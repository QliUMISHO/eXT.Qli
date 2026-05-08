(function () {
    'use strict';

    const CONFIG = {
        cardSelector: '.extqli-monitor-card, .extqli-agent-card',
        previewSelector: '.extqli-preview-wrapper',
        rootClass: 'fx-ready',
        maxTilt: 6,
        maxGlowShift: 100
    };

    let rafId = null;
    let mutationTimer = null;
    let pointerState = {
        activeCard: null,
        x: 0,
        y: 0
    };

    function qsa(selector, root = document) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function prefersReducedMotion() {
        return window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function cardKey(card) {
        if (!card) {
            return '';
        }

        return card.getAttribute('data-agent-uuid') ||
            card.getAttribute('data-client-id') ||
            card.getAttribute('data-id') ||
            card.textContent.trim().slice(0, 80);
    }

    function setCardVariables() {
        qsa(CONFIG.cardSelector).forEach(function (card, index) {
            const key = cardKey(card);
            let seed = 0;

            for (let i = 0; i < key.length; i += 1) {
                seed += key.charCodeAt(i);
            }

            card.style.setProperty('--fx-index', String(index));
            card.style.setProperty('--fx-phase', '-' + ((seed % 900) / 100).toFixed(2) + 's');
            card.style.setProperty('--fx-rotate-x', '0deg');
            card.style.setProperty('--fx-rotate-y', '0deg');
            card.style.setProperty('--fx-pointer-x', '50%');
            card.style.setProperty('--fx-pointer-y', '50%');
        });
    }

    function appendSpanOnce(parent, className, insertFirst) {
        if (!parent || parent.querySelector(':scope > .' + className)) {
            return;
        }

        const span = document.createElement('span');
        span.className = className;
        span.setAttribute('aria-hidden', 'true');

        if (insertFirst) {
            parent.insertBefore(span, parent.firstChild);
        } else {
            parent.appendChild(span);
        }
    }

    function removeConflictingFxNodes(card) {
        qsa(
            ':scope > .fx-layer, :scope > .fx-border, :scope > .fx-grid, :scope > .fx-glow, :scope > .fx-sheen, :scope > .fx-corners, :scope > .fx-card-aura, :scope > .fx-card-grid, :scope > .fx-card-flow, :scope > .fx-card-line',
            card
        ).forEach(function (node) {
            node.remove();
        });
    }

    function addCardFxShell(card) {
        if (!card) {
            return;
        }

        if (card.dataset.fxVersion !== 'rail-orbit-no-pulse-v2') {
            removeConflictingFxNodes(card);
            card.dataset.fxVersion = 'rail-orbit-no-pulse-v2';
            card.dataset.fxBound = '1';
            card.classList.add('fx-card');

            appendSpanOnce(card, 'fx-frame', true);
            appendSpanOnce(card, 'fx-edge', true);
            appendSpanOnce(card, 'fx-orbit', true);
            appendSpanOnce(card, 'fx-card-focus', true);

            ['top', 'right', 'bottom', 'left'].forEach(function (pos) {
                appendSpanOnce(card, 'fx-rail-' + pos, true);
                const rail = card.querySelector(':scope > .fx-rail-' + pos);
                if (rail) {
                    rail.classList.add('fx-rail');
                }
            });

            ['a', 'b', 'c'].forEach(function (pos) {
                appendSpanOnce(card, 'fx-node-' + pos, false);
                const node = card.querySelector(':scope > .fx-node-' + pos);
                if (node) {
                    node.classList.add('fx-node');
                }
            });

            ['tl', 'tr', 'bl', 'br'].forEach(function (pos) {
                appendSpanOnce(card, 'fx-card-corner-' + pos, false);
                const corner = card.querySelector(':scope > .fx-card-corner-' + pos);
                if (corner) {
                    corner.classList.add('fx-card-corner');
                }
            });

            card.classList.add('fx-card-ready');
        } else {
            card.classList.add('fx-card');

            appendSpanOnce(card, 'fx-frame', true);
            appendSpanOnce(card, 'fx-edge', true);
            appendSpanOnce(card, 'fx-orbit', true);
            appendSpanOnce(card, 'fx-card-focus', true);
        }
    }

    function addPreviewFx(preview) {
        if (!preview || preview.dataset.fxPreviewBound === '1') {
            return;
        }

        preview.dataset.fxPreviewBound = '1';
        preview.classList.add('fx-preview');

        appendSpanOnce(preview, 'fx-preview-frame', false);
        appendSpanOnce(preview, 'fx-preview-depth', false);
    }

    function bindCardPointer(card) {
        if (!card || card.dataset.fxPointerBound === '1') {
            return;
        }

        card.dataset.fxPointerBound = '1';

        card.addEventListener('pointerenter', function () {
            pointerState.activeCard = card;
            card.classList.add('fx-hovering');
        });

        card.addEventListener('pointermove', function (event) {
            pointerState.activeCard = card;
            pointerState.x = event.clientX;
            pointerState.y = event.clientY;

            if (!rafId) {
                rafId = window.requestAnimationFrame(updateCardTilt);
            }
        });

        card.addEventListener('pointerleave', function () {
            card.classList.remove('fx-hovering');
            card.style.setProperty('--fx-rotate-x', '0deg');
            card.style.setProperty('--fx-rotate-y', '0deg');
            card.style.setProperty('--fx-pointer-x', '50%');
            card.style.setProperty('--fx-pointer-y', '50%');

            if (pointerState.activeCard === card) {
                pointerState.activeCard = null;
            }
        });
    }

    function updateCardTilt() {
        rafId = null;

        const card = pointerState.activeCard;
        if (!card) {
            return;
        }

        const rect = card.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        const localX = clamp(pointerState.x - rect.left, 0, rect.width);
        const localY = clamp(pointerState.y - rect.top, 0, rect.height);

        const xPercent = localX / rect.width;
        const yPercent = localY / rect.height;

        const rotateY = (xPercent - 0.5) * CONFIG.maxTilt;
        const rotateX = (0.5 - yPercent) * CONFIG.maxTilt;

        card.style.setProperty('--fx-rotate-x', rotateX.toFixed(2) + 'deg');
        card.style.setProperty('--fx-rotate-y', rotateY.toFixed(2) + 'deg');
        card.style.setProperty('--fx-pointer-x', (xPercent * CONFIG.maxGlowShift).toFixed(1) + '%');
        card.style.setProperty('--fx-pointer-y', (yPercent * CONFIG.maxGlowShift).toFixed(1) + '%');
    }

    function applyFxToExistingNodes() {
        document.documentElement.classList.add(CONFIG.rootClass);

        setCardVariables();

        qsa(CONFIG.cardSelector).forEach(function (card) {
            addCardFxShell(card);
            bindCardPointer(card);
        });

        qsa(CONFIG.previewSelector).forEach(function (preview) {
            addPreviewFx(preview);
        });
    }

    function scheduleApplyFx() {
        window.clearTimeout(mutationTimer);
        mutationTimer = window.setTimeout(applyFxToExistingNodes, 80);
    }

    function observeDynamicUi() {
        if (document.documentElement.dataset.fxMutationWatcher === '1') {
            return;
        }

        document.documentElement.dataset.fxMutationWatcher = '1';

        const observer = new MutationObserver(scheduleApplyFx);

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    function bindTabTransitions() {
        if (document.documentElement.dataset.fxTabBound === '1') {
            return;
        }

        document.documentElement.dataset.fxTabBound = '1';

        document.addEventListener('click', function (event) {
            const tab = event.target.closest('[data-agent-admin-tab-trigger]');
            if (!tab) {
                return;
            }

            window.setTimeout(function () {
                qsa('.agent-admin-tab-panel:not([hidden])').forEach(function (panel) {
                    panel.classList.remove('fx-panel-enter');
                    void panel.offsetWidth;
                    panel.classList.add('fx-panel-enter');
                });
            }, 40);
        });
    }

    function boot() {
        if (prefersReducedMotion()) {
            document.documentElement.classList.add('fx-reduced-motion');
            return;
        }

        applyFxToExistingNodes();
        observeDynamicUi();
        bindTabTransitions();

        window.setTimeout(applyFxToExistingNodes, 350);
        window.setTimeout(applyFxToExistingNodes, 1000);
        window.setTimeout(applyFxToExistingNodes, 1800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.extqliApplyCardFx = applyFxToExistingNodes;
})();