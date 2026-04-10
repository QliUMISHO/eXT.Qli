#!/usr/bin/env python3
"""
eXT.Qli Environment Installer (No Admin Required)
- Installs Python 3 from a bundled archive into user's AppData
- Installs required pip packages locally
- Does NOT require administrator privileges
"""

import os
import sys
import subprocess
import platform
import tempfile
import shutil
import zipfile
from pathlib import Path

# ========== CONFIGURATION ==========
BUNDLED_PYTHON_ZIP = "python-3.11.9-embed-amd64.zip"
REQUIRED_PIP_PACKAGES = ["pyautogui", "pynput", "opencv-python"]
# ===================================

def resource_path(relative_path):
    """Get absolute path to resource, works for dev and for PyInstaller."""
    try:
        base_path = sys._MEIPASS
    except Exception:
        base_path = os.path.abspath(".")
    return os.path.join(base_path, relative_path)

def run_cmd(cmd, capture=False, shell=True):
    print(f"    Running: {cmd}")
    try:
        if capture:
            return subprocess.run(cmd, shell=shell, capture_output=True, text=True)
        else:
            subprocess.run(cmd, shell=shell, check=True)
            return None
    except subprocess.CalledProcessError as e:
        print(f"    [!] Command failed with exit code {e.returncode}")
        if e.stderr:
            print(f"    Error: {e.stderr}")
        raise

def get_local_python_dir():
    """Return a user-writable directory for Python installation."""
    local_appdata = os.environ.get('LOCALAPPDATA', os.path.expanduser('~\\AppData\\Local'))
    python_dir = os.path.join(local_appdata, 'eXT.Qli', 'python')
    os.makedirs(python_dir, exist_ok=True)
    return python_dir

def install_python_from_bundle():
    print("\n[+] Installing Python 3...")
    zip_path = resource_path(BUNDLED_PYTHON_ZIP)
    if not os.path.exists(zip_path):
        print(f"[!] Bundled Python archive not found: {zip_path}")
        print("[!] Please ensure the file is included with the executable.")
        sys.exit(1)

    target_dir = get_local_python_dir()
    print(f"    Extracting to {target_dir}...")
    with zipfile.ZipFile(zip_path, 'r') as zip_ref:
        zip_ref.extractall(target_dir)

    python_exe = os.path.join(target_dir, 'python.exe')
    if not os.path.exists(python_exe):
        print("[!] python.exe not found after extraction.")
        sys.exit(1)

    print("    ...done.")
    return python_exe

def ensure_pip(python_exe):
    """Install pip into the local Python environment."""
    print("    Ensuring pip is available...")
    try:
        run_cmd(f'"{python_exe}" -m pip --version', shell=True)
        print("    pip already present.")
        return
    except:
        print("    pip not found, installing...")
        get_pip_url = "https://bootstrap.pypa.io/get-pip.py"
        get_pip_path = os.path.join(tempfile.gettempdir(), "get-pip.py")
        import urllib.request
        urllib.request.urlretrieve(get_pip_url, get_pip_path)
        # Install pip locally without touching system
        run_cmd(f'"{python_exe}" {get_pip_path} --user', shell=True)
        os.remove(get_pip_path)
        print("    pip installed.")

def install_pip_packages(python_exe):
    print("\n[+] Installing required Python packages...")
    for pkg in REQUIRED_PIP_PACKAGES:
        print(f"    Installing {pkg}...")
        run_cmd(f'"{python_exe}" -m pip install --user {pkg}', shell=True)
        print(f"    ...done.")
    print("\n[+] All packages installed successfully.")

def main():
    print("=== eXT.Qli Environment Installer ===\n")
    print("This tool installs required packages to setup the necessary environment for the eXT.Qli agent file to work.\n")

    # 1. Install Python (always from bundle to ensure consistency)
    python_exe = install_python_from_bundle()

    # 2. Ensure pip works
    ensure_pip(python_exe)

    # 3. Install required packages
    install_pip_packages(python_exe)

    print("\n[+] Setup complete. The environment is ready for the eXT.Qli agent.")
    print(f"[+] Python location: {python_exe}")
    print("    Press any key to exit...")
    input()
    sys.exit(0)

if __name__ == "__main__":
    main()