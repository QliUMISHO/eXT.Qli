package main

import (
	"encoding/json"
	"log"
	"net/http"
	"sync"

	"github.com/gorilla/websocket"
)

type Client struct {
	conn      *websocket.Conn
	mu        sync.Mutex
	peerType  string
	agentUUID string
	viewerID  string
}

type WSMessage struct {
	Type            string          `json:"type"`
	PeerType        string          `json:"peer_type,omitempty"`
	AgentUUID       string          `json:"agent_uuid,omitempty"`
	ViewerID        string          `json:"viewer_id,omitempty"`
	TargetAgentUUID string          `json:"target_agent_uuid,omitempty"`
	TargetViewerID  string          `json:"target_viewer_id,omitempty"`
	Task            string          `json:"task,omitempty"`
	TaskID          string          `json:"task_id,omitempty"`
	ResultStatus    string          `json:"result_status,omitempty"`
	OutputText      string          `json:"output_text,omitempty"`
	Status          string          `json:"status,omitempty"`
	Message         string          `json:"message,omitempty"`
	Action          string          `json:"action,omitempty"`
	Image           string          `json:"image,omitempty"`
	MimeType        string          `json:"mime_type,omitempty"`
	Backend         string          `json:"backend,omitempty"`
	Width           int             `json:"width,omitempty"`
	Height          int             `json:"height,omitempty"`
	Seq             int64           `json:"seq,omitempty"`
	Data            json.RawMessage `json:"data,omitempty"`
}

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool {
		return true
	},
}

var (
	clientsMu           sync.RWMutex
	agentClients        = map[string]*Client{}
	viewerClients       = map[string]*Client{}
	viewerSubscriptions = map[string]string{}
	agentViewers        = map[string]map[string]*Client{}
)

func (c *Client) writeJSON(v interface{}) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.conn.WriteJSON(v)
}

func storeClient(c *Client) {
	clientsMu.Lock()
	defer clientsMu.Unlock()

	switch c.peerType {
	case "screen_admin", "viewer":
		viewerClients[c.viewerID] = c
	default:
		agentClients[c.agentUUID] = c
	}
}

func getAgent(agentUUID string) *Client {
	clientsMu.RLock()
	defer clientsMu.RUnlock()
	return agentClients[agentUUID]
}

func getViewer(viewerID string) *Client {
	clientsMu.RLock()
	defer clientsMu.RUnlock()
	return viewerClients[viewerID]
}

func getViewerCountForAgent(agentUUID string) int {
	clientsMu.RLock()
	defer clientsMu.RUnlock()
	return len(agentViewers[agentUUID])
}

func notifyViewer(viewerID string, msg WSMessage) {
	viewer := getViewer(viewerID)
	if viewer == nil {
		return
	}
	_ = viewer.writeJSON(msg)
}

func notifyAllViewers(msg WSMessage) {
	clientsMu.RLock()
	viewers := make([]*Client, 0, len(viewerClients))
	for _, c := range viewerClients {
		viewers = append(viewers, c)
	}
	clientsMu.RUnlock()

	for _, c := range viewers {
		_ = c.writeJSON(msg)
	}
}

func sendScreenControl(agentUUID string, action string, message string) {
	agent := getAgent(agentUUID)
	if agent == nil {
		return
	}
	_ = agent.writeJSON(WSMessage{
		Type:      "screen_control",
		Action:    action,
		AgentUUID: agentUUID,
		Message:   message,
	})
}

func subscribeViewerToAgent(viewer *Client, agentUUID string) {
	clientsMu.Lock()
	defer clientsMu.Unlock()

	if oldAgentUUID, ok := viewerSubscriptions[viewer.viewerID]; ok {
		if viewers, found := agentViewers[oldAgentUUID]; found {
			delete(viewers, viewer.viewerID)
			if len(viewers) == 0 {
				delete(agentViewers, oldAgentUUID)
				go sendScreenControl(oldAgentUUID, "stop", "No active viewers remain.")
			}
		}
	}

	viewerSubscriptions[viewer.viewerID] = agentUUID
	if _, ok := agentViewers[agentUUID]; !ok {
		agentViewers[agentUUID] = map[string]*Client{}
	}
	agentViewers[agentUUID][viewer.viewerID] = viewer
}

func unsubscribeViewer(viewer *Client) {
	clientsMu.Lock()
	defer clientsMu.Unlock()

	agentUUID, ok := viewerSubscriptions[viewer.viewerID]
	if !ok {
		return
	}
	delete(viewerSubscriptions, viewer.viewerID)

	if viewers, found := agentViewers[agentUUID]; found {
		delete(viewers, viewer.viewerID)
		if len(viewers) == 0 {
			delete(agentViewers, agentUUID)
			go sendScreenControl(agentUUID, "stop", "No active viewers remain.")
		}
	}
}

func viewersForAgent(agentUUID string) []*Client {
	clientsMu.RLock()
	defer clientsMu.RUnlock()

	viewerMap := agentViewers[agentUUID]
	list := make([]*Client, 0, len(viewerMap))
	for _, v := range viewerMap {
		list = append(list, v)
	}
	return list
}

func removeClient(c *Client) {
	switch c.peerType {
	case "screen_admin", "viewer":
		unsubscribeViewer(c)
		clientsMu.Lock()
		if current, ok := viewerClients[c.viewerID]; ok && current == c {
			delete(viewerClients, c.viewerID)
		}
		clientsMu.Unlock()
	default:
		clientsMu.Lock()
		if current, ok := agentClients[c.agentUUID]; ok && current == c {
			delete(agentClients, c.agentUUID)
		}
		clientsMu.Unlock()
		notifyAllViewers(WSMessage{
			Type:      "agent_status",
			AgentUUID: c.agentUUID,
			Status:    "offline",
			Message:   "Agent disconnected.",
		})
	}
}

func relayScreenFrame(msg WSMessage, sender *Client) {
	if sender.peerType != "agent" || sender.agentUUID == "" {
		return
	}

	viewers := viewersForAgent(sender.agentUUID)
	if len(viewers) == 0 {
		return
	}

	out := WSMessage{
		Type:      "screen_frame",
		AgentUUID: sender.agentUUID,
		Image:     msg.Image,
		MimeType:  msg.MimeType,
		Backend:   msg.Backend,
		Width:     msg.Width,
		Height:    msg.Height,
		Seq:       msg.Seq,
	}

	for _, viewer := range viewers {
		_ = viewer.writeJSON(out)
	}
}

func relayScreenStatus(msg WSMessage, sender *Client) {
	if sender.peerType != "agent" || sender.agentUUID == "" {
		return
	}

	viewers := viewersForAgent(sender.agentUUID)
	if len(viewers) == 0 {
		return
	}

	out := WSMessage{
		Type:      "screen_status",
		AgentUUID: sender.agentUUID,
		Status:    msg.Status,
		Message:   msg.Message,
	}

	for _, viewer := range viewers {
		_ = viewer.writeJSON(out)
	}
}

func handleRegister(client *Client, msg WSMessage) {
	client.peerType = msg.PeerType
	if client.peerType == "" {
		client.peerType = "agent"
	}
	client.agentUUID = msg.AgentUUID
	client.viewerID = msg.ViewerID

	storeClient(client)

	log.Printf("registered peer_type=%s agent_uuid=%s viewer_id=%s", client.peerType, client.agentUUID, client.viewerID)
	_ = client.writeJSON(WSMessage{
		Type:      "registered",
		PeerType:  client.peerType,
		AgentUUID: client.agentUUID,
		ViewerID:  client.viewerID,
	})

	if client.peerType == "agent" {
		notifyAllViewers(WSMessage{
			Type:      "agent_status",
			AgentUUID: client.agentUUID,
			Status:    "online",
			Message:   "Agent connected.",
		})
		if getViewerCountForAgent(client.agentUUID) > 0 {
			sendScreenControl(client.agentUUID, "start", "Viewer is already waiting. Start Python screen stream.")
		}
	}
}

func handleViewerSubscribe(client *Client, msg WSMessage) {
	if client.viewerID == "" {
		_ = client.writeJSON(WSMessage{Type: "screen_status", Status: "error", Message: "viewer_id is required."})
		return
	}
	if msg.TargetAgentUUID == "" {
		_ = client.writeJSON(WSMessage{Type: "screen_status", Status: "error", Message: "target_agent_uuid is required."})
		return
	}

	subscribeViewerToAgent(client, msg.TargetAgentUUID)
	_ = client.writeJSON(WSMessage{
		Type:      "screen_subscribed",
		AgentUUID: msg.TargetAgentUUID,
		Status:    "subscribed",
		Message:   "Viewer subscribed. Requesting Python agent stream.",
	})

	if getAgent(msg.TargetAgentUUID) == nil {
		_ = client.writeJSON(WSMessage{
			Type:      "screen_status",
			AgentUUID: msg.TargetAgentUUID,
			Status:    "waiting",
			Message:   "Agent is not connected yet. Waiting for reconnect.",
		})
		return
	}

	sendScreenControl(msg.TargetAgentUUID, "start", "Viewer requested live screen.")
}

func handleViewerUnsubscribe(client *Client) {
	unsubscribeViewer(client)
	_ = client.writeJSON(WSMessage{
		Type:    "screen_status",
		Status:  "stopped",
		Message: "Viewer stopped.",
	})
}

func handleWS(w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Println("ws upgrade error:", err)
		return
	}

	conn.SetReadLimit(32 << 20)
	client := &Client{conn: conn}
	defer func() {
		removeClient(client)
		_ = conn.Close()
	}()

	for {
		var msg WSMessage
		if err := conn.ReadJSON(&msg); err != nil {
			log.Println("ws read error:", err)
			return
		}

		switch msg.Type {
		case "register":
			handleRegister(client, msg)
		case "screen_subscribe":
			handleViewerSubscribe(client, msg)
		case "screen_unsubscribe":
			handleViewerUnsubscribe(client)
		case "screen_frame":
			relayScreenFrame(msg, client)
		case "screen_status":
			relayScreenStatus(msg, client)
		case "task_result":
			notifyAllViewers(msg)
		}
	}
}

type SendTaskRequest struct {
	AgentUUID string `json:"agent_uuid"`
	Task      string `json:"task"`
	TaskID    string `json:"task_id"`
}

func handleSendTask(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": false,
			"message": "method not allowed",
		})
		return
	}

	var req SendTaskRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		w.WriteHeader(http.StatusBadRequest)
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": false,
			"message": "invalid JSON",
		})
		return
	}

	client := getAgent(req.AgentUUID)
	if client == nil {
		w.WriteHeader(http.StatusNotFound)
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": false,
			"message": "agent not connected",
		})
		return
	}

	payload := WSMessage{
		Type:   "task",
		Task:   req.Task,
		TaskID: req.TaskID,
	}

	if err := client.writeJSON(payload); err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": false,
			"message": err.Error(),
		})
		return
	}

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"success": true,
		"message": "task sent",
	})
}

func main() {
	http.HandleFunc("/ws", handleWS)
	http.HandleFunc("/send-task", handleSendTask)

	log.Println("WebSocket broker running on :8081")
	log.Fatal(http.ListenAndServe(":8081", nil))
}
