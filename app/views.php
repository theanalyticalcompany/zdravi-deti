<?php

declare(strict_types=1);

function render_layout(string $title, callable $content, string $active = ''): void
{
    $user = current_user();
    $family = $user ? current_family((int)$user['id']) : null;
    $navChildren = $user ? children_for_user((int)$user['id']) : [];
    $currentRoute = (string)($_GET['r'] ?? 'dashboard');
    $currentChildId = (int)($_GET['id'] ?? $_GET['child_id'] ?? 0);
    ?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#125c63" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#101418" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Zdraví dětí">
    <title><?= e($title) ?> | <?= e(cfg('app.name', 'Zdraví dětí')) ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/pwa-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/app.css?v=19">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(url('dashboard')) ?>">Zdraví dětí</a>
    <?php if ($user): ?>
        <nav class="nav">
            <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard')) ?>">Přehled</a>
            <a class="<?= $active === 'family' ? 'active' : '' ?>" href="<?= e(url('family')) ?>">Správa rodiny</a>
            <a class="<?= $active === 'medications' ? 'active' : '' ?>" href="<?= e(url('medications')) ?>">Léčiva</a>
            <a class="<?= $active === 'care_types' ? 'active' : '' ?>" href="<?= e(url('care_types')) ?>">Typy péče</a>
            <a class="<?= $active === 'settings' ? 'active' : '' ?>" href="<?= e(url('settings')) ?>">Nastavení</a>
            <?php foreach ($navChildren as $navChild): ?>
                <a class="nav-child-link <?= $currentRoute === 'child' && $currentChildId === (int)$navChild['id'] ? 'active' : '' ?>" href="<?= e(url('child', ['id' => $navChild['id']])) ?>"><?= e($navChild['first_name']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="account">
            <span><?= e($family['name'] ?? $user['display_name']) ?></span>
            <a class="button subtle" href="<?= e(url('logout')) ?>">Odhlásit</a>
        </div>
    <?php endif; ?>
</header>
<main class="shell">
    <?php foreach (flashes() as $flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php $content(); ?>
</main>
<script src="assets/app.js?v=19"></script>
</body>
</html><?php
}

function metric_card(string $label, string $value, string $meta = '', string $tone = 'neutral'): void
{
    ?>
    <section class="metric <?= e($tone) ?>">
        <span><?= e($label) ?></span>
        <strong><?= e($value) ?></strong>
        <?php if ($meta): ?><small><?= e($meta) ?></small><?php endif; ?>
    </section>
    <?php
}

function render_timeline_chart(array $timeline): void
{
    $temps = $timeline['temperatures'];
    $meds = $timeline['medications'];
    $fromTs = strtotime($timeline['from']);
    $toTs = max(strtotime($timeline['to']), $fromTs + 1);
    $width = 920;
    $height = 280;
    $pad = 38;
    $minY = 35.0;
    $maxY = 41.0;
    $points = [];
    $medLegend = [];
    $medNumbers = [];
    foreach ($meds as $index => $med) {
        $label = medication_label($med);
        if (!isset($medLegend[$label])) {
            $medLegend[$label] = count($medLegend) + 1;
        }
        $medNumbers[$index] = $medLegend[$label];
    }
    foreach ($temps as $temp) {
        $x = $pad + ((strtotime($temp['event_at']) - $fromTs) / ($toTs - $fromTs)) * ($width - 2 * $pad);
        $clamped = max($minY, min($maxY, (float)$temp['temperature_celsius']));
        $y = $height - $pad - (($clamped - $minY) / ($maxY - $minY)) * ($height - 2 * $pad);
        $points[] = ['x' => $x, 'y' => $y, 'value' => (float)$temp['temperature_celsius'], 'event_at' => $temp['event_at']];
    }
    ?>
    <div class="chart-wrap">
        <?php if (!$temps && !$meds): ?>
            <div class="empty">V tomto rozsahu zatím nejsou žádné záznamy.</div>
        <?php else: ?>
            <svg viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Časová osa teplot a léků">
                <line x1="<?= $pad ?>" y1="<?= $height - $pad ?>" x2="<?= $width - $pad ?>" y2="<?= $height - $pad ?>" class="axis"/>
                <line x1="<?= $pad ?>" y1="<?= $pad ?>" x2="<?= $pad ?>" y2="<?= $height - $pad ?>" class="axis"/>
                <?php foreach ([36, 37, 38, 39, 40] as $tick):
                    $y = $height - $pad - (($tick - $minY) / ($maxY - $minY)) * ($height - 2 * $pad);
                    ?>
                    <line x1="<?= $pad ?>" y1="<?= $y ?>" x2="<?= $width - $pad ?>" y2="<?= $y ?>" class="grid"/>
                    <text x="8" y="<?= $y + 4 ?>" class="tick"><?= $tick ?>°</text>
                <?php endforeach; ?>
                <?php if (count($points) > 1): ?>
                    <polyline points="<?= e(implode(' ', array_map(fn($p) => round($p['x'], 1) . ',' . round($p['y'], 1), $points))) ?>" class="temp-line"/>
                <?php endif; ?>
                <?php foreach ($points as $p): ?>
                    <circle cx="<?= round($p['x'], 1) ?>" cy="<?= round($p['y'], 1) ?>" r="6" class="temp-dot <?= e(severity($p['value'])) ?>">
                        <title><?= e(number_format($p['value'], 1, ',', ' ') . ' °C, ' . display_datetime($p['event_at'])) ?></title>
                    </circle>
                <?php endforeach; ?>
                <?php foreach ($meds as $index => $med):
                    $x = $pad + ((strtotime($med['event_at']) - $fromTs) / ($toTs - $fromTs)) * ($width - 2 * $pad);
                    $number = $medNumbers[$index] ?? ($index + 1);
                    $markerY = $height - $pad + 12 + (($index % 2) * 15);
                    ?>
                    <g>
                        <line x1="<?= round($x, 1) ?>" y1="<?= $height - $pad ?>" x2="<?= round($x, 1) ?>" y2="<?= $markerY - 7 ?>" class="med-line"/>
                        <circle cx="<?= round($x, 1) ?>" cy="<?= $markerY ?>" r="9" class="med-dot">
                            <title><?= e($number . ': ' . medication_label($med) . ', ' . display_datetime($med['event_at'])) ?></title>
                        </circle>
                        <text x="<?= round($x, 1) ?>" y="<?= $markerY + 4 ?>" text-anchor="middle" class="med-label"><?= e($number) ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>
            <div class="legend">
                <span><i class="swatch ok"></i> do 37 °C</span>
                <span><i class="swatch warning"></i> nad 37 °C</span>
                <span><i class="swatch danger"></i> nad 38 °C</span>
                <span><i class="swatch med"></i> podané léky</span>
            </div>
            <?php if ($meds): ?>
                <div class="med-events" aria-label="Podané léky v grafu">
                    <?php foreach ($medLegend as $label => $number): ?>
                        <span><?= e($number . ': ' . $label) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
