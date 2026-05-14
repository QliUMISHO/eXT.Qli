(function () {
    'use strict';

    var BASE_PATH = window.EXTQLI_API_BASE_PATH || '/eXT.Qli_preprod';
    var BUTTON_CLASS = 'extqli-native-viewer-btn';
    var PROTOCOL_SCHEME = 'extqli-viewer://open';

    function notify(message, type) {
        type = type || 'info';

        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type === 'error' ? 'error' : type === 'success' ? 'success' : 'info',
                title: message,
                showConfirmButton: false,
                timer: 3200,
                timerProgressBar: true,
                didOpen: function (toast) {
                    toast.addEventListener('mouseenter', window.Swal.stopTimer);
                    toast.addEventListener('mouseleave', window.Swal.resumeTimer);
                }
            });
            return;
        }

        console.log('[eXT.Qli Native Viewer]', message);
    }

    function getBaseUrl() {
        var basePath = String(BASE_PATH || '').trim();

        if (!basePath) {
            basePath = '/eXT.Qli_preprod';
        }

        if (basePath.indexOf('http://') === 0 || basePath.indexOf('https://') === 0) {
            return basePath.replace(/\/+$/, '');
        }

        return window.location.origin + '/' + basePath.replace(/^\/+/, '').replace(/\/+$/, '');
    }

    function getAgentUuidFromCard(card) {
        if (!card) return '';

        return String(card.getAttribute('data-agent-uuid') || '').trim();
    }

    function createNativeButton(agentUuid) {
        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'btn btn-dark btn-sm ' + BUTTON_CLASS;
        button.setAttribute('data-native-viewer-agent', agentUuid);
        button.textContent = 'Native Viewer';
        button.title = 'Open this endpoint in the installed eXT.Qli Native Viewer';

        return button;
    }

    function injectNativeButtons() {
        var cards = Array.prototype.slice.call(
            document.querySelectorAll('.extqli-monitor-card[data-agent-uuid], .extqli-agent-card[data-agent-uuid]')
        );

        cards.forEach(function (card) {
            var agentUuid = getAgentUuidFromCard(card);

            if (!agentUuid) return;

            var actions = card.querySelector('.extqli-monitor-actions, .extqli-card-actions');

            if (!actions) return;

            if (actions.querySelector('.' + BUTTON_CLASS)) return;

            actions.appendChild(createNativeButton(agentUuid));
        });
    }

    function buildProtocolUrl(agentUuid) {
        return PROTOCOL_SCHEME +
            '?base_url=' + encodeURIComponent(getBaseUrl()) +
            '&agent_uuid=' + encodeURIComponent(agentUuid);
    }

    function buildManualCommand(agentUuid) {
        return 'extqli_native_viewer.exe --base-url "' + getBaseUrl() + '" --agent-uuid "' + agentUuid + '"';
    }

    function copyManualCommand(agentUuid) {
        var command = buildManualCommand(agentUuid);

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(command).catch(function () {
                console.log('[eXT.Qli Native Viewer fallback command]', command);
            });
        } else {
            console.log('[eXT.Qli Native Viewer fallback command]', command);
        }
    }

    function openProtocol(protocolUrl) {
        var iframe = document.createElement('iframe');

        iframe.style.display = 'none';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.src = protocolUrl;

        document.body.appendChild(iframe);

        setTimeout(function () {
            if (iframe && iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }, 1800);

        setTimeout(function () {
            window.location.href = protocolUrl;
        }, 120);
    }

    function launchNativeViewer(agentUuid) {
        agentUuid = String(agentUuid || '').trim();

        if (!agentUuid) {
            notify('Missing agent UUID.', 'error');
            return;
        }

        var protocolUrl = buildProtocolUrl(agentUuid);

        console.log('[eXT.Qli Native Viewer protocol]', protocolUrl);
        console.log('[eXT.Qli Native Viewer base URL]', getBaseUrl());
        console.log('[eXT.Qli Native Viewer agent]', agentUuid);

        copyManualCommand(agentUuid);

        notify('Opening installed eXT.Qli Native Viewer...', 'info');

        openProtocol(protocolUrl);

        setTimeout(function () {
            notify('If nothing opened, reinstall the MSI or test extqli-viewer:// in Win + R.', 'info');
        }, 2200);
    }

    function bindClickHandler() {
        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest
                ? event.target.closest('.' + BUTTON_CLASS)
                : null;

            if (!button) return;

            event.preventDefault();
            event.stopPropagation();

            launchNativeViewer(button.getAttribute('data-native-viewer-agent') || '');
        });
    }

    function bootObserver() {
        var observer = new MutationObserver(function () {
            injectNativeButtons();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        injectNativeButtons();

        setInterval(injectNativeButtons, 2500);
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindClickHandler();
        bootObserver();
    });

    window.extqliLaunchNativeViewer = launchNativeViewer;
})();