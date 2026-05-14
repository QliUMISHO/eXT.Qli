<?php
$pageBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/eXT.Qli_preprod')), '/');

if ($pageBase === '' || $pageBase === '.') {
    $pageBase = '/eXT.Qli_preprod';
}

$apiBase = $pageBase;

$configFile = __DIR__ . '/backend/storage/system_config.json';
$runtimeConfig = [];

if (is_file($configFile)) {
    $decoded = json_decode((string) file_get_contents($configFile), true);

    if (is_array($decoded)) {
        $runtimeConfig = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eXT.Qli by Qliphoth</title>

    <link rel="stylesheet" href="https://unpkg.com/@knadh/oat/oat.min.css">
    <script src="https://unpkg.com/@knadh/oat/oat.min.js" defer></script>

    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=base-8">
    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/elements/cards.css?v=cards-10">
    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/elements/animations.css?v=animations-7">
    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/elements/remote-status.css?v=remote-status-3">
    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/elements/system-config.css?v=system-config-5">
    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/elements/viewer-controls.css?v=viewer-controls-4">

    <script>
        window.EXTQLI_API_BASE_PATH = <?= json_encode($apiBase, JSON_UNESCAPED_SLASHES) ?>;
        window.EXTQLI_PAGE_BASE_PATH = <?= json_encode($pageBase, JSON_UNESCAPED_SLASHES) ?>;
        window.EXTQLI_SYSTEM_CONFIG = <?= json_encode($runtimeConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/system-config.js?v=system-config-5"></script>
</head>
<body>
    <div id="pageLoader" class="page-loader" aria-live="polite">
        <article class="loader-card card" aria-busy="true">
            <div class="loader-mark">eXT.Qli</div>
            <div class="loader-title">Preparing Console</div>
            <div class="loader-subtitle">Loading endpoint workspace…</div>
        </article>
    </div>

    <?php require __DIR__ . '/frontend/views/dashboard.php'; ?>

    <script type="module" src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/main.js?v=main-10"></script>
    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/endpoint-cards.js?v=endpoint-cards-8" defer></script>
    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/animations.js?v=animations-7" defer></script>
    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/remote-status-ui.js?v=remote-status-3" defer></script>
    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/native-viewer-launcher.js?v=native-viewer-5" defer></script>
</body>
</html>