(function () {
    'use strict';

    let selectedAgentUuid = '';

    function qsa(selector) {
        return Array.prototype.slice.call(document.querySelectorAll(selector));
    }

    function getUuid(card) {
        if (!card) {
            return '';
        }

        return (card.getAttribute('data-agent-uuid') || '').trim();
    }

    function removeEndpointTooltips() {
        qsa(
            '.extqli-monitor-card [title], ' +
            '.extqli-agent-card [title], ' +
            '.extqli-monitor-card[title], ' +
            '.extqli-agent-card[title]'
        ).forEach(function (node) {
            node.removeAttribute('title');
        });
    }

    function clearSelectedCards() {
        qsa('.extqli-monitor-card, .extqli-agent-card').forEach(function (card) {
            card.classList.remove('is-selected');
        });
    }

    function applySelectedState() {
        clearSelectedCards();

        if (!selectedAgentUuid) {
            removeEndpointTooltips();
            return;
        }

        qsa('.extqli-monitor-card, .extqli-agent-card').forEach(function (card) {
            if (getUuid(card) === selectedAgentUuid) {
                card.classList.add('is-selected');
            }
        });

        removeEndpointTooltips();
    }

    function selectCard(card) {
        const uuid = getUuid(card);

        if (!uuid) {
            removeEndpointTooltips();
            return;
        }

        selectedAgentUuid = uuid;
        applySelectedState();
    }

    function bindCardSelection() {
        document.body.addEventListener('click', function (event) {
            if (event.target.closest('.card-close-btn')) {
                return;
            }

            const card = event.target.closest('.extqli-monitor-card, .extqli-agent-card');

            if (!card) {
                return;
            }

            selectCard(card);
        });
    }

    function bindTooltipRemoval() {
        document.body.addEventListener('mouseover', function (event) {
            const card = event.target.closest('.extqli-monitor-card, .extqli-agent-card');

            if (!card) {
                return;
            }

            removeEndpointTooltips();
        }, true);

        document.body.addEventListener('mouseenter', function (event) {
            const card = event.target.closest('.extqli-monitor-card, .extqli-agent-card');

            if (!card) {
                return;
            }

            removeEndpointTooltips();
        }, true);
    }

    function watchDynamicCards() {
        const grid = document.getElementById('extqliMonitorGrid');
        const list = document.getElementById('extqliClientList');

        const observer = new MutationObserver(function () {
            removeEndpointTooltips();
            applySelectedState();
        });

        if (grid) {
            observer.observe(grid, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['title']
            });
        }

        if (list) {
            observer.observe(list, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['title']
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindCardSelection();
        bindTooltipRemoval();
        watchDynamicCards();
        removeEndpointTooltips();
        applySelectedState();
    });

    window.addEventListener('load', function () {
        removeEndpointTooltips();
        applySelectedState();
    });
})();