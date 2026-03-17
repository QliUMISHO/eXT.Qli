<div class="ext-shell">
    <div id="scanLoadingOverlay" class="ext-loading-overlay">
        <div class="ext-loading-card">
            <div class="ext-loading-spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>
            <div class="ext-loading-title">Scanning Network</div>
            <div class="ext-loading-text" id="scanLoadingText">Please wait while nmap scans the network.</div>
        </div>
    </div>

    <div class="ext-topbar">
        <div class="ext-topbar__left">
            <div class="ext-brand">eXT.Qli</div>
            <div class="ext-subtitle">Network Computer Scanner and Inventory</div>
        </div>
        <div class="ext-topbar__right">
            <div class="ext-badge">Ubuntu Local Server</div>
            <div class="ext-badge">PHP + MySQL</div>
        </div>
    </div>

    <div class="ext-grid">
        <div class="ext-card">
            <div class="ext-card__title">Scan Network</div>
            <div class="ext-card__body">
                <div class="ext-form-row">
                    <div class="ext-field">
                        <div class="ext-label">Subnet CIDR or Server IP</div>
                        <div class="ext-input-wrap">
                            <input type="text" id="subnetInput" value="" placeholder="Example: 172.31.191.0/24 or 172.31.191.1">
                        </div>
                    </div>

                    <div class="ext-field ext-field--button">
                        <div class="ext-label">Action</div>
                        <div class="ext-btn-row">
                            <button id="scanBtn" type="button">Start Scan</button>
                            <button id="detectScanBtn" type="button">Detect & Scan</button>
                            <button id="refreshBtn" type="button" class="secondary">Refresh List</button>
                        </div>
                    </div>
                </div>

                <div class="ext-form-row ext-form-row--single">
                    <div class="ext-field">
                        <div class="ext-label">Search Saved Devices</div>
                        <div class="ext-input-wrap">
                            <input type="text" id="searchInput" placeholder="Search IP, hostname, MAC, vendor">
                        </div>
                    </div>
                </div>

                <div id="statusBox" class="ext-status ext-status--idle">Ready</div>
            </div>
        </div>

        <div class="ext-card">
            <div class="ext-card__title">Scan Results</div>
            <div class="ext-card__body">
                <div id="scanSummary" class="ext-summary">No scan yet.</div>
                <div id="scanResults" class="ext-results"></div>
            </div>
        </div>
    </div>

    <div class="ext-card ext-card--table">
        <div class="ext-card__title">Stored Devices</div>
        <div class="ext-card__body">
            <div class="ext-table-head">
                <div class="ext-table-count" id="deviceCount">0 devices</div>
            </div>

            <div class="ext-table-wrap">
                <div class="ext-table ext-table--header">
                    <div class="ext-col ext-col-ip">IP Address</div>
                    <div class="ext-col ext-col-host">Hostname</div>
                    <div class="ext-col ext-col-mac">MAC Address</div>
                    <div class="ext-col ext-col-vendor">Vendor</div>
                    <div class="ext-col ext-col-status">Status</div>
                    <div class="ext-col ext-col-date">Last Seen</div>
                    <div class="ext-col ext-col-action">Action</div>
                </div>
                <div id="deviceTableBody"></div>
            </div>
        </div>
    </div>
</div>