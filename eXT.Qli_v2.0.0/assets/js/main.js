import { emit } from './modules/events.js';
import { appState } from './modules/store.js';

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
    var extqliConnectionPromises = {};

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
    var EXTQLI_ICE_GATHER_TIMEOUT_MS = 2500;
    var EXTQLI_ANSWER_POLL_INTERVAL_MS = 350;
    var EXTQLI_ANSWER_POLL_TIMEOUT_MS = 18000;
    var EXTQLI_AUTOCONNECT_DELAY_MS = 350;

    var autoConnectQueue = [];
    var autoConnectActive = false;

    function byId(id) {
        return document.getElementById(id);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
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

        if (
            lowered === 'unknown' ||
            lowered === 'none' ||
            lowered === 'null' ||
            lowered === '-' ||
            lowered === 'username unavailable' ||
            lowered === 'fetching username...' ||
            lowered === 'waiting for username...'
        ) {
            return '';
        }

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

        if (payload && payload.hostname) {
            agent.hostname = payload.hostname;
        }
    }

    function persistAgentIdentity(agentUuid, username, payload) {
        agentUuid = String(agentUuid || '').trim();
        username = normalizeDisplayValue(username);

        if (!agentUuid || !username) return;

        var alreadyPersisted = extqliPersistedIdentityByAgentUuid[agentUuid];

        if (alreadyPersisted === username) return;

        var inProgress = extqliPersistingIdentityByAgentUuid[agentUuid];

        if (inProgress === username) return;

        extqliPersistingIdentityByAgentUuid[agentUuid] = username;

        fetch(BASE_PATH + '/backend/api/save_agent_identity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                shared_token: 'extqli_@2026token$$',
                agent_uuid: agentUuid,
                username: username,
                logged_in_username: username,
                endpoint_username_output: username,
                username_stdout: username,
                source: 'webrtc_data_channel',
                identity_payload: payload || {}
            })
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Username save failed.');
                }

                extqliPersistedIdentityByAgentUuid[agentUuid] = username;
                console.log('[DEBUG] Agent username stored in MySQL:', agentUuid, username);
            })
            .catch(function (err) {
                console.warn('[DEBUG] Agent username save failed:', agentUuid, err && err.message ? err.message : err);
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
            if (String(card.getAttribute('data-agent-uuid') || '') === String(agentUuid || '')) {
                cards.push(card);
            }
        });

        qsa('.extqli-monitor-card').forEach(function (card) {
            if (String(card.getAttribute('data-agent-uuid') || '') === String(agentUuid || '')) {
                cards.push(card);
            }
        });

        cards.forEach(function (card) {
            var nameNode = card.querySelector('.extqli-card-title');
            var userNode = card.querySelector('.extqli-card-user');

            if (nameNode) {
                nameNode.textContent = username;
                nameNode.removeAttribute('title');
            }

            if (userNode) {
                userNode.textContent = 'Hostname: ' + hostname;
                userNode.removeAttribute('title');
            }

            qsa('.extqli-card-meta', card).forEach(function (node) {
                node.removeAttribute('title');

                if ((node.textContent || '').trim().toLowerCase().indexOf('user:') === 0) {
                    node.textContent = 'Hostname: ' + hostname;
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

    function relayTaskResult(agentUuid, resultPayload) {
        var taskId = resultPayload && resultPayload.task_id ? resultPayload.task_id : null;

        if (!taskId) {
            console.log('[DEBUG] Task result received without task_id; not saving to DB.', resultPayload);
            return;
        }

        fetch(BASE_PATH + '/backend/api/agent_task_result.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                shared_token: 'extqli_@2026token$$',
                agent_uuid: agentUuid,
                task_id: taskId,
                result_status: resultPayload.result_status || 'success',
                output_text: resultPayload.output_text || resultPayload.result || ''
            })
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Task result save failed.');
                }

                console.log('[DEBUG] Task result stored:', agentUuid, taskId);

                emit('task:result-saved', {
                    agentUuid: agentUuid,
                    taskId: taskId
                });
            })
            .catch(function (err) {
                console.warn('[DEBUG] Task result relay failed:', err && err.message ? err.message : err);
            });
    }

    function handleAgentDataChannelMessage(agentUuid, event) {
        var raw = event && event.data;
        var data = null;

        console.log('[DEBUG] Message from agent:', raw);

        try {
            data = typeof raw === 'string' ? JSON.parse(raw) : raw;
        } catch (err) {
            return;
        }

        if (!data || typeof data !== 'object') return;

        if (data.type === 'agent_identity' || data.type === 'identity' || data.type === 'agent_info') {
            applyAgentIdentity(agentUuid, data);
            return;
        }

        if (data.type === 'task_result') {
            relayTaskResult(agentUuid, data);
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
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 350);
    }

    function setAgentStatus(message) {
        var el = byId('agentStatusBar');

        if (el) {
            el.textContent = message || 'Loading agents...';
        }
    }

    function setScreenStatus(message) {
        var el = byId('screenStatusBar');

        if (el) {
            el.textContent = message || 'Waiting for a selected agent.';
        }
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

    function activeViewerPeer() {
        return extqliCurrentScreenAgentUuid ? (extqliViewerPeers[extqliCurrentScreenAgentUuid] || null) : null;
    }

    function isConnectionAlive(agentUuid) {
        var peer = extqliViewerPeers[agentUuid];

        if (!peer || !peer.pc) return false;

        var state = peer.pc.connectionState;
        var iceState = peer.pc.iceConnectionState;

        return (
            state === 'connected' ||
            state === 'connecting' ||
            iceState === 'connected' ||
            iceState === 'completed' ||
            iceState === 'checking'
        );
    }

    function isConnectionDead(agentUuid) {
        var peer = extqliViewerPeers[agentUuid];

        if (!peer || !peer.pc) return true;

        var state = peer.pc.connectionState;
        var iceState = peer.pc.iceConnectionState;
        var signalingState = peer.pc.signalingState;

        return (
            state === 'failed' ||
            state === 'closed' ||
            state === 'disconnected' ||
            iceState === 'failed' ||
            iceState === 'closed' ||
            signalingState === 'closed'
        );
    }

    function getLiveStreamCount(list) {
        var count = 0;

        (list || []).forEach(function (agent) {
            if (!agent || !agent.is_online) return;

            if (isConnectionAlive(agent.agent_uuid)) {
                count += 1;
            }
        });

        return count;
    }

    function updateLiveCounts() {
        var onlineCount = extqliAgentsList.filter(function (a) {
            return a.is_online;
        }).length;

        var liveCount = getLiveStreamCount(extqliAgentsList);
        var onlineEl = byId('onlineClientsCount');
        var liveEl = byId('liveStreamsCount');

        if (onlineEl) onlineEl.textContent = onlineCount;
        if (liveEl) liveEl.textContent = liveCount;
    }

    function mutateSdpForQuality(sdp, maxKbps, frameRateCap) {
        var lines = String(sdp || '').split('\r\n');
        var out = [];
        var inVideo = false;
        var bInjected = false;
        var vp8Pt = null;

        lines.forEach(function (line) {
            var m = line.match(/^a=rtpmap:(\d+) VP8\/90000/i);

            if (m) {
                vp8Pt = m[1];
            }
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
                out.push(
                    currentLine +
                    ';x-google-max-bitrate=' + maxKbps +
                    ';x-google-min-bitrate=' + Math.round(maxKbps * 0.1) +
                    ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3)
                );
            }

            if (inVideo && currentLine.indexOf('a=rtpmap:') === 0 && vp8Pt && currentLine.indexOf('VP8/90000') !== -1 && frameRateCap) {
                var hasFmtp = lines.some(function (line) {
                    return line.indexOf('a=fmtp:' + vp8Pt + ' ') === 0;
                });

                if (!hasFmtp) {
                    out.push(
                        'a=fmtp:' + vp8Pt +
                        ' max-fr=' + frameRateCap +
                        ';x-google-max-bitrate=' + maxKbps +
                        ';x-google-min-bitrate=' + Math.round(maxKbps * 0.1) +
                        ';x-google-start-bitrate=' + Math.round(maxKbps * 0.3)
                    );
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
                {
                    urls: 'stun:stun.l.google.com:19302'
                },
                {
                    urls: TURN_SERVER,
                    username: TURN_USERNAME,
                    credential: TURN_CREDENTIAL
                }
            ],
            iceTransportPolicy: 'all',
            bundlePolicy: 'max-bundle',
            rtcpMuxPolicy: 'require'
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

        if (message) {
            overlay.textContent = message;
        }
    }

    function safePlayVideo(video, label) {
        if (!video) return;

        var playPromise = video.play();

        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function (e) {
                if (e && e.name === 'AbortError') return;
                console.warn(label || 'Video play error:', e);
            });
        }
    }

    function setPreviewVideoStream(agentUuid, stream, retryCount) {
        retryCount = retryCount || 0;

        var video = getGridPreviewVideo(agentUuid);

        if (!video) {
            if (retryCount < 10) {
                setTimeout(function () {
                    setPreviewVideoStream(agentUuid, stream, retryCount + 1);
                }, 120);
            }

            return;
        }

        if (video.srcObject === stream) {
            video.style.display = 'block';
            setPreviewOverlayState(agentUuid, true, '');
            return;
        }

        video.muted = true;
        video.autoplay = true;
        video.playsInline = true;
        video.style.display = 'block';
        video.srcObject = stream;

        safePlayVideo(video, 'Preview video play error for ' + agentUuid);

        setPreviewOverlayState(agentUuid, true, '');
    }

    function clearPreviewVideoStream(agentUuid, message) {
        var video = getGridPreviewVideo(agentUuid);

        if (!video) return;

        if (video.srcObject) {
            video.srcObject = null;
        }

        video.style.display = 'none';

        setPreviewOverlayState(agentUuid, false, message || 'Connecting...');
    }

    function reattachAllPreviewStreams() {
        for (var uuid in extqliViewerPeers) {
            if (Object.prototype.hasOwnProperty.call(extqliViewerPeers, uuid)) {
                var peer = extqliViewerPeers[uuid];

                if (peer && peer.stream) {
                    setPreviewVideoStream(uuid, peer.stream);
                }
            }
        }
    }

    function setMainVideoToAgentStream(agentUuid) {
        var peer = extqliViewerPeers[agentUuid];
        var mainVideo = byId('remoteScreenVideo');

        if (!mainVideo) return false;

        if (peer && peer.stream) {
            if (mainVideo.srcObject !== peer.stream) {
                mainVideo.srcObject = peer.stream;
            }

            mainVideo.muted = true;
            mainVideo.autoplay = true;
            mainVideo.playsInline = true;
            mainVideo.style.display = 'block';

            safePlayVideo(mainVideo, 'Main video play error');

            showScreenEmptyState(false);

            return true;
        }

        return false;
    }

    function attachStreamToActiveViewer(agentUuid) {
        if (agentUuid === extqliCurrentScreenAgentUuid && extqliIsScreenViewing) {
            if (setMainVideoToAgentStream(agentUuid)) {
                setScreenStatus(extqliRemoteControlEnabled ? 'Streaming active — Remote Control ON' : 'Streaming active');
                showScreenEmptyState(false);
            }
        }
    }

    function onRemoteTrackForAgent(agentUuid, event) {
        console.log('[DEBUG] ontrack for agent', agentUuid);

        var stream = (event.streams && event.streams[0]) ? event.streams[0] : new MediaStream([event.track]);
        var peer = extqliViewerPeers[agentUuid];

        if (peer) {
            peer.stream = stream;
            peer.connectionReady = true;
        }

        setPreviewVideoStream(agentUuid, stream);
        attachStreamToActiveViewer(agentUuid);
        updateLiveCounts();
    }

    function cleanupPeer(agentUuid, clearPreview, previewMessage) {
        var peer = extqliViewerPeers[agentUuid];

        if (!peer) return;

        try {
            if (peer.answerPollInterval) {
                clearInterval(peer.answerPollInterval);
            }
        } catch (err) {}

        try {
            if (peer.answerPollTimeout) {
                clearTimeout(peer.answerPollTimeout);
            }
        } catch (err) {}

        try {
            if (peer.pc) {
                peer.pc.ontrack = null;
                peer.pc.onconnectionstatechange = null;
                peer.pc.oniceconnectionstatechange = null;
                peer.pc.onicecandidate = null;
                peer.pc.close();
            }
        } catch (err) {}

        if (clearPreview) {
            clearPreviewVideoStream(agentUuid, previewMessage || 'Connecting...');
        }

        delete extqliViewerPeers[agentUuid];
        delete extqliConnectionPromises[agentUuid];

        updateLiveCounts();
    }

    function waitForIceGathering(peer) {
        return new Promise(function (resolve) {
            var done = false;
            var timer = null;

            function finish(reason) {
                if (done) return;

                done = true;

                if (timer) {
                    clearTimeout(timer);
                }

                console.log('[DEBUG] ICE gather finished:', reason);
                resolve();
            }

            if (!peer || !peer.pc) {
                finish('no peer');
                return;
            }

            if (peer.pc.iceGatheringState === 'complete') {
                finish('already complete');
                return;
            }

            peer.pc.onicegatheringstatechange = function () {
                if (!peer.pc) {
                    finish('peer closed');
                    return;
                }

                if (peer.pc.iceGatheringState === 'complete') {
                    finish('complete');
                }
            };

            timer = setTimeout(function () {
                finish('fast timeout');
            }, EXTQLI_ICE_GATHER_TIMEOUT_MS);
        });
    }

    function pollForAnswer(agentUuid, peer) {
        var startedAt = Date.now();

        if (peer.answerPollInterval) {
            clearInterval(peer.answerPollInterval);
        }

        if (peer.answerPollTimeout) {
            clearTimeout(peer.answerPollTimeout);
        }

        function stopPolling() {
            if (peer.answerPollInterval) {
                clearInterval(peer.answerPollInterval);
                peer.answerPollInterval = null;
            }

            if (peer.answerPollTimeout) {
                clearTimeout(peer.answerPollTimeout);
                peer.answerPollTimeout = null;
            }
        }

        async function pollOnce() {
            if (!peer || !peer.pc) {
                stopPolling();
                return;
            }

            if (peer.pc.signalingState === 'stable' && peer.pc.remoteDescription) {
                stopPolling();
                return;
            }

            if (Date.now() - startedAt > EXTQLI_ANSWER_POLL_TIMEOUT_MS) {
                stopPolling();
                console.warn('[DEBUG] Answer poll timeout for', agentUuid);

                if (agentUuid === extqliCurrentScreenAgentUuid) {
                    setScreenStatus('WebRTC answer timeout. Click Start Viewing again.');
                }

                return;
            }

            try {
                var pollResp = await fetch(SIGNALING_URL + '?action=poll_answer&viewer_id=' + encodeURIComponent(VIEWER_ID) + '&_=' + Date.now(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    },
                    cache: 'no-store'
                });

                var pollData = await pollResp.json();

                if (pollData && pollData.has_answer && pollData.answer_sdp) {
                    stopPolling();

                    if (!peer.pc || peer.pc.signalingState === 'closed') return;

                    var answer = new RTCSessionDescription({
                        type: 'answer',
                        sdp: pollData.answer_sdp
                    });

                    await peer.pc.setRemoteDescription(answer);

                    console.log('[DEBUG] Remote description set for', agentUuid);

                    if (agentUuid === extqliCurrentScreenAgentUuid) {
                        setScreenStatus('Answer received. Connecting media...');
                    }
                }
            } catch (err) {
                console.error('[DEBUG] Answer poll error:', err);
            }
        }

        pollOnce();
        peer.answerPollInterval = setInterval(pollOnce, EXTQLI_ANSWER_POLL_INTERVAL_MS);
        peer.answerPollTimeout = setTimeout(function () {
            stopPolling();
        }, EXTQLI_ANSWER_POLL_TIMEOUT_MS + 1000);
    }

    async function createWebRTCOffer(agentUuid) {
        agentUuid = String(agentUuid || '').trim();

        if (!agentUuid) {
            throw new Error('Missing agent UUID.');
        }

        console.log('[DEBUG] createWebRTCOffer called for agent:', agentUuid);

        var existing = extqliViewerPeers[agentUuid];

        if (existing && existing.pc) {
            if (existing.stream || isConnectionAlive(agentUuid)) {
                console.log('[DEBUG] Connection already alive for', agentUuid);
                return existing;
            }

            if (existing.creating) {
                console.log('[DEBUG] Connection is already being created for', agentUuid);
                return extqliConnectionPromises[agentUuid] || Promise.resolve(existing);
            }

            if (isConnectionDead(agentUuid)) {
                cleanupPeer(agentUuid, true, 'Reconnecting...');
            }
        }

        if (extqliConnectionPromises[agentUuid]) {
            return extqliConnectionPromises[agentUuid];
        }

        extqliConnectionPromises[agentUuid] = (async function () {
            var peer = {
                pc: null,
                dataChannel: null,
                answerPollInterval: null,
                answerPollTimeout: null,
                connectionReady: false,
                creating: true,
                stream: null
            };

            extqliViewerPeers[agentUuid] = peer;
            setPreviewOverlayState(agentUuid, false, 'Connecting...');

            peer.pc = new RTCPeerConnection(buildRtcConfiguration());

            peer.pc.onconnectionstatechange = function () {
                if (!peer.pc) return;

                console.log('[DEBUG] pc.connectionState =', peer.pc.connectionState, 'for', agentUuid);

                if (peer.pc.connectionState === 'connected') {
                    peer.connectionReady = true;
                    peer.creating = false;
                    updateLiveCounts();
                    attachStreamToActiveViewer(agentUuid);
                }

                if (peer.pc.connectionState === 'failed') {
                    peer.connectionReady = false;
                    peer.creating = false;
                    updateLiveCounts();

                    if (agentUuid === extqliCurrentScreenAgentUuid) {
                        setScreenStatus('Connection failed. Click Start Viewing again.');
                        showScreenEmptyState(true);
                    }

                    clearPreviewVideoStream(agentUuid, 'Connection failed');
                }

                if (peer.pc.connectionState === 'disconnected') {
                    peer.connectionReady = false;
                    updateLiveCounts();

                    if (agentUuid === extqliCurrentScreenAgentUuid) {
                        setScreenStatus('Connection interrupted. Reconnecting if endpoint is still online...');
                    }
                }
            };

            peer.pc.oniceconnectionstatechange = function () {
                if (!peer.pc) return;

                console.log('[DEBUG] pc.iceConnectionState =', peer.pc.iceConnectionState, 'for', agentUuid);

                if (peer.pc.iceConnectionState === 'connected' || peer.pc.iceConnectionState === 'completed') {
                    peer.connectionReady = true;
                    peer.creating = false;
                    updateLiveCounts();
                    attachStreamToActiveViewer(agentUuid);
                }

                if (peer.pc.iceConnectionState === 'failed') {
                    peer.connectionReady = false;
                    peer.creating = false;
                    updateLiveCounts();

                    if (agentUuid === extqliCurrentScreenAgentUuid) {
                        setScreenStatus('ICE failed. Click Start Viewing again.');
                        showScreenEmptyState(true);
                    }

                    clearPreviewVideoStream(agentUuid, 'ICE failed');
                }
            };

            peer.pc.onicecandidate = function (event) {
                if (event.candidate) {
                    console.log('[DEBUG] ICE candidate:', event.candidate.candidate);
                } else {
                    console.log('[DEBUG] ICE gathering completed');
                }
            };

            peer.pc.addTransceiver('video', {
                direction: 'recvonly'
            });

            peer.pc.ontrack = function (event) {
                onRemoteTrackForAgent(agentUuid, event);
            };

            peer.dataChannel = peer.pc.createDataChannel('controlChannel', {
                ordered: true
            });

            peer.dataChannel.onopen = function () {
                console.log('[DEBUG] Data channel open for', agentUuid);

                try {
                    peer.dataChannel.send(JSON.stringify({
                        type: 'identity_request'
                    }));

                    peer.dataChannel.send(JSON.stringify({
                        type: 'set_quality',
                        max_bitrate_kbps: EXTQLI_STREAM_QUALITY.maxBitrateKbps,
                        frame_rate: EXTQLI_STREAM_QUALITY.frameRateCap
                    }));

                    peer.dataChannel.send(JSON.stringify({
                        type: 'resume_video'
                    }));
                } catch (err) {
                    console.warn('[DEBUG] Data channel send failed for', agentUuid, err);
                }
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

            var offer = await peer.pc.createOffer({
                offerToReceiveVideo: true,
                offerToReceiveAudio: false
            });

            await peer.pc.setLocalDescription(offer);
            await waitForIceGathering(peer);

            if (!peer.pc || !peer.pc.localDescription) {
                throw new Error('Failed to create local WebRTC description.');
            }

            var mutatedSdp = mutateSdpForQuality(
                peer.pc.localDescription.sdp,
                EXTQLI_STREAM_QUALITY.maxBitrateKbps,
                EXTQLI_STREAM_QUALITY.frameRateCap
            );

            var response = await fetch(SIGNALING_URL + '?action=submit_offer&agent_uuid=' + encodeURIComponent(agentUuid) + '&_=' + Date.now(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                cache: 'no-store',
                body: JSON.stringify({
                    viewer_id: VIEWER_ID,
                    offer_sdp: mutatedSdp
                })
            });

            var data = await response.json();

            if (!data || !data.success) {
                throw new Error('Failed to submit offer: ' + ((data && data.message) || 'unknown'));
            }

            console.log('[DEBUG] Offer submitted, fast polling for answer...');
            pollForAnswer(agentUuid, peer);

            peer.creating = false;

            return peer;
        })()
            .catch(function (err) {
                console.error('[DEBUG] createWebRTCOffer failed for', agentUuid, err);
                cleanupPeer(agentUuid, true, 'Connect failed');
                throw err;
            })
            .finally(function () {
                delete extqliConnectionPromises[agentUuid];
            });

        return extqliConnectionPromises[agentUuid];
    }

    function processAutoConnectQueue() {
        if (!EXTQLI_AUTO_CONNECT_ALL) return;
        if (autoConnectActive) return;
        if (autoConnectQueue.length === 0) return;

        autoConnectActive = true;

        var agentUuid = autoConnectQueue.shift();

        if (extqliViewerPeers[agentUuid] && isConnectionAlive(agentUuid)) {
            autoConnectActive = false;
            setTimeout(processAutoConnectQueue, EXTQLI_AUTOCONNECT_DELAY_MS);
            return;
        }

        createWebRTCOffer(agentUuid)
            .catch(function (err) {
                console.warn('Auto-connect failed for', agentUuid, err);
            })
            .finally(function () {
                autoConnectActive = false;
                setTimeout(processAutoConnectQueue, EXTQLI_AUTOCONNECT_DELAY_MS);
            });
    }

    function autoConnectAgents() {
        if (!EXTQLI_AUTO_CONNECT_ALL) return;

        var onlineAgents = extqliAgentsList.filter(function (a) {
            return a && a.is_online && a.agent_uuid && !extqliViewerPeers[a.agent_uuid] && !extqliConnectionPromises[a.agent_uuid];
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
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                shared_token: 'extqli_@2026token$$',
                agent_uuid: agentUuid
            })
        })
            .then(function (response) {
                return response.json();
            })
            .catch(function (err) {
                console.warn('Delete error:', err);
            });

        extqliAgentsList = extqliAgentsList.filter(function (a) {
            return a.agent_uuid !== agentUuid;
        });

        cleanupPeer(agentUuid, true, 'Connecting...');

        renderExtQliSidebar(extqliAgentsList);
        renderExtQliGrid(extqliAgentsList);
        populateScreenAgentOptions(extqliAgentsList);
        updateLiveCounts();

        if (extqliCurrentScreenAgentUuid === agentUuid) {
            clearRemoteTarget();
        }
    }

    function buildSidebarCardHtml(a) {
        var isOnline = !!a.is_online;
        var userDisplay = getAgentDisplayName(a);
        var hostnameDisplay = getAgentHostname(a);
        var badgeClass = isOnline ? 'extqli-live-badge' : 'extqli-offline-badge';
        var badgeText = isOnline ? 'ONLINE' : 'OFFLINE';

        return '' +
            '<div class="extqli-agent-card ' + (isOnline ? 'is-online' : 'is-offline') + '" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                '<div class="extqli-card-header">' +
                    '<div class="extqli-card-title">' + escapeHtml(userDisplay) + '</div>' +
                    '<div class="extqli-card-status">' +
                        '<span class="extqli-status-badge ' + badgeClass + '">' + badgeText + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="extqli-card-id">ID: ' + escapeHtml(a.agent_uuid || '-') + '</div>' +
                '<div class="extqli-card-meta">IP: ' + escapeHtml(a.local_ip || '-') + '</div>' +
                '<div class="extqli-card-meta extqli-card-user">Hostname: ' + escapeHtml(hostnameDisplay) + '</div>' +
                '<div class="extqli-card-meta">OS: ' + escapeHtml(a.os_name || '-') + '</div>' +
                '<div class="extqli-card-meta">Last Seen: ' + escapeHtml(a.last_seen || '-') + '</div>' +
                '<div class="extqli-card-actions">' +
                    '<button class="btn btn-dark btn-sm" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button>' +
                    '<button class="btn btn-success btn-sm" onclick="openRemoteScreen(\'' + escapeHtml(a.agent_uuid) + '\')"' + (isOnline ? '' : ' disabled') + '>Remote Screen</button>' +
                '</div>' +
            '</div>';
    }

    function renderExtQliSidebar(list) {
        var container = byId('extqliClientList');

        if (!container) return;

        if (!list.length) {
            container.innerHTML = '<div class="extqli-empty">No agents connected.</div>';
            return;
        }

        container.innerHTML = list.map(buildSidebarCardHtml).join('');
    }

    function buildMonitorCardHtml(a) {
        var isOnline = !!a.is_online;
        var userDisplay = getAgentDisplayName(a);
        var hostnameDisplay = getAgentHostname(a);
        var badgeClass = isOnline ? 'extqli-live-badge' : 'extqli-offline-badge';
        var badgeText = isOnline ? 'ONLINE' : 'OFFLINE';
        var hasStream = extqliViewerPeers[a.agent_uuid] && extqliViewerPeers[a.agent_uuid].stream;
        var videoDisplayStyle = (isOnline && hasStream) ? 'block' : 'none';
        var overlayDisplayStyle = (isOnline && hasStream) ? 'none' : 'flex';
        var overlayText = isOnline ? 'Connecting...' : 'Agent offline';

        return '' +
            '<div class="extqli-monitor-card ' + (isOnline ? 'is-online' : 'is-offline') + '" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                '<div class="extqli-monitor-header">' +
                    '<div class="extqli-monitor-info">' +
                        '<div class="extqli-card-title" data-card-field="title">' + escapeHtml(userDisplay) + '</div>' +
                        '<div class="extqli-card-ip" data-card-field="ip">IP: ' + escapeHtml(a.local_ip || '-') + '</div>' +
                        '<div class="extqli-card-user" data-card-field="hostname">Hostname: ' + escapeHtml(hostnameDisplay) + '</div>' +
                    '</div>' +
                    '<div class="extqli-card-status">' +
                        '<span class="extqli-status-badge ' + badgeClass + '" data-card-field="badge">' + badgeText + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="extqli-preview-wrapper" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '">' +
                    '<video class="extqli-preview-video" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" autoplay playsinline muted style="display:' + videoDisplayStyle + '; width:100%; height:100%; object-fit:cover; background:#000;"></video>' +
                    '<div class="extqli-preview-overlay" data-agent-uuid="' + escapeHtml(a.agent_uuid) + '" style="display:' + overlayDisplayStyle + '; align-items:center; justify-content:center; width:100%; height:100%;">' + escapeHtml(overlayText) + '</div>' +
                '</div>' +
                '<div class="extqli-monitor-actions">' +
                    '<button class="btn btn-dark btn-sm" data-card-action="view" onclick="openScreenViewer(\'' + escapeHtml(a.agent_uuid) + '\')">View Screen</button>' +
                    '<button class="btn btn-success btn-sm" data-card-action="remote" onclick="openRemoteScreen(\'' + escapeHtml(a.agent_uuid) + '\')"' + (isOnline ? '' : ' disabled') + '>Remote Screen</button>' +
                '</div>' +
            '</div>';
    }

    function createMonitorCard(a) {
        var temp = document.createElement('div');

        temp.innerHTML = buildMonitorCardHtml(a).trim();

        return temp.firstElementChild;
    }

    function setNodeText(node, text) {
        if (node && node.textContent !== text) {
            node.textContent = text;
        }
    }

    function updateMonitorCard(card, a) {
        var isOnline = !!a.is_online;
        var userDisplay = getAgentDisplayName(a);
        var hostnameDisplay = getAgentHostname(a);
        var badge = card.querySelector('[data-card-field="badge"]');
        var remoteButton = card.querySelector('[data-card-action="remote"]');
        var peer = extqliViewerPeers[a.agent_uuid];
        var hasStream = !!(peer && peer.stream);
        var video = card.querySelector('.extqli-preview-video[data-agent-uuid="' + a.agent_uuid + '"]');
        var overlay = card.querySelector('.extqli-preview-overlay[data-agent-uuid="' + a.agent_uuid + '"]');

        card.classList.toggle('is-online', isOnline);
        card.classList.toggle('is-offline', !isOnline);

        setNodeText(card.querySelector('[data-card-field="title"]'), userDisplay);
        setNodeText(card.querySelector('[data-card-field="ip"]'), 'IP: ' + (a.local_ip || '-'));
        setNodeText(card.querySelector('[data-card-field="hostname"]'), 'Hostname: ' + hostnameDisplay);

        if (badge) {
            badge.classList.toggle('extqli-live-badge', isOnline);
            badge.classList.toggle('extqli-offline-badge', !isOnline);
            setNodeText(badge, isOnline ? 'ONLINE' : 'OFFLINE');
        }

        if (remoteButton) {
            remoteButton.disabled = !isOnline;
        }

        if (isOnline && hasStream) {
            setPreviewVideoStream(a.agent_uuid, peer.stream);
        } else if (!isOnline) {
            if (video && video.srcObject) {
                video.srcObject = null;
            }

            if (video) {
                video.style.display = 'none';
            }

            if (overlay) {
                overlay.style.display = 'flex';
                overlay.textContent = 'Agent offline';
            }
        } else {
            if (video && video.srcObject) {
                video.style.display = 'block';
            } else if (video) {
                video.style.display = 'none';
            }

            if (overlay) {
                overlay.style.display = video && video.srcObject ? 'none' : 'flex';
                overlay.textContent = 'Connecting...';
            }
        }
    }

    function renderExtQliGrid(list) {
        var container = byId('extqliMonitorGrid');

        if (!container) return;

        if (!list.length) {
            container.innerHTML = '<div class="extqli-empty">No agents to display.</div>';
            return;
        }

        var empty = container.querySelector('.extqli-empty');

        if (empty) {
            empty.remove();
        }

        var existingCards = {};
        var desiredUuids = {};
        var fragment = document.createDocumentFragment();

        qsa('.extqli-monitor-card[data-agent-uuid]', container).forEach(function (card) {
            existingCards[String(card.getAttribute('data-agent-uuid') || '')] = card;
        });

        list.forEach(function (agent) {
            var uuid = String(agent.agent_uuid || '');

            if (!uuid) return;

            desiredUuids[uuid] = true;

            var card = existingCards[uuid];

            if (!card) {
                card = createMonitorCard(agent);
            } else {
                updateMonitorCard(card, agent);
            }

            fragment.appendChild(card);
        });

        Object.keys(existingCards).forEach(function (uuid) {
            if (!desiredUuids[uuid] && existingCards[uuid] && existingCards[uuid].parentNode) {
                existingCards[uuid].parentNode.removeChild(existingCards[uuid]);
            }
        });

        container.appendChild(fragment);

        reattachAllPreviewStreams();

        if (window.extqliApplyCardFx) {
            window.extqliApplyCardFx();
        }
    }

    function populateScreenAgentOptions(list) {
        var select = byId('screenAgentSelect');

        if (!select) return;

        var current = extqliCurrentScreenAgentUuid || select.value || '';
        var options = ['<option value="">Select an agent</option>'];

        list.forEach(function (agent) {
            var value = String(agent.agent_uuid || '');
            var label = getAgentDisplayName(agent) + ' (' + (agent.local_ip || '-') + ')';

            options.push(
                '<option value="' + escapeHtml(value) + '"' + (current === value ? ' selected' : '') + '>' +
                escapeHtml(label) +
                '</option>'
            );
        });

        select.innerHTML = options.join('');
    }

    function renderAgents(list) {
        extqliAgentsList = Array.isArray(list) ? list : [];
        appState.agents = extqliAgentsList;

        extqliAgentsList.forEach(function (agent) {
            var agentUuid = String(agent.agent_uuid || '');
            var cachedUsername = normalizeDisplayValue(extqliIdentityByAgentUuid[agentUuid]);

            if (cachedUsername) {
                setAgentIdentityOnModel(agentUuid, cachedUsername, agent);
            }

            if (!agent.is_online && extqliViewerPeers[agentUuid]) {
                cleanupPeer(agentUuid, true, 'Agent offline');
            }
        });

        updateLiveCounts();
        renderExtQliSidebar(extqliAgentsList);
        renderExtQliGrid(extqliAgentsList);
        populateScreenAgentOptions(extqliAgentsList);
        autoConnectAgents();

        if (window.extqliApplyCardFx) {
            window.extqliApplyCardFx();
        }
    }

    function loadAgents() {
        fetch(BASE_PATH + '/backend/api/agents.php?_=' + Date.now(), {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            cache: 'no-store'
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

    function sendAgentTask(agentUUID, task, data) {
        var agentUuid = String(agentUUID || '').trim();
        var taskName = String(task || '').trim();
        var peer = extqliViewerPeers[agentUuid];

        if (!agentUuid || !taskName) {
            console.warn('Task send skipped: missing agent UUID or task name.');
            return false;
        }

        if (!peer || !peer.dataChannel || peer.dataChannel.readyState !== 'open') {
            console.warn('Task send skipped: WebRTC data channel is not open for', agentUuid);
            setScreenStatus('Task not sent: open the Remote Screen first so the WebRTC data channel is active.');
            return false;
        }

        var taskId = Date.now();

        peer.dataChannel.send(JSON.stringify({
            type: 'task',
            task: taskName,
            task_id: taskId,
            data: data || {}
        }));

        console.log('[DEBUG] Task sent through WebRTC data channel:', agentUuid, taskName, taskId);

        return true;
    }

    function stopScreenView() {
        console.log('[DEBUG] stopScreenView — pausing feed, keeping connection for:', extqliCurrentScreenAgentUuid);

        var peer = activeViewerPeer();

        if (peer && peer.dataChannel && peer.dataChannel.readyState === 'open') {
            peer.dataChannel.send(JSON.stringify({
                type: 'pause_video'
            }));
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

        if (remoteToggle) {
            remoteToggle.checked = false;
        }

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
        extqliIsScreenViewing = true;

        if (setMainVideoToAgentStream(agentUuid)) {
            setScreenStatus(extqliRemoteControlEnabled ? 'Streaming active — Remote Control ON' : 'Streaming active');
            showScreenEmptyState(false);

            var livePeer = activeViewerPeer();

            if (livePeer && livePeer.dataChannel && livePeer.dataChannel.readyState === 'open') {
                livePeer.dataChannel.send(JSON.stringify({
                    type: 'resume_video'
                }));
            }

            return;
        }

        setScreenStatus('Starting WebRTC stream...');
        setPreviewOverlayState(agentUuid, false, 'Connecting...');

        createWebRTCOffer(agentUuid)
            .then(function (peer) {
                var checks = 0;
                var checkInterval = setInterval(function () {
                    checks += 1;

                    if (peer.stream && setMainVideoToAgentStream(agentUuid)) {
                        clearInterval(checkInterval);
                        setScreenStatus(extqliRemoteControlEnabled ? 'Streaming active — Remote Control ON' : 'Streaming active');
                    } else if (peer.pc && (peer.pc.connectionState === 'failed' || peer.pc.iceConnectionState === 'failed')) {
                        clearInterval(checkInterval);
                    } else if (checks > 60) {
                        clearInterval(checkInterval);
                    }
                }, 250);
            })
            .catch(function (err) {
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

        if (overlay) {
            overlay.style.display = (extqliIsScreenViewing && extqliRemoteControlEnabled) ? 'block' : 'none';
        }
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

        if (targetNameEl) {
            targetNameEl.textContent = targetName;
        }

        var remoteBar = byId('extqliRemoteStatus');

        if (!remoteBar) return;

        var textEl = remoteBar.querySelector('.extqli-remote-bar-text');

        if (isActiveRemote && agentUuid) {
            remoteBar.classList.add('is-active');

            if (textEl) {
                textEl.innerHTML =
                    '<strong>Remote target: ' + escapeHtml(targetName) + '</strong>' +
                    '<span>Control input is active — switch to the Screen Viewer tab to interact.</span>';
            }
        } else {
            remoteBar.classList.remove('is-active');

            if (textEl) {
                textEl.innerHTML =
                    '<strong>Remote control is idle.</strong>' +
                    '<span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>';
            }
        }
    }

    function clearRemoteTarget() {
        if (extqliIsScreenViewing) {
            stopScreenView();
        }

        extqliCurrentScreenAgentUuid = '';
        extqliRemoteControlEnabled = false;

        var remoteToggle = byId('remoteControlToggle');

        if (remoteToggle) {
            remoteToggle.checked = false;
        }

        updateRemoteOverlay();

        var targetNameEl = byId('remoteTargetName');

        if (targetNameEl) {
            targetNameEl.textContent = 'None';
        }

        var remoteBar = byId('extqliRemoteStatus');

        if (remoteBar) {
            remoteBar.classList.remove('is-active');

            var textEl = remoteBar.querySelector('.extqli-remote-bar-text');

            if (textEl) {
                textEl.innerHTML =
                    '<strong>Remote control is idle.</strong>' +
                    '<span>Select a client and click <strong>Remote Screen</strong>. Control input will go through the Screen Viewer tab only.</span>';
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

            if (panel) {
                panel.hidden = key !== extqliCurrentAdminTab;
            }
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
        appState.currentScreenAgentUuid = extqliCurrentScreenAgentUuid;

        var select = byId('screenAgentSelect');

        if (select && agentUuid) {
            select.value = agentUuid;
        }

        updateRemoteTargetDisplay(agentUuid, false);

        if (isConnectionAlive(agentUuid) || (extqliViewerPeers[agentUuid] && extqliViewerPeers[agentUuid].stream)) {
            setScreenStatus('Live connection ready — click Start Viewing to resume instantly.');
        } else {
            setScreenStatus('Selected agent. Preparing connection...');
            createWebRTCOffer(agentUuid).catch(function (err) {
                console.warn('[DEBUG] Preconnect failed:', err);
            });
        }
    }

    function openRemoteScreen(agentUuid) {
        activateView('agentsView', 'screen');

        extqliCurrentScreenAgentUuid = agentUuid || '';
        extqliIsScreenViewing = true;
        extqliRemoteControlEnabled = true;

        var select = byId('screenAgentSelect');

        if (select && agentUuid) {
            select.value = agentUuid;
        }

        var remoteToggle = byId('remoteControlToggle');

        if (remoteToggle) {
            remoteToggle.checked = true;
        }

        updateRemoteOverlay();
        updateRemoteTargetDisplay(agentUuid, true);
        setScreenStatus('Preparing remote stream...');

        if (setMainVideoToAgentStream(agentUuid)) {
            setScreenStatus('Streaming active — Remote Control ON');

            var livePeer = activeViewerPeer();

            if (livePeer && livePeer.dataChannel && livePeer.dataChannel.readyState === 'open') {
                livePeer.dataChannel.send(JSON.stringify({
                    type: 'resume_video'
                }));
            }

            return;
        }

        createWebRTCOffer(agentUuid)
            .then(function (peer) {
                var checks = 0;
                var checkInterval = setInterval(function () {
                    checks += 1;

                    if (peer.stream && setMainVideoToAgentStream(agentUuid)) {
                        clearInterval(checkInterval);
                        setScreenStatus('Streaming active — Remote Control ON');
                    } else if (peer.pc && (peer.pc.connectionState === 'failed' || peer.pc.iceConnectionState === 'failed')) {
                        clearInterval(checkInterval);
                    } else if (checks > 72) {
                        clearInterval(checkInterval);
                    }
                }, 250);
            })
            .catch(function (err) {
                console.error('[DEBUG] createWebRTCOffer error:', err);
                setScreenStatus('WebRTC start failed: ' + err.message);
                extqliIsScreenViewing = false;
                showScreenEmptyState(true);
            });
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
                if (extqliIsScreenViewing) {
                    stopScreenView();
                }

                extqliCurrentScreenAgentUuid = screenSelect.value || '';
                extqliRemoteControlEnabled = false;

                if (remoteToggle) {
                    remoteToggle.checked = false;
                }

                updateRemoteOverlay();

                if (extqliCurrentScreenAgentUuid) {
                    if (isConnectionAlive(extqliCurrentScreenAgentUuid) || (extqliViewerPeers[extqliCurrentScreenAgentUuid] && extqliViewerPeers[extqliCurrentScreenAgentUuid].stream)) {
                        setScreenStatus('Agent selected — live connection ready. Click Start Viewing to resume instantly.');
                    } else {
                        setScreenStatus('Agent selected. Preparing connection...');
                        createWebRTCOffer(extqliCurrentScreenAgentUuid).catch(function (err) {
                            console.warn('[DEBUG] Preconnect failed:', err);
                        });
                    }
                } else {
                    setScreenStatus('Waiting for a selected agent.');
                }
            });
        }

        if (refreshViewerAgentsBtn) {
            refreshViewerAgentsBtn.onclick = loadAgents;
        }

        if (startScreenViewBtn) {
            startScreenViewBtn.onclick = startScreenView;
        }

        if (stopScreenViewBtn) {
            stopScreenViewBtn.onclick = stopScreenView;
        }

        if (fullscreenBtn) {
            fullscreenBtn.onclick = toggleFullscreen;
        }

        if (clearRemoteTargetBtn) {
            clearRemoteTargetBtn.onclick = clearRemoteTarget;
        }

        if (remoteToggle) {
            remoteToggle.addEventListener('change', function () {
                extqliRemoteControlEnabled = remoteToggle.checked;
                updateRemoteOverlay();

                setScreenStatus(
                    (extqliIsScreenViewing ? 'Screen active. ' : 'Start viewing first. ') +
                    'Remote control ' +
                    (extqliRemoteControlEnabled ? 'ON' : 'OFF')
                );
            });
        }
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