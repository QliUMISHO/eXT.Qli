<div class="app-layout">
    <div class="main-shell">
        <main class="view-stack">
            <section id="agentsView" class="view-panel is-active">
                <section class="hero-card card">
                    <div class="board-brand">
                        <div class="board-brand-title">eXT.Qli</div>
                        <div class="board-brand-caption">Endpoint Visibility Console</div>
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
                                                        <div role="status" class="skeleton line"></div>
                                                        <div role="status" class="skeleton line"></div>
                                                        <span>Loading agents...</span>
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
                                                        <div role="status" class="skeleton box"></div>
                                                        <span>Loading agents...</span>
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