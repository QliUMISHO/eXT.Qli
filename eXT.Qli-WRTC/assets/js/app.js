(function () {
    'use strict';

    var BASE_PATH = window.EXTQLI_API_BASE_PATH || '/eXT.Qli';
    var SIGNALING_URL = BASE_PATH + '/backend/api/signaling.php';
    var VIEWER_ID = 'viewer-' + Math.random().toString(36).slice(2) + Date.now().toString(36);

    var extqliAgentsList = [];
    var extqliAgentPollTimer = null;

    var extqliCurrentScreenAgentUuid = '';
    var extqliIsScreenViewing = false;
    var extqliCurrentAdminTab = 'agents';

    var extqliViewerPeers = {};
    var extqliIdentityByAgentUuid = {};
    var extqliPersistedIdentityByAgentUuid = {};
    var extqliPersistingIdentityByAgentUuid = {};

    var extqliRemoteControlEnabled = false;

    var extqliRemoteScreenWidth = 1920;
    var extqliRemoteScreenHeight = 1080;

    var extqliLastMouseMoveTime = 0;
    var EXTQLI_MOUSE_MOVE_INTERVAL_MS = 33;

    var EXTQLI_STREAM_QUALITY = {
        maxBitrateKbps: 8000,
        frameRateCap: 30
    };

    var EXTQLI_AUTO_CONNECT_ALL = true;
    var autoConnectQueue = [];
    var autoConnectActive = false;

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

    function normalizeDisplayValue(value) {
        var text = String(value == null ? '' : value).trim();
        if (!text) return '';
        var lowered = text.toLowerCase();
        if (lowered === 'unknown' || lowered === 'none' || lowered === 'null' || lowered === '-' || lowered === 'username unavailable' || lowered === 'fetching username...' || lowered === 'waiting for username...') return '';
        return text;
    }

    function extractUsernameFromIdentityPayload(payload) {
        if (!payload) return '';
        return normalizeDisplayValue(payload.endpoint_username_output) ||
            normalizeDisplayValue(payload.username_stdout) ||
            normalizeDisplayValue(payload.username_probe_output) ||
            normalizeDisplayValue(payload.logged_in_username) ||
            normalizeDisplayValue(payload.username) ||
            normalizeDisplayValue(payload.current_user) ||
            normalizeDisplayValue(payload.display_name);
    }

    function getAgentUsername(agent) {
        if (!agent) return '';
        var agentUuid = String(agent.agent_uuid || '');
        return normalizeDisplayValue(extqliIdentityByAgentUuid[agentUuid]) ||
            extractUsernameFromIdentityPayload(agent);
    }

    function findAgentByUuid(agentUuid) {
        agentUuid = String(agentUuid || '');
        for (var i = 0; i < extqliAgentsList.length; i++) {
            if (String(extqliAgentsList[i].agent_uuid || '') === agentUuid) {
                return extqliAgentsList[i];
            }
        }
        return null;
    }

    function setAgentIdentityOnModel(agentUuid, username, payload) {
        var agent = findAgentByUuid(agentUuid);
        if (!agent) return;
        agent.username = username;
        agent.logged_in_username = username;
        agent.current_user = username;
        agent.endpoint_username_output = username;
        agent.username_stdout = username;
        agent.username_probe_output = username;
        agent.display_name = username;
        if (payload && payload.hostname) agent.hostname = payload.hostname;
    }

    function persistAgentIdentity(agentUuid, username, payload) {
        agentUuid = String(agentUuid || "").trim();
        username = normalizeDisplayValue(username);
        if (!agentUuid || !username) return;

        var alreadyPersisted = extqliPersistedIdentityByAgentUuid[agentUuid];
        if (alreadyPersisted === username) return;

        var inProgress = extqliPersistingIdentityByAgentUuid[agentUuid];
        if (inProgress === username) return;

        extqliPersistingIdentityByAgentUuid[agentUuid] = username;

        fetch(BASE_PATH + "/backend/api/save_agent_identity.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify({
                shared_token: "extqli_@2026token$$",
                agent_uuid: agentUuid,
                username: username,
                logged_in_username: username,
                endpoint_username_output: username,
                username_stdout: username,
                source: "webrtc_data_channel",
                identity_payload: payload || {}
            })
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : "Username save failed.");
                }
                extqliPersistedIdentityByAgentUuid[agentUuid] = username;
                console.log("[DEBUG] Agent username stored in MySQL:", agentUuid, username);
            })
            .catch(function (err) {
                console.warn("[DEBUG] Agent username save failed:", agentUuid, err && err.message ? err.message : err);
            })
            .finally(function () {
                if (extqliPersistingIdentityByAgentUuid[agentUuid] === username) {
                    delete extqliPersistingIdentityByAgentUuid[agentUuid];
                }
            });
    }

    function getAgentHostname(agent) {
        var hostname = normalizeDisplayValue(agent && agent.hostname);
        return hostname || 'Hostname not reported';
    }

    function getAgentHostnameByUuid(agentUuid) {
        var agent = extqliAgentsList.find(function (item) {
            return String(item.agent_uuid || '') === String(agentUuid || '');
        });
        return getAgentHostname(agent);
    }

    function updateAgentIdentityDom(agentUuid, username) {
        var cards = [];
        var hostname = getAgentHostnameByUuid(agentUuid);

        qsa('.extqli-agent-card').forEach(function (card) {
            if (String(card.getAttribute('data-agent-uuid') || '') === String(agentUuid || '')) cards.push(card);
        });

        qsa('.extqli-monitor-card').forEach(function (card) {
            if (String(card.getAttribute('data-agent-uuid') || '') === String(agentUuid || '')) cards.push(card);
        });

        cards.forEach(function (card) {
            var nameNode = card.querySelector('.extqli-card-title');
            var userNode = card.querySelector('.extqli-card-user');

            if (nameNode) nameNode.textContent = username;
            if (userNode) userNode.textContent = 'User: ' + hostname;

            Array.prototype.slice.call(card.querySelectorAll('.extqli-card-meta')).forEach(function (node) {
                if ((node.textContent || '').trim().toLowerCase().indexOf('user:') === 0) {
                    node.textContent = 'User: ' + hostname;
                }
            });
        });
    }

    function applyAgentIdentity(agentUuid, payload) {
        var username = extractUsernameFromIdentityPayload(payload);
        if (!agentUuid || !username) return;
        extqliIdentityByAgentUuid[String(agentUuid)] = username;
        setAgentIdentityOnModel(agentUuid, username, payload || {});
        updateAgentIdentityDom(agentUuid, username);
        persistAgentIdentity(agentUuid, username, payload || {});
        populateScreenAgentOptions(extqliAgentsList);

        if (extqliCurrentScreenAgentUuid === agentUuid) {
            updateRemoteTargetDisplay(agentUuid, extqliRemoteControlEnabled);
        }

        console.log('[DEBUG] Agent identity applied:', agentUuid, username);
    }

    function handleAgentDataChannelMessage(agentUuid, event) {
        var raw = event && event.data;
        console.log('[DEBUG] Message from agent:', raw);

        var data = null;
        try {
            data = typeof raw === 'string' ? JSON.parse(raw) : raw;
        } catch (err) {
            return;
        }

        if (!data || typeof data !== 'object') return;

        if (data.type === 'agent_identity' || data.type === 'identity' || data.type === 'agent_info') {
            applyAgentIdentity(agentUuid, data);
        }
    }

    function getAgentDisplayName(agent) {
        var username = getAgentUsername(agent);
        if (username) return username;
        return agent && agent.is_online ? 'Waiting for username...' : 'Username not reported';
    }

    function hidePageLoader() {
        var loader = byId('pageLoader');
        if (!loader) return;
        loader.classList.add('is-hidden');
        setTimeout(function () {
            if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
        }, 350);
    }

    function setAgentStatus(message) {
        var el = byId('agentStatusBar');
        if (el) el.textContent = message || 'Loading agents...';
    }

    function setScreenStatus(message) {
        var el = byId('screenStatusBar');
        if (el) el.textContent = message || 'Waiting for a selected agent.';
    }

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

    function activeViewerPeer() {
        return extqliCurrentScreenAgentUuid ? (extqliViewerPeers[extqliCurrentScreenAgentUuid] || null) : null;
    }

    function isConnectionAlive(agentUuid) {
        var peer = extqliViewerPeers[agentUuid];
        if (!peer || !peer.pc) return false;
        var state = peer.pc.connectionState;
        return state === 'connected' || state === 'connecting';
    }

    function getLiveStreamCount(list) {
        var count = 0;
        (list || []).forEach(function (agent) {
            if (!agent || !agent.is_online) return;
            if (isConnectionAlive(agent.agent_uuid)) count += 1;
        });
        return count;
    }

    function updateLiveCounts() {
        var onlineCount = extqliAgentsList.filter(function (a) { return a.is_online; }).length;
        var liveCount = getLiveStreamCount(extqliAgentsList);
        var onlineEl = byId('onlineClientsCount');
        var liveEl = byId('liveStreamsCount');

        if (onlineEl) onlineEl.textContent = onlineCount;
        if (liveEl) liveEl.textContent = liveCount;
    }

    function mutateSdpForQuality(sdp, maxKbps, frameRateCap) {
        var lines = sdp.split('\r\n');
        var out = [];
        var inVideo = false;
        var bInjected = false;
        var vp8Pt = null;

        lines.forEach(function (line) {
            var m = line.match(/^a=rtpmap:(\d+) VP8\/90000/i);
            if (m) vp8Pt = m[1];
        });

        for (var i = 0; i < lines.length; i++) {
            var currentLine = lines[i];

            if (currentLine.indexOf('m=video') === 0) {
                inVideo = true;
                bInjected = false;
            } else if (currentLine.indexOf('m=') === 0 && currentLine.indexOf('m=video') !== 0) {
                inVideo = false;
            }

            out.push(currentLine);

            if (inVideo && !bInjected && currentLine.indexOf('c=') === 0) {
                out.push('b=AS:' + maxKbps);
                out.push('b=TIAS:' + (maxKbps * 1000));
                bInjected = true;
            }

            if (inVideo && vp8Pt && currentLine.indexOf('a=fmtp:' + vp8Pt + ' ') === 0) {
                out.pop();
                out.push(currentLine + ';x-google-max-bitrate=' + maxKbps + ';x-google-min-bitrate=' + Math.round(maxKbps * 0.1) + ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3));
            }

            if (inVideo && currentLine.indexOf('a=rtpmap:') === 0 && vp8Pt && currentLine.indexOf('VP8/90000') !== -1 && frameRateCap) {
                var hasFmtp = lines.some(function (line) {
                    return line.indexOf('a=fmtp:' + vp8Pt + ' ') === 0;
                });

                if (!hasFmtp) {
                    out.push('a=fmtp:' + vp8Pt + ' max-fr=' + frameRateCap + ';x-google-max-bitrate=' + maxKbps + ';x-google-min-bitrate=' + Math.round(maxKbps * 0.1) + ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3));
                }
            }
        }

        return out.join('\r\n');
    }

    function buildRtcConfiguration() {
        var TURN_SERVER = 'turn:10.201.0.254:3478?transport=tcp';
        var TURN_USERNAME = 'tachyon';
        var TURN_CREDENTIAL = 'TachyonDragon107';

        return {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: TURN_SERVER, username: TURN_USERNAME, credential: TURN_CREDENTIAL }
            ]
        };
    }

    function getGridPreviewVideo(agentUuid) {
        return document.querySelector('.extqli-preview-video[data-agent-uuid="' + agentUuid + '"]');
    }

    function getGridPreviewOverlay(agentUuid) {
        return document.querySelector('.extqli-preview-overlay[data-agent-uuid="' + agentUuid + '"]');
    }

    function setPreviewOverlayState(agentUuid, isLive, message) {
        var overlay = getGridPreviewOverlay(agentUuid);
        if (!overlay) return;
        overlay.style.display = isLive ? 'none' : 'flex';
        if (message) overlay.textContent = message;
    }

    function setPreviewVideoStream(agentUuid, stream, retryCount) {
        retryCount = retryCount || 0;

        var video = getGridPreviewVideo(agentUuid);
        if (!video) {
            if (retryCount < 10) {
                setTimeout(function () {
                    setPreviewVideoStream(agentUuid, stream, retryCount + 1);
                }, 200);
            }
            return;
        }

        if (video.srcObject === stream) return;

        video.srcObject = stream;
        video.muted = true;
        video.autoplay = true;
        video.playsInline = true;
        video.style.display = 'block';

        video.play().catch(function (e) {
            if (e.name === 'AbortError') return;
            console.warn('Preview video play error for', agentUuid, e);
        });

        setPreviewOverlayState(agentUuid, true, '');
    }

    function clearPreviewVideoStream(agentUuid) {
        var video = getGridPreviewVideo(agentUuid);
        if (!video) return;

        if (video.srcObject) {
            video.srcObject = null;
        }

        video.style.display = 'none';
        setPreviewOverlayState(agentUuid, false, 'Connecting...');
    }

    function reattachAllPreviewStreams() {
        for (var uuid in extqliViewerPeers) {
            var peer = extqliViewerPeers[uuid];
            if (peer && peer.stream) {
                setPreviewVideoStream(uuid, peer.stream);
            }
        }
    }

    function onRemoteTrackForAgent(agentUuid, event) {
        console.log('[DEBUG] ontrack for agent', agentUuid);

        var stream = (event.streams && event.streams[0]) ? event.streams[0] : new MediaStream([event.track]);
        var peer = extqliViewerPeers[agentUuid];

        if (peer) peer.stream = stream;

        setPreviewVideoStream(agentUuid, stream);
    }

    async function createWebRTCOffer(agentUuid) {
        console.log('[DEBUG] createWebRTCOffer called for agent:', agentUuid);

        var existing = extqliViewerPeers[agentUuid];
        if (existing && existing.pc) {
            var existingState = existing.pc.connectionState;

            if (existingState === 'connected' || existingState === 'connecting') {
                console.log('[DEBUG] Connection already alive for', agentUuid);
                return existing;
            }

            console.log('[DEBUG] Closing dead connection (' + existingState + ') for', agentUuid);

            try {
                if (existing.answerPollInterval) clearInterval(existing.answerPollInterval);
            } catch (err) {}

            try {
                existing.pc.close();
            } catch (err) {}

            clearPreviewVideoStream(agentUuid);
            delete extqliViewerPeers[agentUuid];
        }

        var peer = {
            pc: null,
            dataChannel: null,
            answerPollInterval: null,
            connectionReady: false,
            stream: null
        };

        extqliViewerPeers[agentUuid] = peer;

        peer.pc = new RTCPeerConnection(buildRtcConfiguration());

        peer.pc.onconnectionstatechange = function () {
            console.log('[DEBUG] pc.connectionState =', peer.pc.connectionState, 'for', agentUuid);

            if (peer.pc.connectionState === 'connected') {
                peer.connectionReady = true;
                updateLiveCounts();

                if (agentUuid === extqliCurrentScreenAgentUuid && extqliIsScreenViewing) {
                    var mainVideo = byId('remoteScreenVideo');
                    if (mainVideo && peer.stream && mainVideo.srcObject !== peer.stream) {
                        mainVideo.srcObject = peer.stream;
                        mainVideo.play().catch(function () {});
                        setScreenStatus('Streaming active');
                        showScreenEmptyState(false);
                    }
                }
            } else if (peer.pc.connectionState === 'failed' || peer.pc.connectionState === 'disconnected') {
                peer.connectionReady = false;
                updateLiveCounts();

                if (agentUuid === extqliCurrentScreenAgentUuid) {
                    setScreenStatus('Connection lost — try reconnecting.');
                    showScreenEmptyState(true);
                }

                clearPreviewVideoStream(agentUuid);
            }
        };

        peer.pc.oniceconnectionstatechange = function () {
            console.log('[DEBUG] pc.iceConnectionState =', peer.pc.iceConnectionState, 'for', agentUuid);
        };

        peer.pc.onicecandidate = function (event) {
            if (event.candidate) {
                console.log('[DEBUG] ICE candidate:', event.candidate.candidate);
            } else {
                console.log('[DEBUG] ICE gathering completed');
            }
        };

        peer.pc.addTransceiver('video', { direction: 'recvonly' });

        peer.pc.ontrack = function (event) {
            onRemoteTrackForAgent(agentUuid, event);
        };

        peer.dataChannel = peer.pc.createDataChannel('controlChannel');

        peer.dataChannel.onopen = function () {
            console.log('[DEBUG] Data channel open for', agentUuid);
            peer.dataChannel.send(JSON.stringify({ type: 'identity_request' }));
            peer.dataChannel.send(JSON.stringify({
                type: 'set_quality',
                max_bitrate_kbps: EXTQLI_STREAM_QUALITY.maxBitrateKbps,
                frame_rate: EXTQLI_STREAM_QUALITY.frameRateCap
            }));
            peer.dataChannel.send(JSON.stringify({ type: 'resume_video' }));
        };

        peer.dataChannel.onclose = function () {
            console.log('[DEBUG] Data channel closed for', agentUuid);
        };

        peer.dataChannel.onerror = function (err) {
            console.error('[DEBUG] Data channel error for', agentUuid, err);
        };

        peer.dataChannel.onmessage = function (event) {
            handleAgentDataChannelMessage(agentUuid, event);
        };

        var offer = await peer.pc.createOffer();
        await peer.pc.setLocalDescription(offer);

        await new Promise(function (resolve) {
            if (peer.pc.iceGatheringState === 'complete') {
                resolve();
                return;
            }

            peer.pc.onicegatheringstatechange = function () {
                if (peer.pc.iceGatheringState === 'complete') {
                    console.log('[DEBUG] ICE gathered');
                    resolve();
                }
            };

            setTimeout(function () {
                console.warn('[DEBUG] ICE gather timeout, proceeding');
                resolve();
            }, 15000);
        });

        var mutatedSdp = mutateSdpForQuality(peer.pc.localDescription.sdp, EXTQLI_STREAM_QUALITY.maxBitrateKbps, EXTQLI_STREAM_QUALITY.frameRateCap);

        var response = await fetch(SIGNALING_URL + '?action=submit_offer&agent_uuid=' + encodeURIComponent(agentUuid), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                viewer_id: VIEWER_ID,
                offer_sdp: mutatedSdp
            })
        });

        var data = await response.json();
        if (!data.success) {
            throw new Error('Failed to submit offer: ' + (data.message || 'unknown'));
        }

        console.log('[DEBUG] Offer submitted, polling for answer...');

        peer.answerPollInterval = setInterval(async function () {
            if (!peer.pc) {
                clearInterval(peer.answerPollInterval);
                return;
            }

            try {
                var pollResp = await fetch(SIGNALING_URL + '?action=poll_answer&viewer_id=' + VIEWER_ID);
                var pollData = await pollResp.json();

                if (pollData.has_answer) {
                    clearInterval(peer.answerPollInterval);
                    peer.answerPollInterval = null;

                    var answer = new RTCSessionDescription({
                        type: 'answer',
                        sdp: pollData.answer_sdp
                    });

                    await peer.pc.setRemoteDescription(answer);
                    console.log('[DEBUG] Remote description set for', agentUuid);
                } else {
                    console.log('[DEBUG] No answer yet...');
                }
            } catch (err) {
                console.error('[DEBUG] Answer poll error:', err);
            }
        }, 2000);

        return peer;
    }

    function processAutoConnectQueue() {
        if (!EXTQLI_AUTO_CONNECT_ALL) return;
        if (autoConnectActive) return;
        if (autoConnectQueue.length === 0) return;

        autoConnectActive = true;

        var agentUuid = autoConnectQueue.shift();

        if (extqliViewerPeers[agentUuid] && isConnectionAlive(agentUuid)) {
            autoConnectActive = false;
            setTimeout(processAutoConnectQueue, 1000);
            return;
        }

        createWebRTCOffer(agentUuid).catch(function (err) {
            console.warn('Auto-connect failed for', agentUuid, err);
        }).finally(function () {
            autoConnectActive = false;
            setTimeout(processAutoConnectQueue, 2000);
        });
    }

    function autoConnectAgents() {
        if (!EXTQLI_AUTO_CONNECT_ALL) return;

        var onlineAgents = extqliAgentsList.filter(function (a) {
            return a.is_online && !extqliViewerPeers[a.agent_uuid];
        });

        onlineAgents.forEach(function (agent) {
            if (!autoConnectQueue.includes(agent.agent_uuid)) {
                autoConnectQueue.push(agent.agent_uuid);
            }
        });

        processAutoConnectQueue();
    }

    function removeAgent(agentUuid) {
        fetch(BASE_PATH + '/backend/api/delete_agent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shared_token: 'extqli_@2026token$$',
                agent_uuid: agentUuid
            })
        }).then(function (response) {
            return response.json();
        }).catch(function (err) {
            console.warn('Delete error:', err);
        });

        extqliAgentsList = extqliAgentsList.filter(function (a) {
            return a.agent_uuid !== agentUuid;
        });

        if (extqliViewerPeers[agentUuid]) {
            try {
                if (extqliViewerPeers[agentUuid].answerPollInterval) clearInterval(extqliViewerPeers[agentUuid].answerPollInterval);
            } catch (err) {}

            try {
                extqliViewerPeers[agentUuid].pc.close();
            } catch (err) {}

            clearPreviewVideoStream(agentUuid);
            delete extqliViewerPeers[agentUuid];
        }

        renderExtQliSidebar(extqliAgentsList);
        renderExtQliGrid(extqliAgentsList);
        populateScreenAgentOptions(extqliAgentsList);
        updateLiveCounts();

        if (extqliCurrentScreenAgentUuid === agentUuid) {
            clearRemoteTarget();
        }
    }

    function renderExtQliSidebar(list) {
        var container = byId('extqliClientList');
        if (!container) return;

        if (!list.length) {
            container.innerHTML = '<div class="extqli-empty">No agents connected.</div>';
            return;
        }

        container.innerHTML = list.map(function (a) {
            var isOnline = !!a.is_online;
            var userDisplay = getAgentDisplayName(a);
            var hostnameDisplay = getAgentHostname(a);

            return '<div class="extqli-agent-card ' + (isOnline ? 'is-online' : 'is-offline') + '" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                '<button class="card-close-btn" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" title="Remove from list">×</button>' +
                '<div class="extqli-card-header"><div class="extqli-card-title" title="Logged-in PC username">' + escapeHtml(userDisplay) + '</div><span class="extqli-status-badge ' + (isOnline ? 'is-online' : 'is-offline') + '">' + (isOnline ? '● ONLINE' : 'OFFLINE') + '</span></div>' +
                '<div class="extqli-card-id">ID: ' + escapeHtml(a.agent_uuid || '-') + '</div>' +
                '<div class="extqli-card-meta">IP: ' + escapeHtml(a.local_ip || '-') + '</div>' +
                '<div class="extqli-card-meta extqli-card-user">User: ' + escapeHtml(hostnameDisplay) + '</div>' +
                '<div class="extqli-card-meta">OS: ' + escapeHtml(a.os_name || '-') + '</div>' +
                '<div class="extqli-card-meta">Last Seen: ' + escapeHtml(a.last_seen || '-') + '</div>' +
                '<div class="extqli-card-actions"><button class="btn btn-dark btn-sm" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button>' +
                '<button class="btn btn-success btn-sm" onclick="openRemoteScreen(\'' + escapeHtml(a.agent_uuid) + '\')"' + (isOnline ? '' : ' disabled') + '>Remote Screen</button></div></div>';
        }).join('');
    }

    function renderExtQliGrid(list) {
        var container = byId('extqliMonitorGrid');
        if (!container) return;

        if (!list.length) {
            container.innerHTML = '<div class="extqli-empty">No agents to display.</div>';
            return;
        }

        container.innerHTML = list.map(function (a) {
            var isOnline = !!a.is_online;
            var userDisplay = getAgentDisplayName(a);
            var hostnameDisplay = getAgentHostname(a);
            var hasStream = extqliViewerPeers[a.agent_uuid] && extqliViewerPeers[a.agent_uuid].stream;
            var videoDisplayStyle = (isOnline && hasStream) ? 'block' : 'none';
            var overlayDisplayStyle = (isOnline && hasStream) ? 'none' : 'flex';
            var overlayText = isOnline ? 'Connecting...' : 'Agent offline';

            return '<div class="extqli-monitor-card ' + (isOnline ? 'is-online' : 'is-offline') + '" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                '<button class="card-close-btn" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" title="Remove from list">×</button>' +
                '<div class="extqli-monitor-header"><div class="extqli-monitor-info"><div class="extqli-card-title" title="Logged-in PC username">' + escapeHtml(userDisplay) + '</div><div class="extqli-card-ip">IP: ' + escapeHtml(a.local_ip || '-') + '</div><div class="extqli-card-user">User: ' + escapeHtml(hostnameDisplay) + '</div></div>' +
                (isOnline ? '<span class="extqli-live-badge">● LIVE</span>' : '<span class="extqli-offline-badge">OFFLINE</span>') + '</div>' +
                '<div class="extqli-preview-wrapper" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                '<video class="extqli-preview-video" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" autoplay playsinline muted style="display:' + videoDisplayStyle + '; width:100%; height:100%; object-fit:cover; background:#000;"></video>' +
                '<div class="extqli-preview-overlay" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" style="display:' + overlayDisplayStyle + '; align-items:center; justify-content:center; width:100%; height:100%;">' + overlayText + '</div></div>' +
                '<div class="extqli-monitor-actions"><button class="btn btn-dark btn-sm" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button>' +
                '<button class="btn btn-success btn-sm" onclick="openRemoteScreen(\'' + escapeHtml(a.agent_uuid) + '\')"' + (isOnline ? '' : ' disabled') + '>Remote Screen</button></div></div>';
        }).join('');

        setTimeout(reattachAllPreviewStreams, 50);
    }

    function populateScreenAgentOptions(list) {
        var select = byId('screenAgentSelect');
        if (!select) return;

        var current = extqliCurrentScreenAgentUuid || select.value || '';
        var options = ['<option value="">Select an agent</option>'];

        list.forEach(function (agent) {
            var value = String(agent.agent_uuid || '');
            var label = getAgentDisplayName(agent) + ' (' + (agent.local_ip || '-') + ')';

            options.push('<option value="' + escapeHtml(value) + '"' + (current === value ? ' selected' : '') + '>' + escapeHtml(label) + '</option>');
        });

        select.innerHTML = options.join('');
    }

    function renderAgents(list) {
        extqliAgentsList = Array.isArray(list) ? list : [];

        extqliAgentsList.forEach(function (agent) {
            var agentUuid = String(agent.agent_uuid || '');
            var cachedUsername = normalizeDisplayValue(extqliIdentityByAgentUuid[agentUuid]);

            if (cachedUsername) {
                setAgentIdentityOnModel(agentUuid, cachedUsername, agent);
            }
        });

        updateLiveCounts();
        renderExtQliSidebar(extqliAgentsList);
        renderExtQliGrid(extqliAgentsList);
        populateScreenAgentOptions(extqliAgentsList);
        autoConnectAgents();
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
                if (!data.success) throw new Error(data.message || 'Failed to load agents.');
                renderAgents(data.data || []);
            })
            .catch(function (err) {
                setAgentStatus('Agent load error: ' + err.message);
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
        }).then(function (res) {
            return res.json();
        }).then(function () {
            loadAgents();
        }).catch(function (err) {
            console.error('Task send error:', err);
        });
    }

    function setMainVideoToAgentStream(agentUuid) {
        var peer = extqliViewerPeers[agentUuid];
        var mainVideo = byId('remoteScreenVideo');

        if (!mainVideo) return false;

        if (peer && peer.stream) {
            mainVideo.srcObject = peer.stream;
            mainVideo.muted = true;
            mainVideo.autoplay = true;
            mainVideo.playsInline = true;
            mainVideo.style.display = 'block';

            mainVideo.play().catch(function (e) {
                console.warn('Main video play error:', e);
            });

            showScreenEmptyState(false);
            return true;
        }

        return false;
    }

    function stopScreenView() {
        console.log('[DEBUG] stopScreenView — pausing feed, keeping connection for:', extqliCurrentScreenAgentUuid);

        var peer = activeViewerPeer();

        if (peer && peer.dataChannel && peer.dataChannel.readyState === 'open') {
            peer.dataChannel.send(JSON.stringify({ type: 'pause_video' }));
        }

        var videoEl = byId('remoteScreenVideo');

        if (videoEl) {
            try {
                videoEl.pause();
            } catch (err) {}

            videoEl.style.display = 'none';
            videoEl.srcObject = null;
        }

        extqliIsScreenViewing = false;
        extqliRemoteControlEnabled = false;

        var remoteToggle = byId('remoteControlToggle');
        if (remoteToggle) remoteToggle.checked = false;

        updateRemoteOverlay();
        setScreenStatus('Feed paused — connection kept alive. Click Start Viewing to resume instantly.');
        showScreenEmptyState(true);
    }

    function startScreenView() {
        console.log('[DEBUG] startScreenView called');

        var select = byId('screenAgentSelect');
        var agentUuid = select ? select.value : '';

        if (!agentUuid) {
            setScreenStatus('Select an agent first.');
            return;
        }

        extqliCurrentScreenAgentUuid = agentUuid;

        if (setMainVideoToAgentStream(agentUuid)) {
            extqliIsScreenViewing = true;
            setScreenStatus('Streaming active');
            showScreenEmptyState(false);

            var peer = activeViewerPeer();
            if (peer && peer.dataChannel && peer.dataChannel.readyState === 'open') {
                peer.dataChannel.send(JSON.stringify({ type: 'resume_video' }));
            }

            return;
        }

        extqliIsScreenViewing = true;
        setScreenStatus('Starting WebRTC stream...');

        createWebRTCOffer(agentUuid).then(function (peer) {
            var checkInterval = setInterval(function () {
                if (peer.stream && byId('remoteScreenVideo').srcObject !== peer.stream) {
                    setMainVideoToAgentStream(agentUuid);
                    clearInterval(checkInterval);
                } else if (peer.pc && peer.pc.connectionState === 'failed') {
                    clearInterval(checkInterval);
                }
            }, 500);

            setTimeout(function () {
                clearInterval(checkInterval);
            }, 10000);
        }).catch(function (err) {
            console.error('[DEBUG] createWebRTCOffer error:', err);
            setScreenStatus('WebRTC start failed: ' + err.message);
            extqliIsScreenViewing = false;
            showScreenEmptyState(true);
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
        if (overlay) overlay.style.display = (extqliIsScreenViewing && extqliRemoteControlEnabled) ? 'block' : 'none';
    }

    function sendInput(msg) {
        var peer = activeViewerPeer();

        if (!extqliRemoteControlEnabled || !peer || !peer.dataChannel || peer.dataChannel.readyState !== 'open') return;

        peer.dataChannel.send(JSON.stringify(msg));
    }

    function initRemoteControl() {
        var canvas = byId('remoteControlOverlay');
        if (!canvas) return;

        function getScaledCoords(clientX, clientY) {
            var rect = canvas.getBoundingClientRect();
            var scaleX = extqliRemoteScreenWidth / rect.width;
            var scaleY = extqliRemoteScreenHeight / rect.height;

            return {
                x: Math.max(0, Math.min(extqliRemoteScreenWidth, (clientX - rect.left) * scaleX)),
                y: Math.max(0, Math.min(extqliRemoteScreenHeight, (clientY - rect.top) * scaleY))
            };
        }

        canvas.addEventListener('mousemove', function (e) {
            if (!extqliRemoteControlEnabled) return;

            var now = Date.now();
            if (now - extqliLastMouseMoveTime < EXTQLI_MOUSE_MOVE_INTERVAL_MS) return;

            extqliLastMouseMoveTime = now;

            var c = getScaledCoords(e.clientX, e.clientY);

            sendInput({
                event_type: 'mouse_move',
                x: Math.round(c.x),
                y: Math.round(c.y)
            });
        });

        canvas.addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (!extqliRemoteControlEnabled) return;

            sendInput({
                event_type: 'mouse_click',
                button: e.button === 0 ? 'left' : e.button === 2 ? 'right' : 'middle',
                pressed: true
            });
        });

        canvas.addEventListener('mouseup', function (e) {
            e.preventDefault();
            if (!extqliRemoteControlEnabled) return;

            sendInput({
                event_type: 'mouse_click',
                button: e.button === 0 ? 'left' : e.button === 2 ? 'right' : 'middle',
                pressed: false
            });
        });

        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            if (!extqliRemoteControlEnabled) return;

            sendInput({
                event_type: 'mouse_scroll',
                delta: e.deltaY > 0 ? -3 : 3
            });
        });

        canvas.addEventListener('contextmenu', function (e) {
            e.preventDefault();
        });

        var KEY_MAP = {
            ' ': 'space',
            'Enter': 'enter',
            'Control': 'ctrl',
            'Alt': 'alt',
            'Shift': 'shift',
            'ArrowUp': 'up',
            'ArrowDown': 'down',
            'ArrowLeft': 'left',
            'ArrowRight': 'right'
        };

        function mapKey(k) {
            return KEY_MAP[k] || k;
        }

        window.addEventListener('keydown', function (e) {
            if (!extqliRemoteControlEnabled || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();

            sendInput({
                event_type: 'key',
                key: mapKey(e.key),
                pressed: true
            });
        });

        window.addEventListener('keyup', function (e) {
            if (!extqliRemoteControlEnabled || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();

            sendInput({
                event_type: 'key',
                key: mapKey(e.key),
                pressed: false
            });
        });
    }

    function updateRemoteTargetDisplay(agentUuid, isActiveRemote) {
        var agent = extqliAgentsList.find(function (a) {
            return a.agent_uuid === agentUuid;
        });

        var targetName = (agent ? getAgentDisplayName(agent) : agentUuid) || 'None';
        var targetNameEl = byId('remoteTargetName');

        if (targetNameEl) targetNameEl.textContent = targetName;

        var remoteBar = byId('extqliRemoteStatus');
        if (!remoteBar) return;

        var textEl = remoteBar.querySelector('.extqli-remote-bar-text');

        if (isActiveRemote && agentUuid) {
            remoteBar.classList.add('is-active');
            if (textEl) {
                textEl.innerHTML = '<strong>Remote target: ' + escapeHtml(targetName) + '</strong><span>Control input is active — switch to the Screen Viewer tab to interact.</span>';
            }
        } else {
            remoteBar.classList.remove('is-active');
            if (textEl) {
                textEl.innerHTML = '<strong>Remote control is idle.</strong><span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>';
            }
        }
    }

    function clearRemoteTarget() {
        if (extqliIsScreenViewing) stopScreenView();

        extqliCurrentScreenAgentUuid = '';
        extqliRemoteControlEnabled = false;

        var remoteToggle = byId('remoteControlToggle');
        if (remoteToggle) remoteToggle.checked = false;

        updateRemoteOverlay();

        var targetNameEl = byId('remoteTargetName');
        if (targetNameEl) targetNameEl.textContent = 'None';

        var remoteBar = byId('extqliRemoteStatus');

        if (remoteBar) {
            remoteBar.classList.remove('is-active');
            var textEl = remoteBar.querySelector('.extqli-remote-bar-text');

            if (textEl) {
                textEl.innerHTML = '<strong>Remote control is idle.</strong><span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>';
            }
        }
    }

    function activateAgentAdminTab(tabName) {
        var validTabs = {
            agents: 'agentAdminTabAgents',
            screen: 'agentAdminTabScreen'
        };

        extqliCurrentAdminTab = validTabs[tabName] ? tabName : 'agents';

        Object.keys(validTabs).forEach(function (key) {
            var panel = byId(validTabs[key]);
            if (panel) panel.hidden = key !== extqliCurrentAdminTab;
        });

        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-agent-admin-tab-trigger') === extqliCurrentAdminTab);
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
            activateAgentAdminTab(agentAdminTab || extqliCurrentAdminTab || 'agents');
        }
    }

    function openScreenViewer(agentUuid) {
        activateView('agentsView', 'screen');
        extqliCurrentScreenAgentUuid = agentUuid || '';

        var select = byId('screenAgentSelect');
        if (select && agentUuid) select.value = agentUuid;

        updateRemoteTargetDisplay(agentUuid, false);

        if (isConnectionAlive(agentUuid)) {
            setScreenStatus('Live connection ready — click Start Viewing to resume instantly.');
        } else {
            setScreenStatus('Selected agent. Click Start Viewing.');
        }
    }

    function openRemoteScreen(agentUuid) {
        activateView('agentsView', 'screen');
        extqliCurrentScreenAgentUuid = agentUuid || '';

        var select = byId('screenAgentSelect');
        if (select && agentUuid) select.value = agentUuid;

        extqliRemoteControlEnabled = true;

        var remoteToggle = byId('remoteControlToggle');
        if (remoteToggle) remoteToggle.checked = true;

        updateRemoteOverlay();
        updateRemoteTargetDisplay(agentUuid, true);

        if (isConnectionAlive(agentUuid)) {
            setMainVideoToAgentStream(agentUuid);
            extqliIsScreenViewing = true;
            setScreenStatus('Streaming active (resumed) — Remote Control ON');
            showScreenEmptyState(false);

            var peer = activeViewerPeer();
            if (peer && peer.dataChannel && peer.dataChannel.readyState === 'open') {
                peer.dataChannel.send(JSON.stringify({ type: 'resume_video' }));
            }
        } else {
            extqliIsScreenViewing = true;
            setScreenStatus('Starting WebRTC stream with Remote Control.');

            createWebRTCOffer(agentUuid).then(function (peer) {
                var checkInterval = setInterval(function () {
                    if (peer.stream && byId('remoteScreenVideo').srcObject !== peer.stream) {
                        setMainVideoToAgentStream(agentUuid);
                        clearInterval(checkInterval);
                    } else if (peer.pc && peer.pc.connectionState === 'failed') {
                        clearInterval(checkInterval);
                    }
                }, 500);

                setTimeout(function () {
                    clearInterval(checkInterval);
                }, 10000);
            }).catch(function (err) {
                console.error('[DEBUG] createWebRTCOffer error:', err);
                setScreenStatus('WebRTC start failed: ' + err.message);
                extqliIsScreenViewing = false;
                showScreenEmptyState(true);
            });
        }
    }

    function bindEvents() {
        var screenSelect = byId('screenAgentSelect');
        var refreshViewerAgentsBtn = byId('refreshViewerAgentsBtn');
        var startScreenViewBtn = byId('startScreenViewBtn');
        var stopScreenViewBtn = byId('stopScreenViewBtn');
        var fullscreenBtn = byId('fullscreenBtn');
        var remoteToggle = byId('remoteControlToggle');
        var clearRemoteTargetBtn = byId('clearRemoteTargetBtn');

        qsa('[data-agent-admin-tab-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateAgentAdminTab(btn.getAttribute('data-agent-admin-tab-trigger'));
            });
        });

        if (screenSelect) {
            screenSelect.addEventListener('change', function () {
                if (extqliIsScreenViewing) stopScreenView();

                extqliCurrentScreenAgentUuid = screenSelect.value || '';
                extqliRemoteControlEnabled = false;

                if (remoteToggle) remoteToggle.checked = false;

                updateRemoteOverlay();

                if (extqliCurrentScreenAgentUuid) {
                    if (isConnectionAlive(extqliCurrentScreenAgentUuid)) {
                        setScreenStatus('Agent selected — live connection ready. Click Start Viewing to resume instantly.');
                    } else {
                        setScreenStatus('Agent selected. Click Start Viewing.');
                    }
                } else {
                    setScreenStatus('Waiting for a selected agent.');
                }
            });
        }

        if (refreshViewerAgentsBtn) refreshViewerAgentsBtn.onclick = loadAgents;
        if (startScreenViewBtn) startScreenViewBtn.onclick = startScreenView;
        if (stopScreenViewBtn) stopScreenViewBtn.onclick = stopScreenView;
        if (fullscreenBtn) fullscreenBtn.onclick = toggleFullscreen;
        if (clearRemoteTargetBtn) clearRemoteTargetBtn.onclick = clearRemoteTarget;

        if (remoteToggle) {
            remoteToggle.addEventListener('change', function () {
                extqliRemoteControlEnabled = remoteToggle.checked;
                updateRemoteOverlay();
                setScreenStatus((extqliIsScreenViewing ? 'Screen active. ' : 'Start viewing first. ') + 'Remote control ' + (extqliRemoteControlEnabled ? 'ON' : 'OFF'));
            });
        }

        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('.card-close-btn');

            if (btn && btn.getAttribute('data-agent-uuid')) {
                var uuid = btn.getAttribute('data-agent-uuid');
                removeAgent(uuid);
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    function bindNavigation() {
        qsa('[data-view-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateView(btn.getAttribute('data-view-target'), btn.getAttribute('data-agent-admin-tab') || '');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        console.log('[DEBUG] DOMContentLoaded');

        bindNavigation();
        bindEvents();
        initRemoteControl();

        setScreenStatus('Waiting for a selected agent.');
        activateView('agentsView');
        activateAgentAdminTab('agents');

        loadAgents();
        showScreenEmptyState(true);

        extqliAgentPollTimer = setInterval(loadAgents, 5000);

        hidePageLoader();
    });

    window.addEventListener('load', hidePageLoader);
    setTimeout(hidePageLoader, 1500);

    window.sendAgentTask = sendAgentTask;
    window.openScreenViewer = openScreenViewer;
    window.openRemoteScreen = openRemoteScreen;
})();