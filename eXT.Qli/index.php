<?php
$base = '/eXT.Qli';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eXT.Qli - Network Computer Scanner and Inventory</title>

    <link rel="stylesheet" href="<?= $base ?>/assets/css/app.css?v=3">
</head>
<body>
    <div id="pageLoader" class="page-loader">
        <div class="loader-card">
            <div class="loader-spinner"></div>
            <div class="loader-title">eXT.Qli</div>
            <div class="loader-subtitle">Loading network scanner interface...</div>
        </div>
    </div>

    <div class="app-shell">
        <header class="topbar">
            <div class="brand-wrap">
                <div class="brand-title">eXT.Qli</div>
                <div class="brand-subtitle">Network Computer Scanner and Inventory</div>
            </div>

            <div class="badge-wrap">
                <span class="top-badge">Ubuntu Local Server</span>
                <span class="top-badge">PHP + MySQL</span>
            </div>
        </header>

        <main class="content-grid">
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
                                placeholder="e.g. 192.168.1.0/24"
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
                        <label for="searchInput">Search Saved Devices</label>
                        <input
                            type="text"
                            id="searchInput"
                            placeholder="Search IP, hostname, MAC, vendor"
                        >
                    </div>

                    <div id="statusBar" class="status-bar">Ready</div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">Scan Results</div>
                <div class="panel-body">
                    <div id="scanResults" class="results-box">
                        <div class="empty-state">No scan yet.</div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="<?= $base ?>/assets/js/app.js?v=3"></script>
</body>
</html>