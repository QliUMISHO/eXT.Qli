(function () {
    'use strict';

    const SELECTORS = {
        remoteBar: '#extqliRemoteStatus',
        clearButton: '#clearRemoteTargetBtn',
        card: '.extqli-monitor-card, .extqli-agent-card',
        cardTitle: '.extqli-card-title'
    };

    let selectedAgentUuid = '';
    let lastRenderedKey = '';

    function qs(selector, root = document) {
        return root.querySelector(selector);
    }

    function qsa(selector, root = document) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function cleanText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getRemoteBar() {
        return qs(SELECTORS.remoteBar);
    }

    function getUuid(card) {
        return String(card && card.getAttribute('data-agent-uuid') || '').trim();
    }

    function findCardByUuid(agentUuid) {
        agentUuid = String(agentUuid || '').trim();

        if (!agentUuid) {
            return null;
        }

        return qs('.extqli-monitor-card[data-agent-uuid="' + cssEscape(agentUuid) + '"]') ||
            qs('.extqli-agent-card[data-agent-uuid="' + cssEscape(agentUuid) + '"]');
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function getCardName(card) {
        const title = qs(SELECTORS.cardTitle, card);

        return title ? cleanText(title.textContent) || 'Selected endpoint' : 'Selected endpoint';
    }

    function getCardIp(card) {
        let ipText = '';

        qsa('.extqli-card-ip, .extqli-card-meta', card).some(function (node) {
            const text = cleanText(node.textContent);

            if (text.toLowerCase().indexOf('ip:') === 0) {
                ipText = text;
                return true;
            }

            return false;
        });

        return ipText;
    }

    function setRemoteBarContent(card) {
        const remoteBar = getRemoteBar();

        if (!remoteBar || !card) {
            return;
        }

        const textEl = qs('.extqli-remote-bar-text', remoteBar);

        if (!textEl) {
            return;
        }

        const name = getCardName(card);
        const ip = getCardIp(card);
        const uuid = getUuid(card);

        const renderKey = uuid + '|' + name + '|' + ip;

        if (lastRenderedKey === renderKey) {
            return;
        }

        lastRenderedKey = renderKey;

        textEl.innerHTML =
            '<strong>Selected endpoint: ' + escapeHtml(name) + '</strong>' +
            '<span>' +
                (ip ? escapeHtml(ip) + '. ' : '') +
                'Click <strong>Remote Screen</strong> to open the remote-control workflow through the Screen Viewer panel.' +
            '</span>';
    }

    function showRemoteBarForCard(card) {
        const remoteBar = getRemoteBar();

        if (!remoteBar || !card) {
            return;
        }

        selectedAgentUuid = getUuid(card);

        setRemoteBarContent(card);

        if (remoteBar.hidden === true) {
            remoteBar.hidden = false;
        }

        if (!remoteBar.classList.contains('extqli-remote-visible')) {
            remoteBar.classList.add('extqli-remote-visible');
        }

        if (remoteBar.classList.contains('extqli-remote-hidden')) {
            remoteBar.classList.remove('extqli-remote-hidden');
        }
    }

    function hideRemoteBar() {
        const remoteBar = getRemoteBar();

        if (!remoteBar) {
            return;
        }

        selectedAgentUuid = '';
        lastRenderedKey = '';

        if (remoteBar.classList.contains('is-active')) {
            remoteBar.classList.remove('is-active');
        }

        if (remoteBar.classList.contains('extqli-remote-visible')) {
            remoteBar.classList.remove('extqli-remote-visible');
        }

        if (!remoteBar.classList.contains('extqli-remote-hidden')) {
            remoteBar.classList.add('extqli-remote-hidden');
        }

        if (remoteBar.hidden !== true) {
            remoteBar.hidden = true;
        }
    }

    function applySelectedCardClasses(agentUuid) {
        agentUuid = String(agentUuid || '').trim();

        qsa(SELECTORS.card).forEach(function (card) {
            const shouldSelect = agentUuid !== '' && getUuid(card) === agentUuid;

            if (card.classList.contains('is-selected') !== shouldSelect) {
                card.classList.toggle('is-selected', shouldSelect);
            }
        });
    }

    function selectAgent(agentUuid, source) {
        agentUuid = String(agentUuid || '').trim();

        if (!agentUuid) {
            return;
        }

        selectedAgentUuid = agentUuid;
        applySelectedCardClasses(agentUuid);

        const card = findCardByUuid(agentUuid);

        if (card) {
            showRemoteBarForCard(card);
        }

        if (source !== 'endpoint-cards') {
            document.dispatchEvent(new CustomEvent('extqli:select-agent-card', {
                detail: {
                    agentUuid: agentUuid,
                    source: 'remote-status-ui'
                }
            }));
        }
    }

    function bindCardSelection() {
        if (document.documentElement.dataset.extqliRemoteStatusClickBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliRemoteStatusClickBound = '1';

        document.addEventListener('click', function (event) {
            if (
                event.target.closest('#extqliRemoteStatus') ||
                event.target.closest('.extqli-headless-native-btn') ||
                event.target.closest('.extqli-native-key-btn') ||
                event.target.closest('button') ||
                event.target.closest('a') ||
                event.target.closest('input') ||
                event.target.closest('select') ||
                event.target.closest('textarea')
            ) {
                return;
            }

            const card = event.target.closest(SELECTORS.card);

            if (!card) {
                return;
            }

            const uuid = getUuid(card);

            if (!uuid) {
                return;
            }

            selectAgent(uuid, 'click');
        }, false);
    }

    function bindClearButton() {
        if (document.documentElement.dataset.extqliRemoteStatusClearBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliRemoteStatusClearBound = '1';

        document.addEventListener('click', function (event) {
            const clearButton = event.target.closest(SELECTORS.clearButton);

            if (!clearButton) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            selectedAgentUuid = '';
            applySelectedCardClasses('');
            hideRemoteBar();
        }, true);
    }

    function bindSelectionEvents() {
        if (document.documentElement.dataset.extqliRemoteStatusEventBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliRemoteStatusEventBound = '1';

        document.addEventListener('extqli:agent-card-selected', function (event) {
            const detail = event && event.detail ? event.detail : {};
            const uuid = String(detail.agentUuid || '').trim();

            if (!uuid) {
                return;
            }

            selectAgent(uuid, 'endpoint-cards');
        });
    }

    function observeCardListOnly() {
        if (document.documentElement.dataset.extqliRemoteStatusObserverBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliRemoteStatusObserverBound = '1';

        const targets = [
            document.getElementById('extqliMonitorGrid'),
            document.getElementById('extqliClientList')
        ].filter(Boolean);

        if (!targets.length) {
            return;
        }

        let timer = null;

        const observer = new MutationObserver(function (mutations) {
            let shouldRefresh = false;

            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    shouldRefresh = true;
                }
            });

            if (!shouldRefresh || !selectedAgentUuid) {
                return;
            }

            window.clearTimeout(timer);

            timer = window.setTimeout(function () {
                applySelectedCardClasses(selectedAgentUuid);

                const card = findCardByUuid(selectedAgentUuid);

                if (card) {
                    showRemoteBarForCard(card);
                }
            }, 120);
        });

        targets.forEach(function (target) {
            observer.observe(target, {
                childList: true,
                subtree: true
            });
        });
    }

    function boot() {
        hideRemoteBar();
        bindCardSelection();
        bindClearButton();
        bindSelectionEvents();
        observeCardListOnly();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.extqliShowRemoteStatusForCard = showRemoteBarForCard;
    window.extqliHideRemoteStatus = hideRemoteBar;
})();