<?php
$pageBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/eXT.Qli_preprod')), '/');
if ($pageBase === '' || $pageBase === '.') {
    $pageBase = '/eXT.Qli_preprod';
}

$apiBase = '/eXT.Qli';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eXT.Qli by Qliphoth</title>

    <link rel="stylesheet" href="https://unpkg.com/@knadh/oat/oat.min.css">
    <script src="https://unpkg.com/@knadh/oat/oat.min.js" defer></script>

    <link rel="stylesheet" href="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=45">

    <script>
        window.EXTQLI_API_BASE_PATH = <?= json_encode($apiBase) ?>;
    </script>
</head>
<body>
    <div id="pageLoader" class="page-loader" aria-live="polite">
        <article class="loader-card card" aria-busy="true" data-spinner="large">
            <div class="loader-mark">eQ</div>
            <div class="loader-title">eXT.Qli</div>
            <div class="loader-subtitle">Preparing endpoint console</div>
        </article>
    </div>

    <?php require __DIR__ . '/frontend/views/dashboard.php'; ?>

    <script src="<?= htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=45"></script>
</body>
</html>