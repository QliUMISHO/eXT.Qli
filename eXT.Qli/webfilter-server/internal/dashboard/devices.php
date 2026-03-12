<?php $adminName = $_SESSION['admin_name'] ?? 'Administrator'; ?>
<div class="wf-app" data-page="devices">
    <div class="wf-shell">
        <div class="wf-sidebar">
            <div class="wf-brand">WebFilter</div>
            <div class="wf-nav">
                <div class="wf-nav-item" data-link="/dashboard">Overview</div>
                <div class="wf-nav-item wf-nav-active" data-link="/devices">Devices</div>
                <div class="wf-nav-item" data-link="/policies">Policies</div>
                <div class="wf-nav-item" data-link="/logs">Logs</div>
                <div class="wf-nav-item wf-nav-danger" id="logout_button">Logout</div>
            </div>
        </div>
        <div class="wf-main">
            <div class="wf-topbar">
                <div class="wf-title">Devices</div>
                <div class="wf-user"><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="wf-panel">
                <div class="wf-panel-title">Registered Devices</div>
                <div id="devices_table" class="wf-table"></div>
            </div>
        </div>
    </div>
    <div id="wf_assets" data-css="/web/assets/app.css" data-api="/web/assets/api.js" data-js="/web/assets/app.js"></div>
</div>