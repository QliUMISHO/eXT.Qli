(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function hideLoader() {
        var loader = byId('pageLoader');
        if (!loader) {
            return;
        }

        loader.classList.add('is-hidden');

        window.setTimeout(function () {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 400);
    }

    function setStatus(message) {
        var statusBar = byId('statusBar');
        if (statusBar) {
            statusBar.textContent = message;
        }
    }

    function setResults(items) {
        var resultsBox = byId('scanResults');
        if (!resultsBox) {
            return;
        }

        if (!items || !items.length) {
            resultsBox.innerHTML = '<div class="empty-state">No scan yet.</div>';
            return;
        }

        resultsBox.innerHTML = items.map(function (item) {
            return '' +
                '<div class="result-item">' +
                    '<div class="result-title">' + escapeHtml(item.title) + '</div>' +
                    '<div class="result-meta">' + escapeHtml(item.meta) + '</div>' +
                '</div>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function simulateScan(mode) {
        var subnetInput = byId('subnetInput');
        var subnet = subnetInput ? subnetInput.value.trim() : '';

        if (!subnet) {
            setStatus('Please enter a subnet CIDR or server IP.');
            setResults([]);
            return;
        }

        setStatus((mode === 'detect' ? 'Detecting environment and scanning ' : 'Scanning ') + subnet + ' ...');

        window.setTimeout(function () {
            setStatus('Scan completed for ' + subnet + '.');

            setResults([
                {
                    title: 'Host: 10.201.31.1',
                    meta: 'Gateway | MAC: 00:11:22:33:44:55 | Vendor: MikroTik'
                },
                {
                    title: 'Host: 10.201.31.25',
                    meta: 'Hostname: ubuntu-server | MAC: AA:BB:CC:DD:EE:10 | Vendor: Ubuntu'
                },
                {
                    title: 'Host: 10.201.31.88',
                    meta: 'Hostname: ws-finance-01 | MAC: AA:BB:CC:DD:EE:88 | Vendor: Dell'
                }
            ]);
        }, 1100);
    }

    function bindEvents() {
        var startScanBtn = byId('startScanBtn');
        var detectScanBtn = byId('detectScanBtn');
        var refreshListBtn = byId('refreshListBtn');
        var searchInput = byId('searchInput');

        if (startScanBtn) {
            startScanBtn.addEventListener('click', function () {
                simulateScan('normal');
            });
        }

        if (detectScanBtn) {
            detectScanBtn.addEventListener('click', function () {
                simulateScan('detect');
            });
        }

        if (refreshListBtn) {
            refreshListBtn.addEventListener('click', function () {
                setStatus('Device list refreshed.');
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var value = this.value.trim();
                setStatus(value ? 'Filtering saved devices for: ' + value : 'Ready');
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindEvents();
        window.setTimeout(hideLoader, 250);
    });

    window.addEventListener('load', function () {
        window.setTimeout(hideLoader, 100);
    });
})();