(function () {
    'use strict';

    var SELECTORS = {
        remoteBar: '#extqliRemoteStatus',
        clearButton: '#clearRemoteTargetBtn',
        card: '.extqli-monitor-card, .extqli-agent-card',
        selectedCard: '.extqli-monitor-card.is-selected, .extqli-agent-card.is-selected',
        cardTitle: '.extqli-card-title'
    };

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
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

    function getCardName(card) {
        var title = qs(SELECTORS.cardTitle, card);

        return title ? cleanText(title.textContent) || 'Selected endpoint' : 'Selected endpoint';
    }

    function getCardIp(card) {
        var ipText = '';

        qsa('.extqli-card-ip, .extqli-card-meta', card).some(function (node) {
            var text = cleanText(node.textContent);

            if (text.toLowerCase().indexOf('ip:') === 0) {
                ipText = text;
                return true;
            }

            return false;
        });

        return ipText;
    }

    function setRemoteBarContent(card) {
        var remoteBar = getRemoteBar();

        if (!remoteBar || !card) {
            return;
        }

        var textEl = qs('.extqli-remote-bar-text', remoteBar);

        if (!textEl) {
            return;
        }

        var name = getCardName(card);
        var ip = getCardIp(card);

        textEl.innerHTML =
            '<strong>Selected endpoint: ' + escapeHtml(name) + '</strong>' +
            '<span>' +
                (ip ? escapeHtml(ip) + '. ' : '') +
                'Click <strong>Remote Screen</strong> to open the remote-control workflow through the Screen Viewer panel.' +
            '</span>';
    }

    function showRemoteBar(card) {
        var remoteBar = getRemoteBar();

        if (!remoteBar || !card) {
            return;
        }

        setRemoteBarContent(card);

        remoteBar.hidden = false;
        remoteBar.classList.remove('extqli-remote-hidden');
        remoteBar.classList.add('extqli-remote-visible');
    }

    function hideRemoteBar() {
        var remoteBar = getRemoteBar();

        if (!remoteBar) {
            return;
        }

        remoteBar.classList.remove('is-active');
        remoteBar.classList.remove('extqli-remote-visible');
        remoteBar.classList.add('extqli-remote-hidden');
        remoteBar.hidden = true;
    }

    function selectCard(card) {
        if (!card) {
            return;
        }

        qsa(SELECTORS.card).forEach(function (item) {
            item.classList.toggle('is-selected', item === card);
        });

        showRemoteBar(card);
    }

    function bindCardSelection() {
        document.addEventListener('click', function (event) {
            var card = event.target.closest(SELECTORS.card);

            if (!card) {
                return;
            }

            selectCard(card);
        }, true);
    }

    function bindClearButton() {
        document.addEventListener('click', function (event) {
            var clearButton = event.target.closest(SELECTORS.clearButton);

            if (!clearButton) {
                return;
            }

            qsa(SELECTORS.card).forEach(function (card) {
                card.classList.remove('is-selected');
            });

            hideRemoteBar();
        }, true);
    }

    function observeSelectedState() {
        var observer = new MutationObserver(function () {
            var selectedCard = qs(SELECTORS.selectedCard);

            if (selectedCard) {
                showRemoteBar(selectedCard);
            }
        });

        observer.observe(document.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }

    function boot() {
        hideRemoteBar();
        bindCardSelection();
        bindClearButton();
        observeSelectedState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.extqliShowRemoteStatusForCard = showRemoteBar;
    window.extqliHideRemoteStatus = hideRemoteBar;
})();