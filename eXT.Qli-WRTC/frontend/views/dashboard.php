<div class="app-layout">
    <aside class="sidebar-shell" aria-label="Main navigation">
        <div class="sidebar-brand">
            <div class="sidebar-logo">eQ</div>
            <div>
                <div class="sidebar-title">eXT.Qli</div>
                <div class="sidebar-caption">Endpoint Console</div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <details class="nav-group" open>
                <summary>
                    <span class="nav-summary-icon">01</span>
                    <span>Dashboard</span>
                </summary>

                <div class="nav-group-items">
                    <button type="button" class="nav-link is-active" data-view-target="agentsView">
                        <span class="nav-icon">AG</span>
                        <span>Agent Administration</span>
                    </button>
                </div>
            </details>
        </nav>

        <footer class="sidebar-footer">
            <span class="badge outline">Realtime</span>
            <span class="sidebar-footer-text">WebRTC endpoint monitoring</span>
        </footer>
    </aside>

    <div class="main-shell">
        <header class="topbar">
            <div class="brand-wrap">
                <div class="eyebrow">Endpoint EXecution Terminal</div>
                <h1 class="brand-title">Agent Administration</h1>
                <p class="brand-subtitle">
                    Monitor connected agents, preview live screens, and open remote control sessions from one polished workspace.
                </p>
            </div>

            <div class="topbar-actions">
                <span class="badge outline">Secure Console</span>
                <span class="badge">Live Grid</span>
            </div>
        </header>

        <main class="view-stack">
            <section id="agentsView" class="view-panel is-active">
                <section class="hero-card card">
                    <div class="hero-copy">
                        <span class="hero-kicker">Connected Agent Workspace</span>
                        <h2>Live endpoint visibility with compact screen previews.</h2>
                        <p>
                            Select an endpoint, view its stream, and enable remote input through the Screen Viewer panel.
                        </p>
                    </div>

                    <div class="hero-metrics">
                        <article class="hero-metric">
                            <span class="hero-metric-label">Online Clients</span>
                            <strong id="onlineClientsCount">0</strong>
                        </article>
                        <article class="hero-metric">
                            <span class="hero-metric-label">Live Streams</span>
                            <strong id="liveStreamsCount">0</strong>
                        </article>
                        <article class="hero-metric">
                            <span class="hero-metric-label">Remote Target</span>
                            <strong class="is-target" id="remoteTargetName">None</strong>
                        </article>
                    </div>
                </section>

                <div class="agent-admin-workspace">
                    <div class="agent-admin-tabs-shell card">
                        <div class="agent-admin-tab-strip" role="tablist" aria-label="Agent administration tabs">
                            <button type="button" class="agent-admin-tab is-active" data-agent-admin-tab-trigger="agents" role="tab">
                                Connected Agents
                            </button>
                            <button type="button" class="agent-admin-tab" data-agent-admin-tab-trigger="screen" role="tab">
                                Screen Viewer
                            </button>
                        </div>

                        <div class="agent-admin-surface">
                            <section id="agentAdminTabAgents" class="agent-admin-tab-panel" role="tabpanel">
                                <div class="extqli-dashboard-layout">
                                    <div class="extqli-remote-bar" id="extqliRemoteStatus" role="alert">
                                        <div class="extqli-remote-bar-text">
                                            <strong>Remote control is idle.</strong>
                                            <span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>
                                        </div>
                                        <button type="button" id="clearRemoteTargetBtn" class="btn btn-dark btn-sm">Clear Remote Target</button>
                                    </div>

                                    <div class="extqli-body">
                                        <aside class="extqli-sidebar">
                                            <div class="extqli-sidebar-hd">
                                                <div>
                                                    <span class="extqli-sidebar-title">Detected Clients</span>
                                                    <small>Agents discovered from heartbeat and stream status.</small>
                                                </div>
                                                <span class="extqli-realtime-pill">Realtime</span>
                                            </div>

                                            <div id="extqliClientList" class="extqli-client-list">
                                                <div class="extqli-empty">
                                                    <div role="status" class="skeleton line"></div>
                                                    <div role="status" class="skeleton line"></div>
                                                    <span>Loading agents...</span>
                                                </div>
                                            </div>
                                        </aside>

                                        <div class="extqli-main">
                                            <div id="extqliMonitorGrid" class="extqli-grid">
                                                <div class="extqli-empty">
                                                    <div role="status" class="skeleton box"></div>
                                                    <span>Loading agents...</span>
                                                </div>
                                            </div>

                                            <p class="extqli-tip">
                                                Tip: cards stay compact so more connected endpoints remain visible within the workspace.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div id="agentStatusBar" class="status-bar" style="display:none;"></div>
                            </section>

                            <section id="agentAdminTabScreen" class="agent-admin-tab-panel" role="tabpanel" hidden>
                                <div class="screen-view-layout">
                                    <section class="panel card">
                                        <div class="panel-header">
                                            <div>
                                                <span class="section-kicker">Controls</span>
                                                <h3>Viewer Controls</h3>
                                            </div>
                                            <span class="badge outline">Manual</span>
                                        </div>

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

                                    <section class="panel card">
                                        <div class="panel-header">
                                            <div>
                                                <span class="section-kicker">Live Session</span>
                                                <h3>Remote Screen</h3>
                                            </div>
                                            <span class="badge">Preview</span>
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
                                                    <div class="screen-empty-icon">RT</div>
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
        </main>
    </div>
</div>