<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'LotoPredict') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase ?? '') ?>/assets/css/style.css">
    <?php if (!empty($includeChart)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="<?= htmlspecialchars($assetBase ?? '') ?>/" class="logo">LotoPredict</a>
            <nav class="nav">
                <a href="<?= htmlspecialchars($assetBase ?? '') ?>/">Главная</a>
                <a href="<?= htmlspecialchars($assetBase ?? '') ?>/stats">Статистика</a>
                <a href="<?= htmlspecialchars($assetBase ?? '') ?>/predict">Прогноз</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?= $content ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p class="disclaimer">
                Прогнозы основаны на статистическом анализе прошлых тиражей и не являются гарантией выигрыша
                или финансовой рекомендацией. Играйте ответственно.
            </p>
        </div>
    </footer>

    <?php if (!empty($pageScript)): ?>
    <script><?= $pageScript ?></script>
    <?php endif; ?>
</body>
</html>
