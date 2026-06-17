<?php
$title = 'LotoPredict — Прогноз';
$isEvolution = ($algorithm === 'evolution');
?>

<h1>Прогноз номеров</h1>
<p class="subtitle">Статистические алгоритмы и эволюционная нейросеть</p>

<?php if ($lottery === null): ?>
    <div class="alert">Лотерея не найдена.</div>
<?php else: ?>

<form method="post" class="filter-form predict-form">
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
        Алгоритм
        <select name="algorithm">
            <option value="frequency" <?= $algorithm === 'frequency' ? 'selected' : '' ?>>По частоте (взвешенный)</option>
            <option value="hot" <?= $algorithm === 'hot' ? 'selected' : '' ?>>Горячие + холодные</option>
            <option value="overdue" <?= $algorithm === 'overdue' ? 'selected' : '' ?>>Просроченные + средние</option>
            <?php if ($lottery['slug'] === 'gosloto-5x36plus'): ?>
            <option value="evolution" <?= $algorithm === 'evolution' ? 'selected' : '' ?>>Эволюционная нейросеть</option>
            <?php endif; ?>
        </select>
    </label>
    <?php if (!$isEvolution): ?>
    <label>
        Период анализа
        <select name="period">
            <option value="30" <?= $period === 30 ? 'selected' : '' ?>>30 тиражей</option>
            <option value="100" <?= $period === 100 ? 'selected' : '' ?>>100 тиражей</option>
            <option value="0" <?= $period === 0 ? 'selected' : '' ?>>Все тиражи</option>
        </select>
    </label>
    <?php endif; ?>
    <label>
        Комбинаций
        <select name="combinations">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>" <?= $combinations === $i ? 'selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
        </select>
    </label>
    <button type="submit">Сгенерировать</button>
</form>

<?php if ($lottery['slug'] === 'gosloto-5x36plus'): ?>
    <?php if (!empty($evoModel['available']) && !empty($evoModel['metrics'])): ?>
        <p class="meta">
            Нейросеть обучена.
            Точность на тесте: <strong><?= htmlspecialchars((string) $evoModel['metrics']['val_fitness']) ?></strong>
            (случайный уровень ~<?= htmlspecialchars((string) $evoModel['metrics']['random_baseline_main']) ?> совпадений в основном поле).
            <?php if (!empty($evoModel['trained_at'])): ?>
                Обновлено: <?= htmlspecialchars($evoModel['trained_at']) ?>.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="alert">
            Эволюционная модель ещё не обучена. На сервере выполните:
            <code>bash bin/install_ml.sh && bash bin/train_evolution.sh gosloto-5x36plus</code>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($evoError !== null): ?>
    <div class="alert"><?= htmlspecialchars($evoError) ?></div>
<?php endif; ?>

<?php if ($predictions !== []): ?>
<section class="panel predictions">
    <h2>Рекомендованные комбинации<?= $isEvolution ? ' (нейросеть)' : '' ?></h2>
    <?php if (!empty($lotteryConfig['bonus_count'])): ?>
        <p class="meta">Основное поле (1–<?= (int) $lottery['max_number'] ?>) + бонус (1–<?= (int) $lotteryConfig['bonus_max_number'] ?>)</p>
    <?php endif; ?>
    <?php foreach ($predictions as $index => $combo): ?>
        <div class="prediction-row">
            <span class="prediction-label">#<?= $index + 1 ?></span>
            <?php
            $numbers = array_merge($combo['main'], $combo['bonus']);
            $ballClass = 'predict';
            include __DIR__ . '/partials/numbers.php';
            ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php endif; ?>
