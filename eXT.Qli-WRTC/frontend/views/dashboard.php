<div class="app-layout">
    <aside class="sidebar-shell">
        <nav class="sidebar-nav">
            <details class="nav-group" open>
                <summary>Dashboard</summary>

                <div class="nav-group-items">
                    <button type="button" class="nav-link is-active" data-view-target="agentsView">
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
                <div class="brand-subtitle">Endpoint EXecution Terminal</div>
            </div>
        </header>

        <main class="view-stack">
            <section id="agentsView" class="view-panel is-active">
                <div class="agent-admin-header">
                    <h2>Agent Administration</h2>
                    <p>Monitor connected agents and open the live screen viewer.</p>
                </div>

                <div class="agent-admin-workspace">
                    <div class="agent-admin-tabs-shell">
                        <div class="agent-admin-tab-strip">
                            <button type="button" class="agent-admin-tab is-active" data-agent-admin-tab-trigger="agents">
                                Connected Agents
                            </button>
                            <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="screen">
                                Screen Viewer
                            </button>
                        </div>

                        <div class="agent-admin-surface">
                            <section id="agentAdminTabAgents" class="agent-admin-tab-panel">
                                <div class="sentinel-layout">
                                    <div class="sentinel-stats-bar">
                                        <div class="sentinel-stats-left">
                                            <h3 class="sentinel-layout-title">Live Agent Grid</h3>
                                            <p class="sentinel-layout-subtitle">
                                                View connected endpoints in a compact live grid. When <strong>Start Viewing</strong> is used in the Screen Viewer, the selected agent card will show the same stream in minimized live view.
                                            </p>
                                        </div>

                                        <div class="sentinel-stats-right">
                                            <div class="sentinel-stat-item">
                                                <span class="sentinel-stat-label">Online Clients</span>
                                                <span class="sentinel-stat-value" id="onlineClientsCount">0</span>
                                            </div>
                                            <div class="sentinel-stat-item">
                                                <span class="sentinel-stat-label">Live Streams</span>
                                                <span class="sentinel-stat-value" id="liveStreamsCount">0</span>
                                            </div>
                                            <div class="sentinel-stat-item">
                                                <span class="sentinel-stat-label">Remote Target</span>
                                                <span class="sentinel-stat-value is-target" id="remoteTargetName">None</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="sentinel-remote-bar" id="extqliRemoteStatus">
                                        <div class="sentinel-remote-bar-text">
                                            <strong>Remote control is idle.</strong>
                                            <span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>
                                        </div>
                                        <button type="button" id="clearRemoteTargetBtn" class="btn btn-dark btn-sm">Clear Remote Target</button>
                                    </div>

                                    <div class="sentinel-body">
                                        <aside class="sentinel-sidebar">
                                            <div class="sentinel-sidebar-hd">
                                                <span>Detected Clients</span>
                                                <span class="sentinel-realtime-pill">Realtime</span>
                                            </div>

                                            <div id="extqliClientList" class="sentinel-client-list">
                                                <div class="sentinel-empty">Loading agents...</div>
                                            </div>
                                        </aside>

                                        <div class="sentinel-main">
                                            <div id="extqliMonitorGrid" class="sentinel-grid">
                                                <div class="sentinel-empty">Loading agents...</div>
                                            </div>

                                            <p class="sentinel-tip">
                                                Tip: cards stay compact so more connected endpoints remain visible within the workspace.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div id="agentStatusBar" style="display:none;"></div>
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
                                                        <button type="button" id="fullscreenBtn" class="btn btn-accent">Full Screen</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="button-row" style="margin-top: 12px;">
                                                <label style="display:flex; align-items:center; gap:8px;">
                                                    <input type="checkbox" id="remoteControlToggle">
                                                    Enable Remote Control (Mouse/Keyboard)
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
                                                    No remote screen stream yet.
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
        </main>
    </div>
</div>