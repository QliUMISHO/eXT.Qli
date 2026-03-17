const statusBox = document.getElementById('statusBox');
const scanBtn = document.getElementById('scanBtn');
const refreshBtn = document.getElementById('refreshBtn');
const subnetInput = document.getElementById('subnetInput');
const searchInput = document.getElementById('searchInput');
const scanResults = document.getElementById('scanResults');
const scanSummary = document.getElementById('scanSummary');
const deviceTableBody = document.getElementById('deviceTableBody');
const deviceCount = document.getElementById('deviceCount');

function setStatus(type, message) {
    statusBox.className = 'ext-status';
    statusBox.classList.add(`ext-status--${type}`);
    statusBox.textContent = message;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Request failed');
    }
    return data;
}

function renderScanResults(items) {
    if (!items.length) {
        scanResults.innerHTML = `<div class="ext-empty">No live hosts found.</div>`;
        return;
    }

    scanResults.innerHTML = items.map(item => `
        <div class="ext-result-row">
            <div class="ext-result-cell">
                <div class="ext-result-label">IP Address</div>
                <div class="ext-result-value">${escapeHtml(item.ip_address)}</div>
            </div>
            <div class="ext-result-cell">
                <div class="ext-result-label">Hostname</div>
                <div class="ext-result-value">${escapeHtml(item.hostname || '-')}</div>
            </div>
            <div class="ext-result-cell">
                <div class="ext-result-label">MAC / Vendor</div>
                <div class="ext-result-value">${escapeHtml(item.mac_address || '-')} ${item.vendor ? ' / ' + escapeHtml(item.vendor) : ''}</div>
            </div>
        </div>
    `).join('');
}

function renderDevices(items) {
    deviceCount.textContent = `${items.length} device${items.length !== 1 ? 's' : ''}`;

    if (!items.length) {
        deviceTableBody.innerHTML = `<div class="ext-empty">No saved devices yet.</div>`;
        return;
    }

    deviceTableBody.innerHTML = items.map(item => `
        <div class="ext-table-row">
            <div class="ext-col ext-col-ip">${escapeHtml(item.ip_address)}</div>
            <div class="ext-col ext-col-host">${escapeHtml(item.hostname || '-')}</div>
            <div class="ext-col ext-col-mac">${escapeHtml(item.mac_address || '-')}</div>
            <div class="ext-col ext-col-vendor">${escapeHtml(item.vendor || '-')}</div>
            <div class="ext-col ext-col-status">
                <span class="ext-status-pill ${item.status === 'Online' ? 'ext-status-pill--online' : 'ext-status-pill--offline'}">
                    ${escapeHtml(item.status)}
                </span>
            </div>
            <div class="ext-col ext-col-date">${escapeHtml(item.last_seen || '-')}</div>
            <div class="ext-col ext-col-action">
                <button class="ext-delete-btn" data-id="${escapeHtml(item.id)}">Delete</button>
            </div>
        </div>
    `).join('');
}

async function loadDevices(search = '') {
    try {
        const data = await requestJson(`${window.EXT_QLI.appUrl}/backend/api/devices.php?search=${encodeURIComponent(search)}`);
        renderDevices(data.devices || []);
    } catch (error) {
        setStatus('error', error.message);
    }
}

async function runScan() {
    const subnet = subnetInput.value.trim();

    if (!subnet) {
        setStatus('error', 'Please enter a subnet.');
        return;
    }

    scanBtn.disabled = true;
    refreshBtn.disabled = true;
    setStatus('loading', `Scanning ${subnet}...`);
    scanSummary.textContent = 'Scanning in progress...';
    scanResults.innerHTML = '';

    try {
        const data = await requestJson(`${window.EXT_QLI.appUrl}/backend/api/scan.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ subnet })
        });

        const count = data.results ? data.results.length : 0;
        scanSummary.textContent = `Scan finished. ${count} live host${count !== 1 ? 's' : ''} found in ${escapeHtml(data.subnet)}.`;
        renderScanResults(data.results || []);
        setStatus('success', data.message || 'Scan complete.');
        await loadDevices(searchInput.value.trim());
    } catch (error) {
        scanSummary.textContent = 'Scan failed.';
        scanResults.innerHTML = '';
        setStatus('error', error.message);
    } finally {
        scanBtn.disabled = false;
        refreshBtn.disabled = false;
    }
}

async function deleteDevice(id) {
    try {
        const data = await requestJson(`${window.EXT_QLI.appUrl}/backend/api/delete_device.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id })
        });

        setStatus('success', data.message || 'Device deleted.');
        await loadDevices(searchInput.value.trim());
    } catch (error) {
        setStatus('error', error.message);
    }
}

scanBtn.addEventListener('click', runScan);

refreshBtn.addEventListener('click', () => {
    setStatus('idle', 'Refreshing saved devices...');
    loadDevices(searchInput.value.trim()).then(() => {
        setStatus('success', 'Saved devices refreshed.');
    });
});

searchInput.addEventListener('input', () => {
    loadDevices(searchInput.value.trim());
});

document.addEventListener('click', event => {
    const btn = event.target.closest('.ext-delete-btn');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    if (!id) return;
    deleteDevice(id);
});

loadDevices();