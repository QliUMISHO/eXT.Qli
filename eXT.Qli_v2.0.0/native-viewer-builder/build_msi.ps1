$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$BuildDir = Join-Path $Root "build"
$DistDir = Join-Path $Root "dist"
$MsiDir = Join-Path $Root "dist-msi"
$InstallerDir = Join-Path $Root "installer"

$ViewerPy = Join-Path $Root "extqli_native_viewer.py"
$LauncherPy = Join-Path $Root "extqli_viewer_protocol_launcher.py"
$Requirements = Join-Path $Root "requirements.txt"
$ProductWxs = Join-Path $InstallerDir "Product.wxs"

$VenvDir = Join-Path $Root ".venv"
$PythonExe = Join-Path $VenvDir "Scripts\python.exe"
$PipExe = Join-Path $VenvDir "Scripts\pip.exe"
$PyInstallerExe = Join-Path $VenvDir "Scripts\pyinstaller.exe"

Write-Host ""
Write-Host "=== eXT.Qli Native Viewer MSI Build ===" -ForegroundColor Cyan
Write-Host "Windowed/no-console build enabled." -ForegroundColor Cyan
Write-Host ""

if (!(Test-Path $ViewerPy)) {
    throw "Missing file: $ViewerPy"
}

if (!(Test-Path $LauncherPy)) {
    throw "Missing file: $LauncherPy"
}

if (!(Test-Path $Requirements)) {
    throw "Missing file: $Requirements"
}

if (!(Test-Path $ProductWxs)) {
    throw "Missing file: $ProductWxs"
}

Write-Host "[1/7] Cleaning old build folders..." -ForegroundColor Yellow

Remove-Item -Recurse -Force $BuildDir -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force $DistDir -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force $MsiDir -ErrorAction SilentlyContinue

New-Item -ItemType Directory -Force -Path $BuildDir | Out-Null
New-Item -ItemType Directory -Force -Path $DistDir | Out-Null
New-Item -ItemType Directory -Force -Path $MsiDir | Out-Null

Write-Host "[2/7] Creating Python virtual environment..." -ForegroundColor Yellow

if (!(Test-Path $PythonExe)) {
    python -m venv $VenvDir
}

Write-Host "[3/7] Installing Python dependencies..." -ForegroundColor Yellow

& $PythonExe -m pip install --upgrade pip setuptools wheel
& $PipExe install -r $Requirements

Write-Host "[4/7] Building native viewer EXE as windowed/no-console..." -ForegroundColor Yellow

& $PyInstallerExe `
    --clean `
    --noconfirm `
    --onefile `
    --windowed `
    --name "extqli_native_viewer" `
    --distpath $DistDir `
    --workpath (Join-Path $BuildDir "native_viewer") `
    --specpath $BuildDir `
    --collect-all aiortc `
    --collect-all aioice `
    --collect-all av `
    --collect-all cv2 `
    --hidden-import pynput.keyboard._win32 `
    --hidden-import pynput.mouse._win32 `
    $ViewerPy

Write-Host "[5/7] Building protocol launcher EXE as windowed/no-console..." -ForegroundColor Yellow

& $PyInstallerExe `
    --clean `
    --noconfirm `
    --onefile `
    --windowed `
    --name "extqli_viewer_protocol_launcher" `
    --distpath $DistDir `
    --workpath (Join-Path $BuildDir "protocol_launcher") `
    --specpath $BuildDir `
    $LauncherPy

$NativeExe = Join-Path $DistDir "extqli_native_viewer.exe"
$LauncherExe = Join-Path $DistDir "extqli_viewer_protocol_launcher.exe"

if (!(Test-Path $NativeExe)) {
    throw "Native viewer EXE was not created: $NativeExe"
}

if (!(Test-Path $LauncherExe)) {
    throw "Protocol launcher EXE was not created: $LauncherExe"
}

Write-Host "[6/7] Locating WiX Toolset..." -ForegroundColor Yellow

$Candle = $null
$Light = $null

$PossibleWixDirs = @(
    "${env:ProgramFiles(x86)}\WiX Toolset v3.14\bin",
    "${env:ProgramFiles(x86)}\WiX Toolset v3.11\bin",
    "${env:ProgramFiles}\WiX Toolset v3.14\bin",
    "${env:ProgramFiles}\WiX Toolset v3.11\bin"
)

foreach ($Dir in $PossibleWixDirs) {
    $CandidateCandle = Join-Path $Dir "candle.exe"
    $CandidateLight = Join-Path $Dir "light.exe"

    if ((Test-Path $CandidateCandle) -and (Test-Path $CandidateLight)) {
        $Candle = $CandidateCandle
        $Light = $CandidateLight
        break
    }
}

if (-not $Candle) {
    $CandleCmd = Get-Command candle.exe -ErrorAction SilentlyContinue
    if ($CandleCmd) {
        $Candle = $CandleCmd.Source
    }
}

if (-not $Light) {
    $LightCmd = Get-Command light.exe -ErrorAction SilentlyContinue
    if ($LightCmd) {
        $Light = $LightCmd.Source
    }
}

if (-not $Candle -or -not $Light) {
    throw @"
WiX Toolset v3 was not found.

Install WiX Toolset v3.14, then rerun:
  .\build_msi.ps1

Expected tools:
  candle.exe
  light.exe
"@
}

Write-Host "WiX candle: $Candle"
Write-Host "WiX light:  $Light"

Write-Host "[7/7] Building MSI..." -ForegroundColor Yellow

$WixObj = Join-Path $BuildDir "Product.wixobj"
$MsiOut = Join-Path $MsiDir "eXTQliNativeViewerSetup.msi"

& $Candle `
    -dSourceDir="$DistDir" `
    -out $WixObj `
    $ProductWxs

& $Light `
    -ext WixUIExtension `
    -out $MsiOut `
    $WixObj

if (!(Test-Path $MsiOut)) {
    throw "MSI was not created: $MsiOut"
}

Write-Host ""
Write-Host "DONE." -ForegroundColor Green
Write-Host "MSI created:" -ForegroundColor Green
Write-Host $MsiOut -ForegroundColor Cyan
Write-Host ""
Write-Host "Install this MSI, then test extqli-viewer:// again." -ForegroundColor Green
Write-Host ""