(function () {
    'use strict';

    const CARD_SELECTOR = '.extqli-monitor-card, .extqli-agent-card';
    const TOOLTIP_SELECTOR =
        '.extqli-monitor-card [title], ' +
        '.extqli-agent-card [title], ' +
        '.extqli-monitor-card[title], ' +
        '.extqli-agent-card[title]';

    let selectedAgentUuid = '';
    let tooltipCleanupTimer = null;
    let selectionApplyTimer = null;
    let observerStarted = false;

    function qsa(selector, root = document) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function getUuid(card) {
        if (!card) {
            return '';
        }

        return String(card.getAttribute('data-agent-uuid') || '').trim();
    }

    function removeEndpointTooltips() {
        qsa(TOOLTIP_SELECTOR).forEach(function (node) {
            node.removeAttribute('title');
        });
    }

    function scheduleTooltipCleanup() {
        window.clearTimeout(tooltipCleanupTimer);

        tooltipCleanupTimer = window.setTimeout(function () {
            removeEndpointTooltips();
        }, 60);
    }

    function setSelectedUuid(uuid, source) {
        uuid = String(uuid || '').trim();

        if (selectedAgentUuid === uuid) {
            applySelectedState(false);
            return;
        }

        selectedAgentUuid = uuid;
        applySelectedState(true, source || 'endpoint-cards');
    }

    function applySelectedState(emitEvent, source) {
        window.clearTimeout(selectionApplyTimer);

        selectionApplyTimer = window.setTimeout(function () {
            qsa(CARD_SELECTOR).forEach(function (card) {
                const uuid = getUuid(card);
                const shouldSelect = selectedAgentUuid !== '' && uuid === selectedAgentUuid;

                if (card.classList.contains('is-selected') !== shouldSelect) {
                    card.classList.toggle('is-selected', shouldSelect);
                }
            });

            scheduleTooltipCleanup();

            if (emitEvent) {
                document.dispatchEvent(new CustomEvent('extqli:agent-card-selected', {
                    detail: {
                        agentUuid: selectedAgentUuid,
                        source: source || 'endpoint-cards'
                    }
                }));
            }
        }, 0);
    }

    function selectCard(card) {
        const uuid = getUuid(card);

        if (!uuid) {
            scheduleTooltipCleanup();
            return;
        }

        setSelectedUuid(uuid, 'card-click');
    }

    function bindCardSelection() {
        if (document.documentElement.dataset.extqliEndpointCardsClickBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliEndpointCardsClickBound = '1';

        document.body.addEventListener('click', function (event) {
            if (
                event.target.closest('.card-close-btn') ||
                event.target.closest('.extqli-headless-native-btn') ||
                event.target.closest('.extqli-native-key-btn') ||
                event.target.closest('button') ||
                event.target.closest('a') ||
                event.target.closest('input') ||
                event.target.closest('select') ||
                event.target.closest('textarea')
            ) {
                scheduleTooltipCleanup();
                return;
            }

            const card = event.target.closest(CARD_SELECTOR);

            if (!card) {
                return;
            }

            selectCard(card);
        }, false);
    }

    function bindExternalSelectionEvents() {
        if (document.documentElement.dataset.extqliEndpointCardsExternalBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliEndpointCardsExternalBound = '1';

        document.addEventListener('extqli:select-agent-card', function (event) {
            const uuid = event && event.detail ? event.detail.agentUuid : '';

            if (!uuid) {
                return;
            }

            setSelectedUuid(uuid, 'external');
        });
    }

    function bindTooltipRemoval() {
        if (document.documentElement.dataset.extqliEndpointTooltipBound === '1') {
            return;
        }

        document.documentElement.dataset.extqliEndpointTooltipBound = '1';

        document.body.addEventListener('mouseover', function (event) {
            const card = event.target.closest(CARD_SELECTOR);

            if (!card) {
                return;
            }

            scheduleTooltipCleanup();
        }, true);
    }

    function watchDynamicCards() {
        if (observerStarted) {
            return;
        }

        observerStarted = true;

        const targets = [
            document.getElementById('extqliMonitorGrid'),
            document.getElementById('extqliClientList')
        ].filter(Boolean);

        if (!targets.length) {
            return;
        }

        let mutationTimer = null;

        const observer = new MutationObserver(function (mutations) {
            let shouldUpdate = false;

            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    shouldUpdate = true;
                }

                if (mutation.type === 'attributes' && mutation.attributeName === 'title') {
                    shouldUpdate = true;
                }
            });

            if (!shouldUpdate) {
                return;
            }

            window.clearTimeout(mutationTimer);

            mutationTimer = window.setTimeout(function () {
                scheduleTooltipCleanup();
                applySelectedState(false);
            }, 100);
        });

        targets.forEach(function (target) {
            observer.observe(target, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['title']
            });
        });
    }

    function boot() {
        bindCardSelection();
        bindExternalSelectionEvents();
        bindTooltipRemoval();
        watchDynamicCards();

        scheduleTooltipCleanup();
        applySelectedState(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.extqliSelectAgentCard = function (agentUuid) {
        setSelectedUuid(agentUuid, 'global');
    };

    window.extqliGetSelectedAgentUuid = function () {
        return selectedAgentUuid;
    };
})();