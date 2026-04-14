package main

import (
	"bytes"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"sync"
	"time"
)

// ---------- Data structures ----------
type Agent struct {
	UUID     string
	HTTPAddr string // e.g. "http://10.201.31.238:8080"
	LastSeen time.Time
}

type Viewer struct {
	ID       string
	LastSeen time.Time
	// No HTTPAddr needed unless you want direct ICE forwarding to viewer
}

// ---------- Request/response types for HTTP API ----------
type RegisterRequest struct {
	Type     string `json:"type"`      // "agent" or "viewer"
	UUID     string `json:"uuid"`
	HTTPAddr string `json:"http_addr"` // only needed for agents
}

type OfferRequest struct {
	AgentUUID string `json:"agent_uuid"`
	ViewerID  string `json:"viewer_id"`
	SDP       string `json:"sdp"`
}

type AnswerResponse struct {
	SDP string `json:"sdp"`
}

type ICERequest struct {
	AgentUUID   string `json:"agent_uuid,omitempty"`
	ViewerID    string `json:"viewer_id,omitempty"`
	Candidate   string `json:"candidate"`
	SdpMid      string `json:"sdp_mid"`
	SdpMLineIdx int    `json:"sdp_mline_index"`
}

// ---------- In-memory storage ----------
var (
	agentsMu sync.RWMutex
	agents   = make(map[string]*Agent)

	viewersMu sync.RWMutex
	viewers   = make(map[string]*Viewer)
)

// ---------- Helpers ----------
func getAgent(uuid string) *Agent {
	agentsMu.RLock()
	defer agentsMu.RUnlock()
	return agents[uuid]
}

// ---------- HTTP Handlers ----------

// POST /register – agents call this with their HTTP endpoint
func handleRegister(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req RegisterRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}

	if req.UUID == "" {
		http.Error(w, "uuid required", http.StatusBadRequest)
		return
	}

	if req.Type == "agent" {
		if req.HTTPAddr == "" {
			http.Error(w, "http_addr required for agent", http.StatusBadRequest)
			return
		}
		agentsMu.Lock()
		agents[req.UUID] = &Agent{UUID: req.UUID, HTTPAddr: req.HTTPAddr, LastSeen: time.Now()}
		agentsMu.Unlock()
		log.Printf("Agent registered: %s -> %s", req.UUID, req.HTTPAddr)
	} else if req.Type == "viewer" {
		viewersMu.Lock()
		viewers[req.UUID] = &Viewer{ID: req.UUID, LastSeen: time.Now()}
		viewersMu.Unlock()
		log.Printf("Viewer registered: %s", req.UUID)
	} else {
		http.Error(w, "type must be 'agent' or 'viewer'", http.StatusBadRequest)
		return
	}

	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(map[string]string{"status": "registered"})
}

// POST /offer – viewer sends offer, broker forwards to agent and returns answer
func handleOffer(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req OfferRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}

	if req.AgentUUID == "" || req.ViewerID == "" || req.SDP == "" {
		http.Error(w, "missing agent_uuid, viewer_id, or sdp", http.StatusBadRequest)
		return
	}

	agent := getAgent(req.AgentUUID)
	if agent == nil {
		log.Printf("Agent %s not found", req.AgentUUID)
		http.Error(w, "agent not found", http.StatusNotFound)
		return
	}

	// Build payload for agent
	offerPayload := map[string]interface{}{
		"type":      "offer",
		"sdp":       req.SDP,
		"viewer_id": req.ViewerID,
	}
	jsonData, err := json.Marshal(offerPayload)
	if err != nil {
		http.Error(w, "internal error", http.StatusInternalServerError)
		return
	}

	// Forward offer to agent's /offer endpoint
	agentURL := agent.HTTPAddr + "/offer"
	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Post(agentURL, "application/json", bytes.NewReader(jsonData))
	if err != nil {
		log.Printf("Failed to reach agent %s: %v", req.AgentUUID, err)
		http.Error(w, "agent unreachable", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		log.Printf("Agent %s returned HTTP %d: %s", req.AgentUUID, resp.StatusCode, body)
		http.Error(w, "agent rejected offer", http.StatusBadGateway)
		return
	}

	// Read answer from agent
	var answer AnswerResponse
	if err := json.NewDecoder(resp.Body).Decode(&answer); err != nil {
		log.Printf("Failed to decode agent answer: %v", err)
		http.Error(w, "invalid answer from agent", http.StatusBadGateway)
		return
	}

	if answer.SDP == "" {
		log.Printf("Agent %s returned empty SDP answer", req.AgentUUID)
		http.Error(w, "empty answer from agent", http.StatusBadGateway)
		return
	}

	// Return answer to viewer
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(AnswerResponse{SDP: answer.SDP})
	log.Printf("Relayed offer/answer: viewer=%s <-> agent=%s", req.ViewerID, req.AgentUUID)
}

// POST /ice – forward ICE candidate to agent or viewer
func handleICE(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req ICERequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON", http.StatusBadRequest)
		return
	}

	var targetURL string
	var targetType string

	if req.AgentUUID != "" {
		agent := getAgent(req.AgentUUID)
		if agent == nil {
			http.Error(w, "agent not found", http.StatusNotFound)
			return
		}
		targetURL = agent.HTTPAddr + "/ice"
		targetType = "agent"
	} else if req.ViewerID != "" {
		// If you need to forward to viewer, you must store viewer's HTTP endpoint.
		// For simplicity, we skip viewer ICE forwarding unless implemented.
		log.Printf("ICE to viewer %s not supported (no HTTP endpoint)", req.ViewerID)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"status": "ignored (viewer ICE not implemented)"})
		return
	} else {
		http.Error(w, "missing target (agent_uuid or viewer_id)", http.StatusBadRequest)
		return
	}

	// Forward candidate
	jsonData, _ := json.Marshal(req)
	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Post(targetURL, "application/json", bytes.NewReader(jsonData))
	if err != nil {
		log.Printf("Failed to forward ICE to %s %s: %v", targetType, req.AgentUUID, err)
		// Do not return error to viewer – ICE is best-effort
	} else {
		resp.Body.Close()
	}

	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(map[string]string{"status": "ice forwarded"})
}

// GET /health – simple health check
func handleHealth(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("OK"))
}

// ---------- Main ----------
func main() {
	http.HandleFunc("/register", handleRegister)
	http.HandleFunc("/offer", handleOffer)
	http.HandleFunc("/ice", handleICE)
	http.HandleFunc("/health", handleHealth)

	log.Println("HTTP WebRTC signaling broker running on :8081")
	log.Fatal(http.ListenAndServe(":8081", nil))
}