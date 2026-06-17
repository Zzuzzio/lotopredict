<?php
$title = 'LotoPredict — Главная';
use App\Support\LotteryHelper;
?>

<h1>Анализ лотерей Столото</h1>
<p class="subtitle">Статистика тиражей и прогнозы для Гослото</p>

<?php if ($lottery === null): ?>
    <div class="alert">Лотереи не настроены. Запустите <code>php bin/fetch_draws.php --backfill</code>.</div>
<?php else: ?>

<form method="get" class="filter-form">
    <label>
        Лотерея
        <select name="lottery" onchange="this.form.submit()">
            <?php foreach ($lotteries as $item): ?>
                <option value="<?= htmlspecialchars($item['slug']) ?>" <?= $item['slug'] === $lottery['slug'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($item['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<div class="cards">
    <div class="card">
        <div class="card-label">Тиражей в базе</div>
        <div class="card-value"><?= (int) $drawCount ?></div>
    </div>
    <div class="card">
        <div class="card-label">Формат</div>
        <div class="card-value format-label"><?= htmlspecialchars(LotteryHelper::formatLabel($lotteryConfig)) ?></div>
    </div>
</div>

<?php if ($drawCount === 0): ?>
    <div class="alert">
        Данных пока нет. Загрузите тиражи командой:
        <code>php bin/fetch_draws.php --backfill</code>
    </div>
<?php else: ?>

<h2>Последние тиражи</h2>
<table class="table">
    <thead>
        <tr>
            <th>№ тиража</th>
            <th>Дата</th>
            <th>Числа</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($latestDraws as $draw): ?>
        <tr>
            <td><?= (int) $draw['draw_number'] ?></td>
            <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($draw['draw_date']))) ?></td>
            <td>
                <?php
                $numbers = $draw['numbers'];
                include __DIR__ . '/partials/numbers.php';
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>
<?php endif; ?>
