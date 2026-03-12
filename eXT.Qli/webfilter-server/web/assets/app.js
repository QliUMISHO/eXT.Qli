(function () {
    function loadCss(path) {
        if (!path || document.querySelector(`link[data-wf="${path}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = path;
        link.dataset.wf = path;
        document.head.appendChild(link);
    }

    function loadScript(path) {
        return new Promise((resolve, reject) => {
            if (!path) {
                resolve();
                return;
            }

            const existing = document.querySelector(`script[data-wf="${path}"]`);
            if (existing) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = path;
            script.defer = true;
            script.dataset.wf = path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    const assetNode = document.getElementById('wf_assets');
    if (assetNode) {
        loadCss(assetNode.dataset.css || '');
    }

    function q(id) {
        return document.getElementById(id);
    }

    function text(id, value) {
        const el = q(id);
        if (el) el.textContent = value;
    }

    function navBind() {
        document.querySelectorAll('[data-link]').forEach(node => {
            node.addEventListener('click', () => {
                window.location.href = node.dataset.link;
            });
        });
    }

    function renderTable(targetId, columns, rows) {
        const target = q(targetId);
        if (!target) return;

        const head = document.createElement('div');
        head.className = 'wf-row wf-head';
        head.style.setProperty('--wf-cols', columns.length);

        columns.forEach(col => {
            const cell = document.createElement('div');
            cell.className = 'wf-cell';
            cell.textContent = col.label;
            head.appendChild(cell);
        });

        target.innerHTML = '';
        target.appendChild(head);

        rows.forEach(row => {
            const line = document.createElement('div');
            line.className = 'wf-row';
            line.style.setProperty('--wf-cols', columns.length);

            columns.forEach(col => {
                const cell = document.createElement('div');
                cell.className = 'wf-cell';

                if (col.render) {
                    const content = col.render(row[col.key], row);
                    if (content instanceof HTMLElement) {
                        cell.appendChild(content);
                    } else {
                        cell.innerHTML = content;
                    }
                } else {
                    cell.textContent = row[col.key] ?? '';
                }

                line.appendChild(cell);
            });

            target.appendChild(line);
        });
    }

    async function loginBind() {
        const submit = q('login_submit');
        if (!submit) return;

        submit.addEventListener('click', async () => {
            const username = q('login_username')?.value?.trim() || '';
            const password = q('login_password')?.value || '';

            try {
                text('login_message', 'Signing in...');
                await window.WFAPI.post('/api/auth/login', { username, password });
                text('login_message', 'Login successful');
                window.location.href = '/dashboard';
            } catch (e) {
                text('login_message', e.message || 'Login failed');
            }
        });
    }

    async function logoutBind() {
        const node = q('logout_button');
        if (!node) return;

        node.addEventListener('click', async () => {
            try {
                await window.WFAPI.post('/api/auth/logout', {});
            } catch (e) {
            }
            window.location.href = '/login';
        });
    }

    async function loadDashboard() {
        const devicesWrap = q('dashboard_devices');
        const logsWrap = q('dashboard_logs');
        if (!devicesWrap && !logsWrap) return;

        const [devices, policies, logs] = await Promise.all([
            window.WFAPI.get('/api/devices'),
            window.WFAPI.get('/api/policies'),
            window.WFAPI.get('/api/logs')
        ]);

        text('stat_devices', String(devices.data.length));
        text('stat_policies', String(policies.data.length));
        text('stat_logs', String(logs.data.length));

        renderTable('dashboard_devices', [
            { key: 'hostname', label: 'Hostname' },
            { key: 'ip_address', label: 'IP' },
            { key: 'operating_system', label: 'OS' },
            {
                key: 'status',
                label: 'Status',
                render: (value) => {
                    const cls = value === 'online' ? 'wf-badge' : 'wf-badge wf-badge-offline';
                    return `<div class="${cls}">${value || 'unknown'}</div>`;
                }
            }
        ], devices.data.slice(0, 8));

        renderTable('dashboard_logs', [
            { key: 'hostname', label: 'Hostname' },
            { key: 'domain', label: 'Domain' },
            { key: 'action', label: 'Action' },
            { key: 'created_at', label: 'Time' }
        ], logs.data.slice(0, 8));
    }

    async function loadDevices() {
        const target = q('devices_table');
        if (!target) return;

        const res = await window.WFAPI.get('/api/devices');

        renderTable('devices_table', [
            { key: 'device_uuid', label: 'UUID' },
            { key: 'hostname', label: 'Hostname' },
            { key: 'ip_address', label: 'IP' },
            { key: 'operating_system', label: 'OS' },
            { key: 'agent_version', label: 'Agent' },
            { key: 'policy_name', label: 'Policy' },
            {
                key: 'status',
                label: 'Status',
                render: (value) => {
                    const cls = value === 'online' ? 'wf-badge' : 'wf-badge wf-badge-offline';
                    return `<div class="${cls}">${value || 'unknown'}</div>`;
                }
            },
            { key: 'last_seen_at', label: 'Last Seen' }
        ], res.data);
    }

    async function refreshPolicies() {
        const res = await window.WFAPI.get('/api/policies');

        const select = q('rule_policy_id');
        if (select) {
            select.innerHTML = '<option value="">Select policy</option>';
            res.data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                select.appendChild(opt);
            });
        }

        renderTable('policies_table', [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Name' },
            { key: 'description', label: 'Description' },
            { key: 'status', label: 'Status' },
            { key: 'rule_count', label: 'Rules' },
            { key: 'created_at', label: 'Created' }
        ], res.data);

        if (select && select.value) {
            await refreshRules(select.value);
        }
    }

    async function refreshRules(policyId) {
        if (!policyId) {
            const target = q('rules_table');
            if (target) target.innerHTML = '';
            return;
        }

        const res = await window.WFAPI.get(`/api/rules?policy_id=${encodeURIComponent(policyId)}`);

        renderTable('rules_table', [
            { key: 'id', label: 'ID' },
            { key: 'rule_type', label: 'Type' },
            { key: 'match_type', label: 'Match' },
            { key: 'value', label: 'Value' },
            { key: 'enabled', label: 'Enabled' },
            { key: 'created_at', label: 'Created' }
        ], res.data);
    }

    async function bindPolicies() {
        const savePolicy = q('policy_save');
        const saveRule = q('rule_save');
        const policySelect = q('rule_policy_id');

        if (policySelect) {
            policySelect.addEventListener('change', async () => {
                await refreshRules(policySelect.value);
            });
        }

        if (savePolicy) {
            savePolicy.addEventListener('click', async () => {
                const name = q('policy_name')?.value?.trim() || '';
                const description = q('policy_description')?.value?.trim() || '';

                try {
                    text('policy_message', 'Saving policy...');
                    await window.WFAPI.post('/api/policies/save', {
                        name,
                        description,
                        status: 'active'
                    });
                    text('policy_message', 'Policy saved');
                    q('policy_name').value = '';
                    q('policy_description').value = '';
                    await refreshPolicies();
                } catch (e) {
                    text('policy_message', e.message || 'Failed to save policy');
                }
            });
        }

        if (saveRule) {
            saveRule.addEventListener('click', async () => {
                const policy_id = parseInt(q('rule_policy_id')?.value || '0', 10);
                const rule_type = q('rule_type')?.value || 'block';
                const match_type = q('rule_match_type')?.value || 'exact';
                const value = q('rule_value')?.value?.trim() || '';

                try {
                    text('rule_message', 'Saving rule...');
                    await window.WFAPI.post('/api/rules/save', {
                        policy_id,
                        rule_type,
                        match_type,
                        value,
                        enabled: 1
                    });
                    text('rule_message', 'Rule saved');
                    q('rule_value').value = '';
                    await refreshRules(policy_id);
                    await refreshPolicies();
                } catch (e) {
                    text('rule_message', e.message || 'Failed to save rule');
                }
            });
        }

        if (q('policies_table')) {
            await refreshPolicies();
        }
    }

    async function loadLogs() {
        const target = q('logs_table');
        if (!target) return;

        const res = await window.WFAPI.get('/api/logs');

        renderTable('logs_table', [
            { key: 'device_uuid', label: 'Device UUID' },
            { key: 'hostname', label: 'Hostname' },
            { key: 'domain', label: 'Domain' },
            { key: 'action', label: 'Action' },
            { key: 'reason', label: 'Reason' },
            { key: 'created_at', label: 'Time' }
        ], res.data);
    }

    async function boot() {
        if (assetNode) {
            await loadScript(assetNode.dataset.api || '');
        }

        navBind();
        await loginBind();
        await logoutBind();

        try {
            await loadDashboard();
        } catch (e) {
        }

        try {
            await loadDevices();
        } catch (e) {
        }

        try {
            await bindPolicies();
        } catch (e) {
        }

        try {
            await loadLogs();
        } catch (e) {
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();