<div class="app-layout">
    <div class="main-shell">
        <main class="view-stack">
            <section id="agentsView" class="view-panel is-active">
                <section class="hero-card card">
                    <div class="board-brand">
                        <div>
                            <div class="board-brand-title">eXT.Qli</div>
                            <div class="board-brand-caption">Endpoint eXecution Terminal (EXT)</div>
                        </div>

                        <button type="button" id="openSystemConfigBtn" class="btn btn-dark btn-sm extqli-config-open-btn">
                            System Config
                        </button>
                    </div>

                    <div class="board-rule"></div>

                    <div class="board-header-row">
                        <div class="board-copy">
                            <span class="board-kicker">Remote Endpoint Console</span>
                            <h1 class="board-title">Agent Administration</h1>
                            <p class="board-subtitle">
                                Select an endpoint card, inspect live telemetry, then launch browser viewer or remote viewer mode.
                            </p>
                        </div>

                        <div class="board-stats">
                            <article class="board-stat">
                                <span class="board-stat-label">Online</span>
                                <strong id="onlineClientsCount">0</strong>
                            </article>

                            <article class="board-stat">
                                <span class="board-stat-label">Streams</span>
                                <strong id="liveStreamsCount">0</strong>
                            </article>

                            <article class="board-stat">
                                <span class="board-stat-label">Remote</span>
                                <strong id="remoteTargetName">None</strong>
                            </article>
                        </div>
                    </div>

                    <div class="agent-admin-workspace">
                        <div class="agent-admin-tabs-shell">
                            <div class="agent-admin-tab-strip" role="tablist" aria-label="Agent administration tabs">
                                <button type="button" class="agent-admin-tab is-active" data-agent-admin-tab-trigger="agents" role="tab">
                                    Workspace
                                </button>

                                <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="screen" role="tab">
                                    Screen Viewer
                                </button>
                            </div>

                            <div class="agent-admin-surface">
                                <section id="agentAdminTabAgents" class="agent-admin-tab-panel" role="tabpanel">
                                    <div class="extqli-dashboard-layout">
                                        <div class="extqli-remote-bar extqli-remote-hidden" id="extqliRemoteStatus" role="alert" hidden>
                                            <div class="extqli-remote-bar-text">
                                                <strong>Selected endpoint ready.</strong>
                                                <span>Click <strong>Remote Screen</strong> to open the remote-control workflow through the Screen Viewer panel.</span>
                                            </div>

                                            <button type="button" id="clearRemoteTargetBtn" class="btn btn-dark btn-sm">
                                                Clear Remote Target
                                            </button>
                                        </div>

                                        <div class="extqli-body">
                                            <aside class="extqli-sidebar">
                                                <div class="extqli-sidebar-hd">
                                                    <span class="extqli-sidebar-title">Detected Clients</span>
                                                    <small>Realtime endpoint list from heartbeat and stream state.</small>
                                                </div>

                                                <div id="extqliClientList" class="extqli-client-list">
                                                    <div class="extqli-empty">
                                                        <strong>No endpoints detected yet.</strong>
                                                        <span>Configure network scan settings or wait for endpoint heartbeats.</span>
                                                    </div>
                                                </div>
                                            </aside>

                                            <div class="extqli-main">
                                                <div class="extqli-main-header">
                                                    <div>
                                                        <span class="section-kicker">Monitor View</span>
                                                        <h3>Live Endpoint Cards</h3>
                                                    </div>

                                                    <span class="matrix-label">Preview Layer</span>
                                                </div>

                                                <div id="extqliMonitorGrid" class="extqli-grid">
                                                    <div class="extqli-empty">
                                                        <strong>No endpoint cards yet.</strong>
                                                        <span>The workspace will stay empty until agents are discovered.</span>
                                                    </div>
                                                </div>

                                                <p class="extqli-tip">
                                                    Compact technical cards are used so more connected endpoints remain visible within the workspace.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="agentStatusBar" class="status-bar" style="display:none;"></div>
                                </section>

                                <section id="agentAdminTabScreen" class="agent-admin-tab-panel" role="tabpanel" hidden>
                                    <div class="screen-view-layout">
                                        <section class="control-panel">
                                            <div class="panel-header">
                                                <div>
                                                    <span class="section-kicker">Controls</span>
                                                    <h3>Viewer Controls</h3>
                                                </div>
                                            </div>

                                            <div class="panel-body">
                                                <div class="form-grid">
                                                    <div class="field">
                                                        <label for="screenAgentSelect">Agent</label>
                                                        <select id="screenAgentSelect" class="field-select"></select>
                                                    </div>

                                                    <div class="field">
                                                        <label>Action</label>

                                                        <div class="button-stack">
                                                            <button type="button" id="startScreenViewBtn" class="btn btn-primary">
                                                                Start Viewing
                                                            </button>

                                                            <button type="button" id="stopScreenViewBtn" class="btn btn-dark">
                                                                Stop Viewing
                                                            </button>

                                                            <button type="button" id="refreshViewerAgentsBtn" class="btn btn-dark">
                                                                Refresh Agents
                                                            </button>

                                                            <button type="button" id="fullscreenBtn" class="btn btn-dark">
                                                                Full Screen
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="remote-switch-row">
                                                    <label class="remote-switch">
                                                        <input type="checkbox" id="remoteControlToggle" role="switch">

                                                        <span>
                                                            <strong>Enable Remote Control</strong>
                                                            <small>Mouse and keyboard input will be sent to the active endpoint.</small>
                                                        </span>
                                                    </label>
                                                </div>

                                                <div id="screenStatusBar" class="status-bar">
                                                    Waiting for a selected agent.
                                                </div>
                                            </div>
                                        </section>

                                        <section class="screen-panel">
                                            <div class="panel-header">
                                                <div>
                                                    <span class="section-kicker">Live Session</span>
                                                    <h3>Remote Screen</h3>
                                                </div>
                                            </div>

                                            <div class="panel-body">
                                                <div id="screenStage" class="screen-stage is-empty">
                                                    <video
                                                        id="remoteScreenVideo"
                                                        autoplay
                                                        playsinline
                                                        muted
                                                        draggable="false"
                                                    ></video>

                                                    <canvas
                                                        id="remoteControlOverlay"
                                                        style="position:absolute; top:0; left:0; width:100%; height:100%; z-index:10; cursor:crosshair; display:none;"
                                                    ></canvas>

                                                    <div id="screenEmptyState" class="screen-empty-state">
                                                        <strong>No remote screen stream yet.</strong>
                                                        <span>Select an agent and click Start Viewing.</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </section>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>
                </section>
            </section>
        </main>
    </div>
</div>

<!-- System Configuration Modal -->
<div id="systemConfigModal" class="extqli-config-modal" hidden>
    <div class="extqli-config-backdrop" data-config-close></div>

    <section class="extqli-config-dialog" role="dialog" aria-modal="true" aria-labelledby="systemConfigTitle">
        <header class="extqli-config-header">
            <div>
                <span class="section-kicker">System Setup</span>
                <h2 id="systemConfigTitle">System Configuration</h2>
                <p>Configure discovery, default port, and TURN server settings here.</p>
            </div>

            <button type="button" class="extqli-config-close" data-config-close aria-label="Close configuration modal">
                ×
            </button>
        </header>

        <div class="extqli-config-body">
            <section class="extqli-config-card">
                <h3>Endpoint Discovery</h3>

                <div class="extqli-config-grid">
                    <label class="extqli-config-field">
                        <span>IP address / subnet to scan</span>
                        <input
                            type="text"
                            id="configScanIp"
                            placeholder="Example: 10.201.0.0/24 or 10.201.0.254"
                            autocomplete="off"
                        >
                        <small>Use CIDR for automatic scanning across the network.</small>
                    </label>

                    <label class="extqli-config-field">
                        <span>System port</span>
                        <input
                            type="number"
                            id="configSystemPort"
                            min="1"
                            max="65535"
                            placeholder="3478"
                            autocomplete="off"
                        >
                        <small>Default: <strong id="configDefaultPortText">3478</strong></small>
                    </label>
                </div>
            </section>

            <section class="extqli-config-card">
                <div class="extqli-config-card-head">
                    <div>
                        <h3>TURN Server</h3>
                        <p>Autodiscover TURN IP when possible. Otherwise enter the TURN server credentials here.</p>
                    </div>

                    <button type="button" id="configAutoDiscoverTurnBtn" class="btn btn-dark btn-sm">
                        Autodiscover
                    </button>
                </div>

                <div class="extqli-config-grid">
                    <label class="extqli-config-field">
                        <span>TURN server IP</span>
                        <input
                            type="text"
                            id="configTurnIp"
                            placeholder="Example: 10.201.0.254"
                            autocomplete="off"
                        >
                    </label>

                    <label class="extqli-config-field">
                        <span>TURN port</span>
                        <input
                            type="number"
                            id="configTurnPort"
                            min="1"
                            max="65535"
                            placeholder="3478"
                            autocomplete="off"
                        >
                    </label>

                    <label class="extqli-config-field">
                        <span>TURN username</span>
                        <input
                            type="text"
                            id="configTurnUser"
                            placeholder="TURN username"
                            autocomplete="off"
                        >
                    </label>

                    <label class="extqli-config-field">
                        <span>TURN password</span>
                        <input
                            type="password"
                            id="configTurnPass"
                            placeholder="TURN password"
                            autocomplete="new-password"
                        >
                    </label>
                </div>

                <div class="extqli-config-actions-row">
                    <button type="button" id="configCheckTurnBtn" class="btn btn-dark btn-sm">
                        Check TURN Connection
                    </button>

                    <div id="configTurnStatus" class="extqli-config-status">
                        TURN status not checked yet.
                    </div>
                </div>
            </section>

            <section class="extqli-config-card">
                <h3>Runtime Status</h3>

                <div class="extqli-config-runtime">
                    <div>
                        <span>Detected web base</span>
                        <strong id="configRuntimeBase">—</strong>
                    </div>
                    <div>
                        <span>Detected server IP</span>
                        <strong id="configRuntimeServerIp">—</strong>
                    </div>
                    <div>
                        <span>Current ICE mode</span>
                        <strong id="configRuntimeIce">—</strong>
                    </div>
                </div>
            </section>
        </div>

        <footer class="extqli-config-footer">
            <div id="configSaveStatus" class="extqli-config-save-status">
                Ready.
            </div>

            <button type="button" class="btn btn-dark" data-config-close>
                Cancel
            </button>

            <button type="button" id="saveSystemConfigBtn" class="btn btn-primary">
                Save Configuration
            </button>
        </footer>
    </section>
</div>