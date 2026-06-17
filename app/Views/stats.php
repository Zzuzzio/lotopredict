<?php
$title = 'LotoPredict — Статистика';
$includeChart = true;
?>

<h1>Статистика</h1>

<?php if ($lottery === null): ?>
    <div class="alert">Лотерея не найдена.</div>
<?php else: ?>

<form method="get" class="filter-form">
    <label>
        Лотерея
        <select name="lottery">
            <?php foreach ($lotteries as $item): ?>
                <option value="<?= htmlspecialchars($item['slug']) ?>" <?= $item['slug'] === $lottery['slug'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($item['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Период
        <select name="period">
            <option value="30" <?= $period === 30 ? 'selected' : '' ?>>30 тиражей</option>
            <option value="100" <?= $period === 100 ? 'selected' : '' ?>>100 тиражей</option>
            <option value="0" <?= $period === null ? 'selected' : '' ?>>Все тиражи</option>
        </select>
    </label>
    <button type="submit">Показать</button>
</form>

<?php if ($summary === null || $summary['total_draws'] === 0): ?>
    <div class="alert">Нет данных для статистики. Загрузите тиражи.</div>
<?php else: ?>

<p class="meta">Проанализировано тиражей: <strong><?= (int) $summary['total_draws'] ?></strong></p>

<div class="grid-2">
    <section class="panel">
        <h2>Частота номеров</h2>
        <canvas id="freqChart" height="120"></canvas>
    </section>

    <section class="panel">
        <h2>Горячие и холодные</h2>
        <div class="hot-cold">
            <div>
                <h3>Горячие</h3>
                <div class="numbers">
                    <?php foreach ($summary['hot_cold']['hot'] as $num): ?>
                        <span class="ball hot"><?= (int) $num ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h3>Холодные</h3>
                <div class="numbers">
                    <?php foreach ($summary['hot_cold']['cold'] as $num): ?>
                        <span class="ball cold"><?= (int) $num ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="grid-2">
    <section class="panel">
        <h2>Топ пар</h2>
        <table class="table compact">
            <thead><tr><th>Пара</th><th>Раз</th></tr></thead>
            <tbody>
                <?php foreach ($summary['pairs'] as $pair): ?>
                <tr>
                    <td><?= (int) $pair['pair'][0] ?> — <?= (int) $pair['pair'][1] ?></td>
                    <td><?= (int) $pair['count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>Просроченные номера</h2>
        <table class="table compact">
            <thead><tr><th>Номер</th><th>Тиражей назад</th></tr></thead>
            <tbody>
                <?php foreach ($summary['overdue'] as $item): ?>
                <tr>
                    <td><span class="ball"><?= (int) $item['number'] ?></span></td>
                    <td><?= (int) $item['draws_since'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<?php if (!empty($summary['bonus'])): ?>
<div class="grid-2">
    <section class="panel">
        <h2>Бонусное поле — частота</h2>
        <div class="numbers">
            <?php foreach ($summary['bonus']['frequency'] as $num => $cnt): ?>
                <span class="ball bonus" title="<?= (int) $cnt ?> раз"><?= (int) $num ?> <small>(<?= (int) $cnt ?>)</small></span>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <h2>Бонусное поле — горячие / холодные</h2>
        <div class="hot-cold">
            <div>
                <h3>Горячие</h3>
                <div class="numbers">
                    <?php foreach ($summary['bonus']['hot_cold']['hot'] as $num): ?>
                        <span class="ball bonus hot"><?= (int) $num ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h3>Холодные</h3>
                <div class="numbers">
                    <?php foreach ($summary['bonus']['hot_cold']['cold'] as $num): ?>
                        <span class="ball bonus cold"><?= (int) $num ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</div>
<?php endif; ?>

<?php
$labels = array_keys($summary['frequency']);
$values = array_values($summary['frequency']);
$pageScript = 'const ctx = document.getElementById("freqChart");
new Chart(ctx, {
    type: "bar",
    data: {
        labels: ' . json_encode($labels) . ',
        datasets: [{
            label: "Частота",
            data: ' . json_encode($values) . ',
            backgroundColor: "rgba(37, 99, 235, 0.6)",
            borderColor: "rgba(37, 99, 235, 1)",
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});';
?>

<?php endif; ?>
<?php endif; ?>
