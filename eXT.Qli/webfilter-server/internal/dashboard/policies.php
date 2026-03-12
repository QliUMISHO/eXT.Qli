<?php $adminName = $_SESSION['admin_name'] ?? 'Administrator'; ?>
<div class="wf-app" data-page="policies">
    <div class="wf-shell">
        <div class="wf-sidebar">
            <div class="wf-brand">WebFilter</div>
            <div class="wf-nav">
                <div class="wf-nav-item" data-link="/dashboard">Overview</div>
                <div class="wf-nav-item" data-link="/devices">Devices</div>
                <div class="wf-nav-item wf-nav-active" data-link="/policies">Policies</div>
                <div class="wf-nav-item" data-link="/logs">Logs</div>
                <div class="wf-nav-item wf-nav-danger" id="logout_button">Logout</div>
            </div>
        </div>
        <div class="wf-main">
            <div class="wf-topbar">
                <div class="wf-title">Policies</div>
                <div class="wf-user"><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="wf-grid-two">
                <div class="wf-panel">
                    <div class="wf-panel-title">Create Policy</div>
                    <div class="wf-form-grid">
                        <div class="wf-field">
                            <div class="wf-label">Policy Name</div>
                            <div class="wf-input-wrap">
                                <input id="policy_name" class="wf-input" type="text">
                            </div>
                        </div>
                        <div class="wf-field">
                            <div class="wf-label">Description</div>
                            <div class="wf-input-wrap">
                                <input id="policy_description" class="wf-input" type="text">
                            </div>
                        </div>
                        <div class="wf-field">
                            <div id="policy_save" class="wf-button wf-button-primary">Save Policy</div>
                        </div>
                        <div class="wf-field">
                            <div id="policy_message" class="wf-message"></div>
                        </div>
                    </div>
                </div>
                <div class="wf-panel">
                    <div class="wf-panel-title">Create Rule</div>
                    <div class="wf-form-grid">
                        <div class="wf-field">
                            <div class="wf-label">Policy</div>
                            <div class="wf-input-wrap">
                                <select id="rule_policy_id" class="wf-input"></select>
                            </div>
                        </div>
                        <div class="wf-field">
                            <div class="wf-label">Rule Type</div>
                            <div class="wf-input-wrap">
                                <select id="rule_type" class="wf-input">
                                    <option value="block">block</option>
                                    <option value="allow">allow</option>
                                </select>
                            </div>
                        </div>
                        <div class="wf-field">
                            <div class="wf-label">Match Type</div>
                            <div class="wf-input-wrap">
                                <select id="rule_match_type" class="wf-input">
                                    <option value="exact">exact</option>
                                    <option value="wildcard">wildcard</option>
                                </select>
                            </div>
                        </div>
                        <div class="wf-field">
                            <div class="wf-label">Value</div>
                            <div class="wf-input-wrap">
                                <input id="rule_value" class="wf-input" type="text" placeholder="example.com">
                            </div>
                        </div>
                        <div class="wf-field">
                            <div id="rule_save" class="wf-button wf-button-primary">Save Rule</div>
                        </div>
                        <div class="wf-field">
                            <div id="rule_message" class="wf-message"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wf-grid-two">
                <div class="wf-panel">
                    <div class="wf-panel-title">Policies</div>
                    <div id="policies_table" class="wf-table"></div>
                </div>
                <div class="wf-panel">
                    <div class="wf-panel-title">Rules</div>
                    <div id="rules_table" class="wf-table"></div>
                </div>
            </div>
        </div>
    </div>
    <div id="wf_assets" data-css="/web/assets/app.css" data-api="/web/assets/api.js" data-js="/web/assets/app.js"></div>
</div>