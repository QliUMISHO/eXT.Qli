(function () {
    'use strict';

    var apiBase = window.EXTQLI_API_BASE_PATH || '/eXT.Qli_preprod';
    var configUrl = apiBase + '/backend/api/system_config.php';

    var defaultConfig = {
        scan_ip: '',
        system_port: 3478,
        default_port: 3478,
        turn_auto_discovery: true,
        turn_ip: '',
        turn_port: 3478,
        turn_username: 'tachyon',
        turn_password: '',
        detected_server_ip: '',
        updated_at: null
    };

    var currentConfig = Object.assign({}, defaultConfig, window.EXTQLI_SYSTEM_CONFIG || {});
    var currentValidation = validateConfig(currentConfig);

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        var el = byId(id);

        if (el) {
            el.textContent = value == null || value === '' ? '—' : String(value);
        }
    }

    function setValue(id, value) {
        var el = byId(id);

        if (el) {
            el.value = value == null ? '' : String(value);
        }
    }

    function getValue(id) {
        var el = byId(id);

        return el ? String(el.value || '').trim() : '';
    }

    function getNumberValue(id, fallback) {
        var raw = getValue(id);
        var num = parseInt(raw, 10);

        return Number.isFinite(num) ? num : fallback;
    }

    function ipv4ToInt(ip) {
        var parts = String(ip || '').trim().split('.');

        if (parts.length !== 4) return null;

        var total = 0;

        for (var i = 0; i < 4; i++) {
            var n = parseInt(parts[i], 10);

            if (!Number.isFinite(n) || n < 0 || n > 255) return null;

            total = (total << 8) + n;
        }

        return total >>> 0;
    }

    function isValidIPv4(ip) {
        return ipv4ToInt(ip) !== null;
    }

    function cidrContainsIp(cidr, ip) {
        cidr = String(cidr || '').trim();
        ip = String(ip || '').trim();

        if (cidr.indexOf('/') === -1) {
            return cidr === ip;
        }

        var parts = cidr.split('/');
        var base = parts[0];
        var bits = parseInt(parts[1], 10);

        if (!isValidIPv4(base) || !isValidIPv4(ip)) return false;
        if (!Number.isFinite(bits) || bits < 0 || bits > 32) return false;

        var baseInt = ipv4ToInt(base);
        var ipInt = ipv4ToInt(ip);
        var mask = bits === 0 ? 0 : (0xffffffff << (32 - bits)) >>> 0;

        return (baseInt & mask) === (ipInt & mask);
    }

    function isValidScanTarget(value) {
        value = String(value || '').trim();

        if (!value) return false;

        if (value.indexOf('/') === -1) {
            return isValidIPv4(value);
        }

        var parts = value.split('/');
        var bits = parseInt(parts[1], 10);

        return isValidIPv4(parts[0]) && Number.isFinite(bits) && bits >= 0 && bits <= 32;
    }

    function isValidPort(port) {
        port = parseInt(port, 10);

        return Number.isFinite(port) && port >= 1 && port <= 65535;
    }

    function validateConfig(config) {
        config = Object.assign({}, defaultConfig, config || {});

        var errors = [];
        var warnings = [];
        var scanIp = String(config.scan_ip || '').trim();
        var serverIp = String(config.detected_server_ip || '').trim();
        var turnIp = String(config.turn_ip || '').trim();

        if (!isValidScanTarget(scanIp)) {
            errors.push('Invalid scan IP/subnet. Use IPv4 or CIDR format like 10.201.0.0/24.');
        }

        if (!isValidPort(config.system_port)) {
            errors.push('Invalid system port.');
        }

        if (!turnIp || !isValidIPv4(turnIp)) {
            errors.push('Invalid TURN server IP.');
        }

        if (!isValidPort(config.turn_port)) {
            errors.push('Invalid TURN server port.');
        }

        if (!String(config.turn_username || '').trim()) {
            errors.push('TURN username is required.');
        }

        if (serverIp && isValidIPv4(serverIp) && isValidScanTarget(scanIp)) {
            if (!cidrContainsIp(scanIp, serverIp)) {
                errors.push('Scan subnet does not include the detected server IP. Cards are blocked until this is corrected.');
            }
        }

        if (!String(config.turn_password || '').trim()) {
            warnings.push('TURN password is empty. ICE may fail if your TURN server requires credentials.');
        }

        return {
            valid: errors.length === 0,
            errors: errors,
            warnings: warnings
        };
    }

    function broadcastConfigState() {
        currentValidation = validateConfig(currentConfig);

        window.EXTQLI_SYSTEM_CONFIG = currentConfig;
        window.EXTQLI_SYSTEM_CONFIG_VALID = currentValidation.valid;
        window.EXTQLI_SYSTEM_CONFIG_VALIDATION = currentValidation;

        window.dispatchEvent(new CustomEvent('extqli:config-updated', {
            detail: {
                config: Object.assign({}, currentConfig),
                validation: Object.assign({}, currentValidation)
            }
        }));
    }

    function showModal() {
        var modal = byId('systemConfigModal');

        if (!modal) return;

        modal.hidden = false;
        document.documentElement.classList.add('extqli-config-open');
        document.body.classList.add('extqli-config-open');
        populateForm(currentConfig);
    }

    function hideModal() {
        var modal = byId('systemConfigModal');

        if (!modal) return;

        modal.hidden = true;
        document.documentElement.classList.remove('extqli-config-open');
        document.body.classList.remove('extqli-config-open');
    }

    function setSaveStatus(message, mode) {
        var el = byId('configSaveStatus');

        if (!el) return;

        el.textContent = message || 'Ready.';
        el.dataset.mode = mode || 'idle';
    }

    function setTurnStatus(message, mode) {
        var el = byId('configTurnStatus');

        if (!el) return;

        el.textContent = message || 'TURN status not checked yet.';
        el.dataset.mode = mode || 'idle';
    }

    function renderValidationMessage(validation) {
        var body = document.querySelector('.extqli-config-body');

        if (!body) return;

        var old = body.querySelector('.extqli-config-warning');

        if (old) {
            old.remove();
        }

        if (validation.valid && !validation.warnings.length) {
            return;
        }

        var box = document.createElement('div');
        box.className = 'extqli-config-warning';

        var messages = validation.errors.length ? validation.errors : validation.warnings;

        box.innerHTML = messages.map(function (msg) {
            return '<div>' + escapeHtml(msg) + '</div>';
        }).join('');

        body.insertBefore(box, body.firstChild);
    }

    function populateForm(config) {
        config = Object.assign({}, defaultConfig, config || {});

        setValue('configScanIp', config.scan_ip);
        setValue('configSystemPort', config.system_port || config.default_port || 3478);
        setValue('configTurnIp', config.turn_ip);
        setValue('configTurnPort', config.turn_port || 3478);
        setValue('configTurnUser', config.turn_username || '');
        setValue('configTurnPass', config.turn_password || '');

        setText('configDefaultPortText', config.default_port || 3478);
        setText('configRuntimeBase', window.EXTQLI_API_BASE_PATH || apiBase);
        setText('configRuntimeServerIp', config.detected_server_ip || config.turn_ip || '—');

        var validation = validateConfig(config);
        var iceText = validation.valid && config.turn_ip
            ? 'TURN: ' + config.turn_ip + ':' + (config.turn_port || 3478)
            : 'Blocked: invalid config';

        setText('configRuntimeIce', iceText);
        renderValidationMessage(validation);
    }

    function collectForm() {
        return {
            scan_ip: getValue('configScanIp'),
            system_port: getNumberValue('configSystemPort', 3478),
            default_port: currentConfig.default_port || 3478,
            turn_auto_discovery: true,
            turn_ip: getValue('configTurnIp'),
            turn_port: getNumberValue('configTurnPort', 3478),
            turn_username: getValue('configTurnUser'),
            turn_password: getValue('configTurnPass'),
            detected_server_ip: currentConfig.detected_server_ip || ''
        };
    }

    function updateRuntimeConfig(config) {
        currentConfig = Object.assign({}, defaultConfig, config || {});
        currentValidation = validateConfig(currentConfig);

        populateForm(currentConfig);
        installRtcConfigurationOverride();
        broadcastConfigState();
    }

    function fetchConfig() {
        return fetch(configUrl + '?action=get&_=' + Date.now(), {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'Failed to load system config.');
                }

                updateRuntimeConfig(data.config || {});
                return currentConfig;
            })
            .catch(function (err) {
                console.warn('[eXT.Qli Config] Load failed:', err && err.message ? err.message : err);
                updateRuntimeConfig(currentConfig);
                return currentConfig;
            });
    }

    function saveConfig() {
        var payload = collectForm();
        var validation = validateConfig(payload);

        if (!validation.valid) {
            renderValidationMessage(validation);
            setSaveStatus(validation.errors[0] || 'Invalid configuration.', 'error');
            updateRuntimeConfig(payload);
            return Promise.reject(new Error(validation.errors[0] || 'Invalid configuration.'));
        }

        setSaveStatus('Saving configuration...', 'loading');

        return fetch(configUrl + '?action=save&_=' + Date.now(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            cache: 'no-store',
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'Failed to save configuration.');
                }

                updateRuntimeConfig(data.config || payload);
                setSaveStatus('Configuration saved. New WebRTC connections will use this TURN setup.', 'success');

                return currentConfig;
            })
            .catch(function (err) {
                setSaveStatus(err && err.message ? err.message : 'Save failed.', 'error');
                throw err;
            });
    }

    function autodiscoverTurn() {
        setTurnStatus('Autodiscovering TURN server IP...', 'loading');

        return fetch(configUrl + '?action=autodiscover_turn&_=' + Date.now(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'TURN autodiscovery failed.');
                }

                var merged = Object.assign({}, currentConfig, data.config || {});
                updateRuntimeConfig(merged);

                setTurnStatus(data.message || 'TURN IP autodiscovered.', 'success');
            })
            .catch(function (err) {
                setTurnStatus(err && err.message ? err.message : 'TURN autodiscovery failed.', 'error');
            });
    }

    function checkTurn() {
        var payload = {
            turn_ip: getValue('configTurnIp'),
            turn_port: getNumberValue('configTurnPort', 3478)
        };

        if (!payload.turn_ip) {
            setTurnStatus('TURN IP is required.', 'error');
            return;
        }

        setTurnStatus('Checking TURN TCP reachability...', 'loading');

        fetch(configUrl + '?action=check_turn&_=' + Date.now(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            cache: 'no-store',
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'TURN check failed.');
                }

                var check = data.check || {};

                if (check.ok) {
                    setTurnStatus('Success: ' + check.message + ' Latency: ' + check.latency_ms + 'ms', 'success');
                } else {
                    setTurnStatus('Failed: ' + check.message, 'error');
                }
            })
            .catch(function (err) {
                setTurnStatus(err && err.message ? err.message : 'TURN check failed.', 'error');
            });
    }

    function buildIceServersFromConfig() {
        var cfg = Object.assign({}, defaultConfig, window.EXTQLI_SYSTEM_CONFIG || currentConfig || {});
        var validation = validateConfig(cfg);
        var servers = [
            {
                urls: 'stun:stun.l.google.com:19302'
            }
        ];

        if (validation.valid && cfg.turn_ip && cfg.turn_port && cfg.turn_username) {
            servers.push({
                urls: 'turn:' + cfg.turn_ip + ':' + cfg.turn_port + '?transport=tcp',
                username: cfg.turn_username,
                credential: cfg.turn_password || ''
            });
        }

        return servers;
    }

    function installRtcConfigurationOverride() {
        if (window.__EXTQLI_RTC_CONFIG_OVERRIDE_INSTALLED__) {
            return;
        }

        if (!window.RTCPeerConnection) {
            return;
        }

        var NativeRTCPeerConnection = window.RTCPeerConnection;

        window.RTCPeerConnection = function (configuration, constraints) {
            var nextConfiguration = Object.assign({}, configuration || {});

            nextConfiguration.iceServers = buildIceServersFromConfig();
            nextConfiguration.iceTransportPolicy = nextConfiguration.iceTransportPolicy || 'all';
            nextConfiguration.bundlePolicy = nextConfiguration.bundlePolicy || 'max-bundle';
            nextConfiguration.rtcpMuxPolicy = nextConfiguration.rtcpMuxPolicy || 'require';

            return new NativeRTCPeerConnection(nextConfiguration, constraints);
        };

        window.RTCPeerConnection.prototype = NativeRTCPeerConnection.prototype;

        if (window.webkitRTCPeerConnection) {
            window.webkitRTCPeerConnection = window.RTCPeerConnection;
        }

        window.__EXTQLI_RTC_CONFIG_OVERRIDE_INSTALLED__ = true;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bindEvents() {
        var openBtn = byId('openSystemConfigBtn');
        var saveBtn = byId('saveSystemConfigBtn');
        var checkBtn = byId('configCheckTurnBtn');
        var autodiscoverBtn = byId('configAutoDiscoverTurnBtn');

        if (openBtn) {
            openBtn.addEventListener('click', showModal);
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', saveConfig);
        }

        if (checkBtn) {
            checkBtn.addEventListener('click', checkTurn);
        }

        if (autodiscoverBtn) {
            autodiscoverBtn.addEventListener('click', autodiscoverTurn);
        }

        document.addEventListener('click', function (event) {
            if (event.target && event.target.matches('[data-config-close]')) {
                hideModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideModal();
            }
        });

        ['configScanIp', 'configSystemPort', 'configTurnIp', 'configTurnPort', 'configTurnUser', 'configTurnPass'].forEach(function (id) {
            var el = byId(id);

            if (!el) return;

            el.addEventListener('input', function () {
                var draft = collectForm();
                var validation = validateConfig(draft);

                populateForm(draft);

                if (!validation.valid) {
                    setSaveStatus(validation.errors[0], 'error');
                    window.EXTQLI_SYSTEM_CONFIG_VALID = false;

                    window.dispatchEvent(new CustomEvent('extqli:config-invalid', {
                        detail: {
                            config: draft,
                            validation: validation
                        }
                    }));
                } else {
                    setSaveStatus('Ready.', 'idle');
                }
            });
        });
    }

    window.EXTQLI_CONFIG = {
        get: function () {
            return Object.assign({}, currentConfig);
        },
        validation: function () {
            return Object.assign({}, currentValidation);
        },
        isValid: function () {
            return validateConfig(currentConfig).valid;
        },
        reload: fetchConfig,
        save: saveConfig,
        open: showModal,
        close: hideModal,
        buildIceServers: buildIceServersFromConfig
    };

    installRtcConfigurationOverride();
    broadcastConfigState();

    document.addEventListener('DOMContentLoaded', function () {
        bindEvents();
        populateForm(currentConfig);
        fetchConfig();
    });
})();