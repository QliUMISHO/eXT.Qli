#!/usr/bin/env python3
"""
eXT.Qli Native Viewer Protocol Launcher

Handles:
extqli-viewer://open?base_url=http%3A%2F%2F10.201.0.254%2FeXT.Qli_preprod&agent_uuid=UUID

Launches:
extqli_native_viewer.exe --base-url ... --agent-uuid ...

This launcher is designed for a windowed/no-console MSI build.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
import time
import urllib.parse
from pathlib import Path


APP_NAME = "eXT.Qli Native Viewer"


def get_local_log_path() -> Path:
    base = os.environ.get("LOCALAPPDATA") or str(Path.home())
    log_dir = Path(base) / "eXT.QliNativeViewer" / "logs"
    log_dir.mkdir(parents=True, exist_ok=True)

    return log_dir / "protocol_launcher.log"


def log(message: str) -> None:
    line = f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {message}"

    try:
        with get_local_log_path().open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except Exception:
        pass


def show_error(message: str) -> None:
    log("ERROR: " + message)

    try:
        import ctypes

        ctypes.windll.user32.MessageBoxW(
            None,
            message,
            APP_NAME,
            0x10
        )
    except Exception:
        pass

    sys.exit(1)


def parse_protocol_url(raw_url: str) -> tuple[str, str]:
    raw_url = (raw_url or "").strip().strip('"')

    log(f"Received URL: {raw_url}")

    if not raw_url:
        show_error("No extqli-viewer:// URL was received.")

    parsed = urllib.parse.urlparse(raw_url)

    if parsed.scheme.lower() != "extqli-viewer":
        show_error(f"Invalid protocol scheme: {parsed.scheme}")

    query = urllib.parse.parse_qs(parsed.query)

    base_url = (query.get("base_url") or [""])[0].strip()
    agent_uuid = (query.get("agent_uuid") or [""])[0].strip()

    if not base_url:
        show_error("Missing base_url.")

    if not agent_uuid:
        show_error("Missing agent_uuid.")

    if not base_url.startswith(("http://", "https://")):
        show_error("Invalid base_url. It must start with http:// or https://")

    if not re.match(r"^[a-zA-Z0-9._:-]+$", agent_uuid):
        show_error("Invalid agent_uuid format.")

    return base_url.rstrip("/"), agent_uuid


def get_app_dir() -> Path:
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent

    return Path(__file__).resolve().parent


def main() -> None:
    log("Protocol launcher started.")
    log("argv=" + repr(sys.argv))

    if len(sys.argv) < 2:
        show_error(
            "Missing extqli-viewer:// launch URL.\n\n"
            "This app is normally opened from the browser using the Native Viewer button."
        )

    base_url, agent_uuid = parse_protocol_url(sys.argv[1])

    app_dir = get_app_dir()
    viewer_exe = app_dir / "extqli_native_viewer.exe"
    viewer_py = app_dir / "extqli_native_viewer.py"

    log(f"App dir: {app_dir}")
    log(f"Base URL: {base_url}")
    log(f"Agent UUID: {agent_uuid}")
    log(f"Viewer EXE: {viewer_exe}")

    if viewer_exe.is_file():
        command = [
            str(viewer_exe),
            "--base-url",
            base_url,
            "--agent-uuid",
            agent_uuid,
        ]
    elif viewer_py.is_file():
        command = [
            sys.executable,
            str(viewer_py),
            "--base-url",
            base_url,
            "--agent-uuid",
            agent_uuid,
        ]
    else:
        show_error(
            "Native viewer executable was not found.\n\n"
            f"Expected:\n{viewer_exe}"
        )

    try:
        log("Launching command: " + repr(command))

        startupinfo = None
        creationflags = 0

        if os.name == "nt":
            startupinfo = subprocess.STARTUPINFO()
            startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
            startupinfo.wShowWindow = 0

            creationflags = subprocess.DETACHED_PROCESS | subprocess.CREATE_NO_WINDOW

        subprocess.Popen(
            command,
            cwd=str(app_dir),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            startupinfo=startupinfo,
            creationflags=creationflags,
            close_fds=True,
        )

        log("Native viewer process launched without console.")
    except Exception as exc:
        show_error(f"Failed to launch native viewer:\n\n{exc}")


if __name__ == "__main__":
    main()