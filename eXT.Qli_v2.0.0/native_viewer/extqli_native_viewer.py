#!/usr/bin/env python3
"""
eXT.Qli Native Python Viewer

Purpose:
- Acts as a WebRTC viewer client.
- Uses the existing PHP signaling.php flow:
  submit_offer -> poll_answer
- Receives endpoint screen stream.
- Opens a native OpenCV viewer window.
- Sends mouse/keyboard input through the WebRTC DataChannel.

Run manually:
python3 extqli_native_viewer.py --base-url http://10.201.0.254/eXT.Qli_preprod --agent-uuid AGENT_UUID

Browser launch:
backend/api/native_viewer_launch.php starts this script.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import signal
import sys
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

import cv2
import requests
from aiortc import RTCConfiguration, RTCDataChannel, RTCIceServer, RTCPeerConnection, RTCSessionDescription
from av import VideoFrame

try:
    from pynput import keyboard
except Exception:
    keyboard = None


@dataclass
class ViewerConfig:
    base_url: str
    agent_uuid: str
    viewer_id: str
    width: int = 1280
    height: int = 720
    poll_interval: float = 0.18
    answer_timeout: float = 24.0


class NativeViewer:
    def __init__(self, config: ViewerConfig) -> None:
        self.config = config
        self.signaling_url = self.config.base_url.rstrip("/") + "/backend/api/signaling.php"
        self.system_config_url = self.config.base_url.rstrip("/") + "/backend/api/system_config.php"

        self.pc: Optional[RTCPeerConnection] = None
        self.channel: Optional[RTCDataChannel] = None

        self.remote_width = 1920
        self.remote_height = 1080

        self.latest_frame = None
        self.latest_frame_ts = 0.0

        self.running = True
        self.window_name = f"eXT.Qli Native Viewer - {self.config.agent_uuid}"

        self.key_listener = None

    def log(self, message: str, *args: Any) -> None:
        logging.info(message, *args)

    def fetch_system_config(self) -> Dict[str, Any]:
        try:
            response = requests.get(
                self.system_config_url,
                params={"action": "get", "_": int(time.time() * 1000)},
                timeout=5,
            )
            response.raise_for_status()
            data = response.json()

            if not data.get("success"):
                raise RuntimeError(data.get("message", "system_config.php returned success=false"))

            config = data.get("config") or {}
            if not isinstance(config, dict):
                return {}

            return config
        except Exception as exc:
            self.log("System config fetch failed: %s", exc)
            return {}

    def build_rtc_configuration(self) -> RTCConfiguration:
        config = self.fetch_system_config()
        servers: List[RTCIceServer] = []

        ice_servers = config.get("ice_servers")

        if isinstance(ice_servers, list):
            for item in ice_servers:
                if not isinstance(item, dict):
                    continue

                urls = item.get("urls")
                username = item.get("username")
                credential = item.get("credential")

                if not urls:
                    continue

                if username:
                    servers.append(
                        RTCIceServer(
                            urls=urls,
                            username=str(username),
                            credential=str(credential or ""),
                        )
                    )
                else:
                    servers.append(RTCIceServer(urls=urls))

        stun_url = config.get("stun_url")
        if stun_url and not servers:
            servers.append(RTCIceServer(urls=str(stun_url)))

        turn_ip = str(config.get("turn_ip") or "").strip()
        turn_port = int(config.get("turn_port") or 0)
        turn_username = str(config.get("turn_username") or "").strip()
        turn_password = str(config.get("turn_password") or "")

        if turn_ip and turn_port and turn_username:
            servers.append(
                RTCIceServer(
                    urls=f"turn:{turn_ip}:{turn_port}?transport=tcp",
                    username=turn_username,
                    credential=turn_password,
                )
            )

        if not servers:
            self.log("No ICE servers configured. Native viewer will use host candidates only.")

        return RTCConfiguration(iceServers=servers)

    async def start(self) -> None:
        self.pc = RTCPeerConnection(configuration=self.build_rtc_configuration())

        self.channel = self.pc.createDataChannel("controlChannel")

        @self.channel.on("open")
        def on_open() -> None:
            self.log("DataChannel open.")
            self.send_json({"type": "identity_request"})
            self.send_json({"type": "resume_video"})

        @self.channel.on("close")
        def on_close() -> None:
            self.log("DataChannel closed.")

        @self.channel.on("message")
        def on_message(message: Any) -> None:
            self.log("DataChannel message: %s", message)

        @self.pc.on("track")
        def on_track(track) -> None:
            self.log("Track received: %s", track.kind)

            if track.kind == "video":
                asyncio.ensure_future(self.consume_video(track))

        @self.pc.on("connectionstatechange")
        async def on_connection_state_change() -> None:
            if not self.pc:
                return

            self.log("connectionState=%s", self.pc.connectionState)

            if self.pc.connectionState in ("failed", "closed"):
                self.running = False

        @self.pc.on("iceconnectionstatechange")
        async def on_ice_state_change() -> None:
            if not self.pc:
                return

            self.log("iceConnectionState=%s", self.pc.iceConnectionState)

            if self.pc.iceConnectionState in ("failed", "closed"):
                self.running = False

        self.pc.addTransceiver("video", direction="recvonly")

        offer = await self.pc.createOffer()
        await self.pc.setLocalDescription(offer)

        await self.submit_offer()
        await self.poll_answer()
        self.start_keyboard_listener()

        await self.ui_loop()

    async def submit_offer(self) -> None:
        if not self.pc or not self.pc.localDescription:
            raise RuntimeError("Missing localDescription.")

        payload = {
            "viewer_id": self.config.viewer_id,
            "offer_sdp": self.pc.localDescription.sdp,
        }

        response = requests.post(
            self.signaling_url,
            params={
                "action": "submit_offer",
                "agent_uuid": self.config.agent_uuid,
                "_": int(time.time() * 1000),
            },
            json=payload,
            timeout=8,
        )

        response.raise_for_status()
        data = response.json()

        if not data.get("success"):
            raise RuntimeError(data.get("message", "submit_offer failed"))

        self.log("Offer submitted.")

    async def poll_answer(self) -> None:
        if not self.pc:
            raise RuntimeError("PeerConnection not initialized.")

        started = time.time()

        while self.running and time.time() - started < self.config.answer_timeout:
            response = requests.get(
                self.signaling_url,
                params={
                    "action": "poll_answer",
                    "viewer_id": self.config.viewer_id,
                    "_": int(time.time() * 1000),
                },
                timeout=6,
            )

            response.raise_for_status()
            data = response.json()

            if data.get("has_answer") and data.get("answer_sdp"):
                answer = RTCSessionDescription(sdp=data["answer_sdp"], type="answer")
                await self.pc.setRemoteDescription(answer)
                self.log("Answer received and applied.")
                return

            await asyncio.sleep(self.config.poll_interval)

        raise TimeoutError("Timed out waiting for WebRTC answer.")

    async def consume_video(self, track) -> None:
        while self.running:
            try:
                frame: VideoFrame = await track.recv()
                image = frame.to_ndarray(format="bgr24")

                self.remote_height, self.remote_width = image.shape[:2]
                self.latest_frame = image
                self.latest_frame_ts = time.time()
            except Exception as exc:
                self.log("Video receive stopped: %s", exc)
                self.running = False
                break

    def send_json(self, payload: Dict[str, Any]) -> None:
        try:
            if self.channel and self.channel.readyState == "open":
                self.channel.send(json.dumps(payload))
        except Exception as exc:
            self.log("DataChannel send failed: %s", exc)

    def send_key(self, key_name: str, pressed: bool) -> None:
        key_name = str(key_name or "").strip()

        if not key_name:
            return

        self.send_json(
            {
                "event_type": "key",
                "key": key_name,
                "pressed": bool(pressed),
            }
        )

    def start_keyboard_listener(self) -> None:
        if keyboard is None:
            self.log("pynput not available. Keyboard input disabled.")
            return

        def normalize_key(key_obj) -> str:
            special_map = {
                keyboard.Key.space: "space",
                keyboard.Key.enter: "enter",
                keyboard.Key.esc: "escape",
                keyboard.Key.tab: "tab",
                keyboard.Key.backspace: "backspace",
                keyboard.Key.delete: "delete",
                keyboard.Key.insert: "insert",
                keyboard.Key.home: "home",
                keyboard.Key.end: "end",
                keyboard.Key.page_up: "pageup",
                keyboard.Key.page_down: "pagedown",
                keyboard.Key.up: "up",
                keyboard.Key.down: "down",
                keyboard.Key.left: "left",
                keyboard.Key.right: "right",
                keyboard.Key.shift: "shift",
                keyboard.Key.shift_l: "shift",
                keyboard.Key.shift_r: "shift",
                keyboard.Key.ctrl: "ctrl",
                keyboard.Key.ctrl_l: "ctrl",
                keyboard.Key.ctrl_r: "ctrl",
                keyboard.Key.alt: "alt",
                keyboard.Key.alt_l: "alt",
                keyboard.Key.alt_r: "alt",
                keyboard.Key.cmd: "meta",
                keyboard.Key.cmd_l: "meta",
                keyboard.Key.cmd_r: "meta",
                keyboard.Key.caps_lock: "capslock",
                keyboard.Key.menu: "menu",
                keyboard.Key.print_screen: "printscreen",
                keyboard.Key.pause: "pause",
            }

            if key_obj in special_map:
                return special_map[key_obj]

            for i in range(1, 25):
                if hasattr(keyboard.Key, f"f{i}") and key_obj == getattr(keyboard.Key, f"f{i}"):
                    return f"f{i}"

            try:
                char = getattr(key_obj, "char", None)
                if char:
                    return char
            except Exception:
                pass

            return str(key_obj).replace("Key.", "").lower()

        def on_press(key_obj) -> None:
            self.send_key(normalize_key(key_obj), True)

        def on_release(key_obj) -> None:
            self.send_key(normalize_key(key_obj), False)

        self.key_listener = keyboard.Listener(on_press=on_press, on_release=on_release)
        self.key_listener.daemon = True
        self.key_listener.start()

        self.log("Keyboard listener started.")

    def handle_mouse_event(self, event: int, x: int, y: int, flags: int, param: Any) -> None:
        if self.latest_frame is None:
            return

        frame_h, frame_w = self.latest_frame.shape[:2]

        if frame_w <= 0 or frame_h <= 0:
            return

        scaled_x = int(max(0, min(self.remote_width, (x / frame_w) * self.remote_width)))
        scaled_y = int(max(0, min(self.remote_height, (y / frame_h) * self.remote_height)))

        if event == cv2.EVENT_MOUSEMOVE:
            self.send_json(
                {
                    "event_type": "mouse_move",
                    "x": scaled_x,
                    "y": scaled_y,
                }
            )
            return

        button = None
        pressed = None

        if event == cv2.EVENT_LBUTTONDOWN:
            button = "left"
            pressed = True
        elif event == cv2.EVENT_LBUTTONUP:
            button = "left"
            pressed = False
        elif event == cv2.EVENT_RBUTTONDOWN:
            button = "right"
            pressed = True
        elif event == cv2.EVENT_RBUTTONUP:
            button = "right"
            pressed = False
        elif event == cv2.EVENT_MBUTTONDOWN:
            button = "middle"
            pressed = True
        elif event == cv2.EVENT_MBUTTONUP:
            button = "middle"
            pressed = False

        if button is not None and pressed is not None:
            self.send_json(
                {
                    "event_type": "mouse_click",
                    "button": button,
                    "pressed": pressed,
                }
            )
            return

        if event == cv2.EVENT_MOUSEWHEEL:
            delta = 3 if flags > 0 else -3
            self.send_json(
                {
                    "event_type": "mouse_scroll",
                    "delta": delta,
                }
            )

    async def ui_loop(self) -> None:
        cv2.namedWindow(self.window_name, cv2.WINDOW_NORMAL)
        cv2.resizeWindow(self.window_name, self.config.width, self.config.height)
        cv2.setMouseCallback(self.window_name, self.handle_mouse_event)

        self.log("Native viewer window opened.")

        while self.running:
            if self.latest_frame is not None:
                cv2.imshow(self.window_name, self.latest_frame)
            else:
                placeholder = self.make_placeholder()
                cv2.imshow(self.window_name, placeholder)

            key_code = cv2.waitKey(1) & 0xFF

            if key_code == ord("q"):
                self.running = False
                break

            if key_code == 27:
                self.send_key("escape", True)
                self.send_key("escape", False)

            await asyncio.sleep(0.001)

        await self.close()

    def make_placeholder(self):
        import numpy as np

        image = np.zeros((360, 640, 3), dtype=np.uint8)
        image[:] = (24, 28, 34)

        cv2.putText(
            image,
            "eXT.Qli Native Viewer",
            (28, 145),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.85,
            (245, 245, 245),
            2,
            cv2.LINE_AA,
        )

        cv2.putText(
            image,
            "Waiting for WebRTC screen stream...",
            (28, 190),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (170, 180, 195),
            1,
            cv2.LINE_AA,
        )

        cv2.putText(
            image,
            "Press Q to quit.",
            (28, 230),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.50,
            (130, 140, 155),
            1,
            cv2.LINE_AA,
        )

        return image

    async def close(self) -> None:
        self.running = False

        try:
            if self.key_listener:
                self.key_listener.stop()
        except Exception:
            pass

        try:
            cv2.destroyWindow(self.window_name)
        except Exception:
            pass

        try:
            if self.pc:
                await self.pc.close()
        except Exception:
            pass

        self.log("Native viewer closed.")


def parse_args() -> ViewerConfig:
    parser = argparse.ArgumentParser(description="eXT.Qli Native WebRTC Viewer")
    parser.add_argument("--base-url", required=True, help="Example: http://10.201.0.254/eXT.Qli_preprod")
    parser.add_argument("--agent-uuid", required=True)
    parser.add_argument("--viewer-id", default="")
    parser.add_argument("--width", type=int, default=1280)
    parser.add_argument("--height", type=int, default=720)

    args = parser.parse_args()

    viewer_id = args.viewer_id.strip() or f"native-{int(time.time())}"

    return ViewerConfig(
        base_url=args.base_url.strip().rstrip("/"),
        agent_uuid=args.agent_uuid.strip(),
        viewer_id=viewer_id,
        width=args.width,
        height=args.height,
    )


async def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="[%(asctime)s] [%(levelname)s] %(message)s",
    )

    config = parse_args()
    viewer = NativeViewer(config)

    loop = asyncio.get_running_loop()

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, lambda: setattr(viewer, "running", False))
        except NotImplementedError:
            pass

    try:
        await viewer.start()
    except Exception as exc:
        logging.exception("Native viewer failed: %s", exc)
        await viewer.close()
        sys.exit(1)


if __name__ == "__main__":
    asyncio.run(main())