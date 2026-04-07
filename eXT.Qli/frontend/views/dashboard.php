<style>
    .agent-admin-header {
        margin-bottom: 14px;
    }

    .agent-admin-header h2 {
        margin: 0 0 6px;
    }

    .agent-admin-header p {
        margin: 0;
    }

    .agent-admin-workspace {
        width: 100%;
        display: flex;
        flex-direction: column;
    }

    .agent-admin-tabs-shell {
        width: 100%;
        margin-top: 10px;
    }

    .agent-admin-tab-strip {
        width: 100%;
        display: flex;
        align-items: flex-end;
        gap: 8px;
        padding: 0 12px;
        border-bottom: 1px solid rgba(255, 255, 255, .10);
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
    }

    .agent-admin-tab-strip::-webkit-scrollbar {
        height: 6px;
    }

    .agent-admin-tab-strip::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .14);
        border-radius: 999px;
    }

    .agent-admin-tab {
        flex: 1 1 0;
        min-width: 200px;
        appearance: none;
        border: 1px solid rgba(255, 255, 255, .12);
        border-bottom: none;
        border-radius: 16px 16px 0 0;
        background: linear-gradient(180deg, rgba(20, 32, 60, .96), rgba(11, 22, 44, .98));
        color: #c7d8ff;
        padding: 15px 18px 14px;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.2;
        text-align: center;
        cursor: pointer;
        white-space: nowrap;
        transition: background .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
    }

    .agent-admin-tab:hover {
        color: #ffffff;
        border-color: rgba(255, 255, 255, .18);
        background: linear-gradient(180deg, rgba(29, 46, 84, .98), rgba(15, 27, 54, 1));
    }

    .agent-admin-tab.is-active {
        color: #ffffff;
        border-color: rgba(182, 210, 255, .74);
        background: linear-gradient(180deg, rgba(92, 144, 255, .98), rgba(62, 112, 234, 1));
        box-shadow: 0 10px 28px rgba(49, 103, 223, .28), inset 0 1px 0 rgba(255, 255, 255, .18);
        transform: translateY(1px);
        position: relative;
        z-index: 2;
    }

    .agent-admin-surface {
        width: 100%;
        border: 1px solid rgba(255, 255, 255, .10);
        border-top: none;
        border-radius: 0 0 22px 22px;
        background: linear-gradient(180deg, rgba(8, 18, 40, .96), rgba(4, 12, 28, 1));
        overflow: hidden;
    }

    .agent-admin-tab-panel {
        padding: 18px;
    }

    .agent-admin-tab-panel[hidden] {
        display: none !important;
    }

    .screen-view-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: 18px;
        width: 100%;
    }

    .screen-stage {
        position: relative;
        width: 100%;
        border-radius: 18px;
        border: 1px dashed rgba(140, 176, 255, .22);
        background:
            radial-gradient(circle at top left, rgba(49, 86, 164, .14), transparent 34%),
            linear-gradient(180deg, rgba(6, 14, 30, .98), rgba(3, 10, 22, 1));
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: min-height .2s ease, height .2s ease, border-color .2s ease;
    }

    .screen-stage.is-empty {
        min-height: 120px;
        height: 120px;
    }

    .screen-stage.has-frame {
        min-height: 520px;
        height: min(72vh, 760px);
        border-style: solid;
        border-color: rgba(146, 179, 255, .28);
    }

    /* Fullscreen mode */
    .screen-stage:fullscreen {
        width: 100vw;
        height: 100vh;
        border-radius: 0;
        background: black;
    }
    .screen-stage:-webkit-full-screen {
        width: 100vw;
        height: 100vh;
        border-radius: 0;
        background: black;
    }
    .screen-stage:-moz-full-screen {
        width: 100vw;
        height: 100vh;
        border-radius: 0;
        background: black;
    }

    #remoteScreenVideo {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: none;
        background: #020814;
    }

    .screen-empty-state {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 20px 24px;
        color: rgba(225, 234, 255, .78);
        font-size: 14px;
        letter-spacing: .2px;
    }

    /* Remote Control Layout */
    .remote-control-layout {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 22px;
    }
    .remote-control-layout .panel {
        min-height: auto;
    }
    .field-input {
        width: 100%;
        padding: 12px 16px;
        border-radius: 14px;
        border: 1px solid rgba(109, 143, 220, 0.24);
        background: rgba(10, 20, 43, 0.92);
        color: #ffffff;
        font-size: 14px;
        font-family: inherit;
    }
    @media (max-width: 768px) {
        .remote-control-layout {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="app-layout">
    <aside class="sidebar-shell">
        <nav class="sidebar-nav">
            <details class="nav-group" open>
                <summary>Dashboard</summary>

                <div class="nav-group-items">
                    <button type="button" class="nav-link is-active" data-view-target="scannerView">
                        <span class="nav-icon">▣</span>
                        <span>Network Scanner</span>
                    </button>

                    <button type="button" class="nav-link" data-view-target="agentsView" data-agent-admin-tab="agents">
                        <span class="nav-icon">◫</span>
                        <span>Agent Administration</span>
                    </button>
                </div>
            </details>
        </nav>
    </aside>

    <div class="main-shell">
        <header class="topbar">
            <div class="brand-wrap">
                <div class="brand-title">eXT.Qli</div>
                <div class="brand-subtitle">Network Computer Scanner and Inventory</div>
            </div>
        </header>

        <main class="view-stack">
            <section id="scannerView" class="view-panel is-active">
                <div class="view-header">
                    <h2>Network Scanner</h2>
                    <p>Scan a subnet, review results, and store discovered devices.</p>
                </div>

                <div class="content-grid">
                    <section class="panel">
                        <div class="panel-header">Scan Network</div>
                        <div class="panel-body">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="subnetInput">Subnet CIDR or Server IP</label>
                                    <input
                                        type="text"
                                        id="subnetInput"
                                        value="10.201.31.238/24"
                                        placeholder="e.g. 192.168.1.0/24 or 192.168.1.10"
                                    >
                                </div>

                                <div class="field">
                                    <label>Action</label>
                                    <div class="button-row">
                                        <button type="button" id="startScanBtn" class="btn btn-primary">Start Scan</button>
                                        <button type="button" id="detectScanBtn" class="btn btn-accent">Detect &amp; Scan</button>
                                        <button type="button" id="refreshListBtn" class="btn btn-dark">Refresh List</button>
                                    </div>
                                </div>
                            </div>

                            <div class="field full-width">
                                <label for="searchInput">Search Results</label>
                                <input
                                    type="text"
                                    id="searchInput"
                                    placeholder="Search IP, hostname, MAC, vendor, ports"
                                >
                            </div>

                            <div id="statusBar" class="status-bar">Ready</div>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel-header">Scan Results</div>
                        <div class="panel-body">
                            <div id="scanSummary" class="scan-summary">No scan yet.</div>

                            <div id="scanResults" class="results-box">
                                <div class="empty-state">No scan yet.</div>
                            </div>

                            <div class="panel-subheader">Raw Nmap Output</div>
                            <pre id="rawOutput" class="raw-output">No scan yet.</pre>
                        </div>
                    </section>
                </div>
            </section>

            <section id="agentsView" class="view-panel">
                <div class="agent-admin-header">
                    <h2>Agent Administration</h2>
                    <p>Monitor connected agents, review saved devices, and open the live screen viewer from one page.</p>
                </div>

                <div class="agent-admin-workspace">
                    <div class="agent-admin-tabs-shell">
                        <div class="agent-admin-tab-strip">
                            <button type="button" class="agent-admin-tab is-active" data-agent-admin-tab-trigger="agents">
                                Connected Agents
                            </button>
                            <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="devices">
                                Saved Devices
                            </button>
                            <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="screen">
                                Screen Viewer
                            </button>
                            <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="remote">
                                Remote Control
                            </button>
                        </div>

                        <div class="agent-admin-surface">
                            <section id="agentAdminTabAgents" class="agent-admin-tab-panel">
                                <section class="panel">
                                    <div class="panel-header">Connected Agents</div>
                                    <div class="panel-body">
                                        <div id="agentStatusBar" class="status-bar">Loading agents...</div>

                                        <div class="table-wrap">
                                            <table class="agents-table">
                                                <thead>
                                                    <tr>
                                                        <th>Status</th>
                                                        <th>Hostname</th>
                                                        <th>IP</th>
                                                        <th>OS</th>
                                                        <th>Arch</th>
                                                        <th>CPU</th>
                                                        <th>RAM</th>
                                                        <th>Disk Free</th>
                                                        <th>Wazuh</th>
                                                        <th>Last Seen</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="agentsTableBody">
                                                     <tr>
                                                        <td colspan="11">No agents yet.</td>
                                                     </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </section>
                            </section>

                            <section id="agentAdminTabDevices" class="agent-admin-tab-panel" hidden>
                                <section class="panel">
                                    <div class="panel-header">Saved Devices</div>
                                    <div class="panel-body">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label for="deviceSearchInput">Search Saved Devices</label>
                                                <input
                                                    type="text"
                                                    id="deviceSearchInput"
                                                    placeholder="Search IP, hostname, MAC, vendor"
                                                >
                                            </div>

                                            <div class="field">
                                                <label>Action</label>
                                                <div class="button-row">
                                                    <button type="button" id="deviceRefreshBtn" class="btn btn-primary">Refresh Saved Devices</button>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="devicesStatusBar" class="status-bar">Loading saved devices...</div>

                                        <div class="table-wrap">
                                            <table class="agents-table">
                                                <thead>
                                                    <tr>
                                                        <th>IP</th>
                                                        <th>Hostname</th>
                                                        <th>MAC</th>
                                                        <th>Vendor</th>
                                                        <th>Status</th>
                                                        <th>Last Seen</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="devicesTableBody">
                                                    <tr>
                                                        <td colspan="7">No saved devices yet.</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </section>
                            </section>

                            <section id="agentAdminTabScreen" class="agent-admin-tab-panel" hidden>
                                <div class="screen-view-layout">
                                    <section class="panel">
                                        <div class="panel-header">Viewer Controls</div>
                                        <div class="panel-body">
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label for="screenAgentSelect">Agent</label>
                                                    <select id="screenAgentSelect" class="field-select"></select>
                                                </div>
                                                <div class="field">
                                                    <label>Action</label>
                                                    <div class="button-row">
                                                        <button type="button" id="startScreenViewBtn" class="btn btn-primary">Start Viewing</button>
                                                        <button type="button" id="stopScreenViewBtn" class="btn btn-dark">Stop Viewing</button>
                                                        <button type="button" id="refreshViewerAgentsBtn" class="btn btn-accent">Refresh Agents</button>
                                                        <button type="button" id="fullscreenBtn" class="btn btn-accent">⛶ Full Screen</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="button-row" style="margin-top: 12px;">
                                                <label style="display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" id="remoteControlToggle"> Enable Remote Control (Mouse/Keyboard)
                                                </label>
                                            </div>
                                            <div id="screenStatusBar" class="status-bar">
                                                Waiting for a selected agent.
                                            </div>
                                        </div>
                                    </section>

                                    <section class="panel">
                                        <div class="panel-header">Remote Screen</div>
                                        <div class="panel-body">
                                            <div id="screenStage" class="screen-stage is-empty" style="position: relative;">
                                                <img id="remoteScreenVideo" alt="Remote screen stream" draggable="false" style="position: absolute; top:0; left:0; width:100%; height:100%; object-fit: contain;">
                                                <canvas id="remoteControlOverlay" style="position: absolute; top:0; left:0; width:100%; height:100%; z-index:10; cursor: crosshair; display: none;"></canvas>
                                                <div id="screenEmptyState" class="screen-empty-state">
                                                    No remote screen stream yet.
                                                </div>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                            </section>

                            <section id="agentAdminTabRemote" class="agent-admin-tab-panel" hidden>
                                <div class="remote-control-layout">
                                    <section class="panel">
                                        <div class="panel-header">Command Execution</div>
                                        <div class="panel-body">
                                            <div class="field">
                                                <label for="commandInput">Shell Command</label>
                                                <textarea id="commandInput" rows="2" class="field-input" placeholder="e.g., dir, whoami, ls -la"></textarea>
                                            </div>
                                            <div class="button-row">
                                                <button type="button" id="executeCmdBtn" class="btn btn-primary">Execute</button>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="panel">
                                        <div class="panel-header">TCP Server (Reverse Tunnel)</div>
                                        <div class="panel-body">
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label for="tcpPort">Port</label>
                                                    <input type="number" id="tcpPort" class="field-input" value="4444">
                                                </div>
                                                <div class="field">
                                                    <label for="tcpMessage">Welcome Message (optional)</label>
                                                    <input type="text" id="tcpMessage" class="field-input" placeholder="Hello from agent">
                                                </div>
                                            </div>
                                            <div class="button-row">
                                                <button type="button" id="startTcpBtn" class="btn btn-primary">Start TCP Server</button>
                                                <button type="button" id="stopTcpBtn" class="btn btn-dark">Stop TCP Server</button>
                                            </div>
                                            <div id="tcpStatus" class="status-bar" style="margin-top: 12px;">Not running</div>
                                        </div>
                                    </section>

                                    <section class="panel">
                                        <div class="panel-header">Quick Actions</div>
                                        <div class="panel-body">
                                            <div class="button-row">
                                                <button type="button" id="quickScreenshotBtn" class="btn btn-accent">Screenshot</button>
                                                <button type="button" id="quickWebcamBtn" class="btn btn-accent">Webcam Capture</button>
                                                <button type="button" id="quickKeyloggerStartBtn" class="btn btn-accent">Start Keylogger</button>
                                                <button type="button" id="quickKeyloggerStopBtn" class="btn btn-accent">Stop Keylogger</button>
                                                <button type="button" id="quickInfoBtn" class="btn btn-accent">Collect Info</button>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="panel">
                                        <div class="panel-header">Task Results</div>
                                        <div class="panel-body">
                                            <div id="taskResultsLog" class="results-box" style="max-height: 300px; overflow-y: auto;">
                                                <div class="empty-state">No tasks executed yet.</div>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>