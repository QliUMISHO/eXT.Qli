#!/usr/bin/env python3
"""
eXT.Qli agent with WebRTC screen streaming – HTTP signaling.
Includes monkey-patch to fix 'None is not in list' error in aiortc.
"""

from __future__ import annotations

import asyncio
import base64
import ctypes
import json
import os
import platform
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, Optional, Tuple
from urllib.request import Request, urlopen

# =========================
# MONKEY-PATCH aiortc to fix None direction bug
# =========================
try:
    import aiortc.rtcpeerconnection
    original_and_direction = aiortc.rtcpeerconnection.and_direction
    original_or_direction = aiortc.rtcpeerconnection.or_direction

    def safe_and_direction(a, b):
        if a is None or b is None:
            return "sendrecv"
        try:
            return original_and_direction(a, b)
        except ValueError:
            return "sendrecv"

    def safe_or_direction(a, b):
        if a is None or b is None:
            return "sendrecv"
        try:
            return original_or_direction(a, b)
        except ValueError:
            return "sendrecv"

    aiortc.rtcpeerconnection.and_direction = safe_and_direction
    aiortc.rtcpeerconnection.or_direction = safe_or_direction
    print("[PATCH] aiortc direction functions monkey-patched")
except Exception as e:
    print(f"[PATCH] Failed to patch aiortc: {e}")

# WebRTC dependencies
try:
    from aiortc import RTCPeerConnection, RTCSessionDescription, VideoStreamTrack
    from av import VideoFrame
    import mss
    WEBRTC_AVAILABLE = True
except ImportError:
    WEBRTC_AVAILABLE = False

try:
    import pyautogui
    PYAUTOGUI_AVAILABLE = True
except ImportError:
    PYAUTOGUI_AVAILABLE = False

# =========================
# Configuration
# =========================
SERVER_HOST = "10.201.0.254"
BASE_HTTP_URL = f"http://{SERVER_HOST}/eXT.Qli"
SIGNALING_URL = f"{BASE_HTTP_URL}/backend/api/signaling.php"
HEARTBEAT_URL = f"{BASE_HTTP_URL}/backend/api/agent_heartbeat.php"
TASK_RESULT_URL = f"{BASE_HTTP_URL}/backend/api/agent_task_result.php"

SHARED_TOKEN = "extqli_@2026token$$"
HEARTBEAT_INTERVAL_SECONDS = 30
SIGNALING_POLL_INTERVAL_SECONDS = 5
HTTP_TIMEOUT_SECONDS = 10

STATE_FILE = Path(__file__).resolve().with_name("agent_state.json")
LOG_PREFIX = "[eXT.Qli Agent]"

# WebRTC globals
webrtc_peer: Optional[RTCPeerConnection] = None
webrtc_data_channel: Optional[Any] = None
webrtc_loop: Optional[asyncio.AbstractEventLoop] = None
signaling_stop_event = threading.Event()
STOP_EVENT = threading.Event()

# =========================
# Helpers
# =========================
def log(message: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    print(f"{LOG_PREFIX} {ts} - {message}", flush=True)

def load_or_create_state() -> Dict[str, str]:
    if STATE_FILE.exists():
        try:
            data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
            if data.get("agent_uuid") and data.get("agent_token"):
                return {"agent_uuid": str(data["agent_uuid"]), "agent_token": str(data["agent_token"])}
        except Exception:
            pass
    state = {"agent_uuid": str(uuid.uuid4()), "agent_token": secrets.token_hex(16)}
    STATE_FILE.write_text(json.dumps(state, indent=2), encoding="utf-8")
    return state

STATE = load_or_create_state()
AGENT_UUID = STATE["agent_uuid"]
AGENT_TOKEN = STATE["agent_token"]

def get_local_ip() -> str:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect((SERVER_HOST, 80))
            return s.getsockname()[0]
    except Exception:
        return "127.0.0.1"

def collect_system_info() -> Dict[str, Any]:
    return {
        "agent_uuid": AGENT_UUID,
        "agent_token": AGENT_TOKEN,
        "hostname": socket.gethostname(),
        "local_ip": get_local_ip(),
        "os_name": platform.system(),
        "shared_token": SHARED_TOKEN,
    }

def http_post_json(url: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")
    req = Request(url, data=body, headers={"Content-Type": "application/json"}, method="POST")
    with urlopen(req, timeout=HTTP_TIMEOUT_SECONDS) as resp:
        return json.loads(resp.read().decode("utf-8"))

def send_heartbeat():
    try:
        http_post_json(HEARTBEAT_URL, collect_system_info())
        log("Heartbeat sent")
    except Exception as e:
        log(f"Heartbeat failed: {e}")

def heartbeat_loop():
    while not STOP_EVENT.is_set():
        send_heartbeat()
        STOP_EVENT.wait(HEARTBEAT_INTERVAL_SECONDS)

# =========================
# WebRTC Screen Capture
# =========================
class ScreenCaptureTrack(VideoStreamTrack):
    kind = "video"
    def __init__(self):
        super().__init__()
        self.sct = mss.mss()
        self.monitor = self.sct.monitors[1]
        self.frame_rate = 30
        self.last_frame_time = 0
        log(f"Screen capture started: {self.monitor['width']}x{self.monitor['height']}")

    async def recv(self):
        pts, time_base = await self.next_timestamp()
        now = time.time()
        if now - self.last_frame_time < 1.0 / self.frame_rate:
            await asyncio.sleep(0)
        img = self.sct.grab(self.monitor)
        frame = VideoFrame.from_ndarray(img.rgb, format="rgb24")
        frame.pts = pts
        frame.time_base = time_base
        self.last_frame_time = now
        return frame

# =========================
# SDP Direction Fix (Robust)
# =========================
def fix_sdp_directions(sdp: str) -> str:
    """
    Remove any existing direction attributes and add a=sendrecv to each media section.
    """
    lines = sdp.splitlines()
    new_lines = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if line.startswith("m="):
            new_lines.append(line)
            new_lines.append("a=sendrecv")
            i += 1
            while i < len(lines) and not lines[i].startswith("m="):
                if not (lines[i].startswith("a=") and 
                        (lines[i][2:].startswith("send") or 
                         lines[i][2:].startswith("recv") or
                         lines[i][2:] == "inactive")):
                    new_lines.append(lines[i])
                i += 1
        else:
            new_lines.append(line)
            i += 1
    return "\n".join(new_lines)

# =========================
# WebRTC Signaling (HTTP polling)
# =========================
def send_signal(action: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    url = f"{SIGNALING_URL}?action={action}"
    return http_post_json(url, payload)

async def establish_webrtc(offer_sdp: str, viewer_id: str):
    global webrtc_peer, webrtc_data_channel
    if not WEBRTC_AVAILABLE:
        log("WebRTC not available")
        return
    if webrtc_peer:
        await webrtc_peer.close()
    webrtc_peer = RTCPeerConnection()

    try:
        video_track = ScreenCaptureTrack()
        webrtc_peer.addTrack(video_track)
        log("Video track added")
    except Exception as e:
        log(f"Failed to add video track: {e}")
        return

    @webrtc_peer.on("datachannel")
    def on_datachannel(channel):
        global webrtc_data_channel
        webrtc_data_channel = channel
        log("Data channel opened")
        @channel.on("message")
        def on_message(msg):
            try:
                data = json.loads(msg)
                if data.get("event_type") == "mouse_move" and PYAUTOGUI_AVAILABLE:
                    pyautogui.moveTo(data["x"], data["y"])
                elif data.get("event_type") == "mouse_click" and PYAUTOGUI_AVAILABLE:
                    btn = pyautogui.LEFT if data["button"] == "left" else pyautogui.RIGHT
                    if data["pressed"]:
                        pyautogui.mouseDown(button=btn)
                    else:
                        pyautogui.mouseUp(button=btn)
                elif data.get("event_type") == "key" and PYAUTOGUI_AVAILABLE:
                    if data["pressed"]:
                        pyautogui.keyDown(data["key"])
                    else:
                        pyautogui.keyUp(data["key"])
            except Exception as e:
                log(f"Data channel error: {e}")

    try:
        offer = RTCSessionDescription(sdp=offer_sdp, type="offer")
        await webrtc_peer.setRemoteDescription(offer)
        log("Remote description set")

        log("Creating answer...")
        answer = await webrtc_peer.createAnswer()
        log("Answer created")

        # Fix SDP directions
        answer.sdp = fix_sdp_directions(answer.sdp)
        log("SDP directions fixed")

        await webrtc_peer.setLocalDescription(answer)
        log("Local description set")

        # Send answer back
        send_signal("submit_answer", {
            "viewer_id": viewer_id,
            "answer_sdp": webrtc_peer.localDescription.sdp
        })
        log(f"Answer sent to viewer {viewer_id}")

    except Exception as e:
        log(f"ERROR in WebRTC negotiation: {e}")
        import traceback
        log(traceback.format_exc())
        return

    @webrtc_peer.on("connectionstatechange")
    async def on_state():
        log(f"Connection state: {webrtc_peer.connectionState}")
        if webrtc_peer.connectionState == "connected":
            log("WebRTC connected – video should appear")
        elif webrtc_peer.connectionState == "failed":
            log("WebRTC failed – check firewall/TURN")

def poll_for_offers():
    global webrtc_loop
    while not signaling_stop_event.is_set():
        try:
            resp = http_post_json(SIGNALING_URL + f"?action=poll_offer&agent_uuid={AGENT_UUID}", {})
            if resp.get("has_offer"):
                offer_sdp = resp.get("offer_sdp")
                viewer_id = resp.get("viewer_id")
                if offer_sdp and viewer_id:
                    log(f"Received offer from {viewer_id}")
                    if webrtc_loop is None:
                        webrtc_loop = asyncio.new_event_loop()
                        asyncio.set_event_loop(webrtc_loop)
                    asyncio.run_coroutine_threadsafe(establish_webrtc(offer_sdp, viewer_id), webrtc_loop)
        except Exception as e:
            log(f"Poll error: {e}")
        signaling_stop_event.wait(SIGNALING_POLL_INTERVAL_SECONDS)

def main():
    log(f"Starting agent (HTTP signaling) – UUID {AGENT_UUID}")
    threading.Thread(target=heartbeat_loop, daemon=True).start()
    threading.Thread(target=poll_for_offers, daemon=True).start()

    global webrtc_loop
    webrtc_loop = asyncio.new_event_loop()
    asyncio.set_event_loop(webrtc_loop)
    try:
        webrtc_loop.run_forever()
    except KeyboardInterrupt:
        log("Shutting down")
    finally:
        STOP_EVENT.set()
        signaling_stop_event.set()

if __name__ == "__main__":
    sys.exit(main())