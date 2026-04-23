<?php
$base = '/eXT.Qli';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eXT.Qli by Qliphoth</title>

    <link rel="stylesheet" href="https://unpkg.com/@knadh/oat/oat.min.css">
    <script src="https://unpkg.com/@knadh/oat/oat.min.js" defer></script>

    <link rel="stylesheet" href="<?= $base ?>/assets/css/app.css?v=14">
</head>
<body>
    <div id="pageLoader" class="page-loader">
        <div class="loader-card">
            <div class="loader-spinner"></div>
            <div class="loader-title">eXT.Qli</div>
            <div class="loader-subtitle">Loading dashboard...</div>
        </div>
    </div>

    <?php require __DIR__ . '/frontend/views/dashboard.php'; ?>

    <script src="<?= $base ?>/assets/js/app.js?v=14"></script>
</body>
</html>