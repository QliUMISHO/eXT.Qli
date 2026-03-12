<?php $pageTitle = 'Login'; ?>
<div class="wf-app" data-page="login">
    <div class="wf-auth-shell">
        <div class="wf-auth-card">
            <div class="wf-brand">WebFilter Server</div>
            <div class="wf-auth-title">Admin Login</div>
            <div class="wf-auth-subtitle">Sign in to manage devices, policies, and logs</div>
            <div class="wf-form-grid">
                <div class="wf-field">
                    <div class="wf-label">Username</div>
                    <div class="wf-input-wrap">
                        <input id="login_username" class="wf-input" type="text" autocomplete="username">
                    </div>
                </div>
                <div class="wf-field">
                    <div class="wf-label">Password</div>
                    <div class="wf-input-wrap">
                        <input id="login_password" class="wf-input" type="password" autocomplete="current-password">
                    </div>
                </div>
                <div class="wf-field">
                    <div id="login_message" class="wf-message"></div>
                </div>
                <div class="wf-field">
                    <div id="login_submit" class="wf-button wf-button-primary">Login</div>
                </div>
            </div>
        </div>
    </div>
    <div id="wf_assets" data-css="/web/assets/app.css" data-api="/web/assets/api.js" data-js="/web/assets/app.js"></div>
</div>