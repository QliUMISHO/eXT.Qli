<?php $adminName = $_SESSION['admin_name'] ?? 'Administrator'; ?>
<div class="wf-app" data-page="dashboard">
    <div class="wf-shell">
        <div class="wf-sidebar">
            <div class="wf-brand">WebFilter</div>
            <div class="wf-nav">
                <div class="wf-nav-item" data-link="/dashboard">Overview</div>
                <div class="wf-nav-item" data-link="/devices">Devices</div>
                <div class="wf-nav-item" data-link="/policies">Policies</div>
                <div class="wf-nav-item" data-link="/logs">Logs</div>
                <div class="wf-nav-item wf-nav-danger" id="logout_button">Logout</div>
            </div>
        </div>
        <div class="wf-main">
            <div class="wf-topbar">
                <div class="wf-title">Dashboard</div>
                <div class="wf-user"><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="wf-cards">
                <div class="wf-card">
                    <div class="wf-card-label">System Status</div>
                    <div class="wf-card-value">Online</div>
                </div>
                <div class="wf-card">
                    <div class="wf-card-label">Managed Devices</div>
                    <div class="wf-card-value" id="stat_devices">0</div>
                </div>
                <div class="wf-card">
                    <div class="wf-card-label">Policies</div>
                    <div class="wf-card-value" id="stat_policies">0</div>
                </div>
                <div class="wf-card">
                    <div class="wf-card-label">Recent Blocks</div>
                    <div class="wf-card-value" id="stat_logs">0</div>
                </div>
            </div>
            <div class="wf-grid-two">
                <div class="wf-panel">
                    <div class="wf-panel-title">Recent Devices</div>
                    <div id="dashboard_devices" class="wf-table"></div>
                </div>
                <div class="wf-panel">
                    <div class="wf-panel-title">Recent Logs</div>
                    <div id="dashboard_logs" class="wf-table"></div>
                </div>
            </div>
        </div>
    </div>
    <div id="wf_assets" data-css="/web/assets/app.css" data-api="/web/assets/api.js" data-js="/web/assets/app.js"></div>
</div>