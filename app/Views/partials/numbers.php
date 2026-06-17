<?php
/** @var int[] $numbers */
/** @var array $lotteryConfig */
use App\Support\LotteryHelper;

$parts = LotteryHelper::splitNumbers($numbers, $lotteryConfig ?? []);
$main = $parts['main'];
$bonus = $parts['bonus'];
$ballClass = isset($ballClass) ? $ballClass : '';
?>
<span class="numbers">
    <?php foreach ($main as $num): ?>
        <span class="ball <?= htmlspecialchars($ballClass) ?>"><?= (int) $num ?></span>
    <?php endforeach; ?>
    <?php if ($bonus !== []): ?>
        <span class="bonus-sep" title="Бонусное поле">+</span>
        <?php foreach ($bonus as $num): ?>
            <span class="ball bonus <?= htmlspecialchars($ballClass) ?>"><?= (int) $num ?></span>
        <?php endforeach; ?>
    <?php endif; ?>
</span>
