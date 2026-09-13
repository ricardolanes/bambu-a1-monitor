<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';

$printerName = 'Bambu Lab A1';
$row = null;
$dbError = null;

try {
    $pdo = db_connect();
    $row = $pdo->query('SELECT * FROM snapshots ORDER BY captured_at DESC LIMIT 1')->fetch();
} catch (PDOException $e) {
    $dbError = 'Não consegui conectar no banco agora.';
}

$hasData = $row !== null;

if ($hasData) {
    $state    = state_info($row['gcode_state']);
    $percent  = $row['mc_percent'] !== null ? (int) $row['mc_percent'] : 0;
    $filament = parse_active_filament($row['ams_json'] ?? null, $row['vt_tray_json'] ?? null);
    $bars     = wifi_bars($row['wifi_signal'] ?? null);

    // anel de progresso via stroke-dasharray
    $ringR = 54;
    $ringCirc = 2 * M_PI * $ringR;
    $ringOffset = $ringCirc * (1 - $percent / 100);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel — <?= htmlspecialchars($printerName) ?></title>
<link rel="stylesheet" href="style.css?v=4">
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <?= $hasData ? icon_state_dot($state['tone']) : '' ?>
    <h1><?= htmlspecialchars($printerName) ?></h1>
  </div>
  <div class="topbar-right">
    <span id="updated-ago" class="muted"><?= $hasData ? htmlspecialchars(time_ago($row['captured_at'])) : '' ?></span>
    <span class="icon-sm tone-idle" id="wifi-icon"><?= $hasData ? icon_wifi($bars) : '' ?></span>
  </div>
</header>

<main>

<?php if ($dbError): ?>
  <div class="empty-state">
    <p><?= htmlspecialchars($dbError) ?></p>
    <p class="muted">Confere as credenciais em <code>config.php</code> e se o acesso remoto ao MySQL está liberado pra esse host.</p>
  </div>

<?php elseif (!$hasData): ?>
  <div class="empty-state">
    <p>Ainda não chegou nenhum snapshot.</p>
    <p class="muted">Confere se o <code>collect_status.py</code> já rodou pelo menos uma vez no cron.</p>
  </div>

<?php else: ?>

  <section class="hero">
    <div class="ring-wrap">
      <svg viewBox="0 0 120 120" class="progress-ring tone-<?= $state['tone'] ?>">
        <circle cx="60" cy="60" r="<?= $ringR ?>" class="ring-track"/>
        <circle cx="60" cy="60" r="<?= $ringR ?>" class="ring-fill"
          id="ring-fill"
          stroke-dasharray="<?= $ringCirc ?>"
          stroke-dashoffset="<?= $ringOffset ?>"/>
      </svg>
      <div class="ring-center">
        <span class="ring-percent" id="hero-percent"><?= $percent ?>%</span>
        <span class="ring-state" id="hero-state"><?= htmlspecialchars($state['label']) ?></span>
      </div>
    </div>

    <div class="hero-info">
      <h2 id="hero-name"><?= htmlspecialchars($row['subtask_name'] ?: 'sem tarefa ativa') ?></h2>
      <dl class="hero-stats">
        <div><dt>Camada</dt><dd id="hero-layer"><?= (int)($row['layer_num'] ?? 0) ?> / <?= (int)($row['total_layer_num'] ?? 0) ?></dd></div>
        <div><dt>Tempo restante</dt><dd id="hero-eta"><?= htmlspecialchars(format_minutes($row['mc_remaining_time'] !== null ? (int)$row['mc_remaining_time'] : null)) ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="gauges">
    <div class="gauge tone-hot">
      <span class="gauge-icon"><?= icon_nozzle() ?></span>
      <div class="gauge-text">
        <span class="gauge-label">Bico <span class="gauge-badge" id="g-nozzle-dia"><?= htmlspecialchars($row['nozzle_diameter'] ?? '?') ?>mm</span></span>
        <span class="gauge-value" id="g-nozzle"><?= htmlspecialchars(format_temp($row['nozzle_temper'])) ?></span>
        <span class="gauge-sub" id="g-nozzle-target">alvo <?= htmlspecialchars(format_temp($row['nozzle_target_temper'])) ?></span>
      </div>
    </div>

    <div class="gauge tone-hot2">
      <span class="gauge-icon"><?= icon_bed() ?></span>
      <div class="gauge-text">
        <span class="gauge-label">Mesa</span>
        <span class="gauge-value" id="g-bed"><?= htmlspecialchars(format_temp($row['bed_temper'])) ?></span>
        <span class="gauge-sub" id="g-bed-target">alvo <?= htmlspecialchars(format_temp($row['bed_target_temper'])) ?></span>
      </div>
    </div>

    <div class="gauge tone-idle">
      <span class="gauge-icon" id="g-spool-icon"><?= $filament ? icon_spool($filament['color']) : icon_spool('#4B4F5A') ?></span>
      <div class="gauge-text">
        <span class="gauge-label">Filamento</span>
        <span class="gauge-value" id="g-filament-type"><?= htmlspecialchars($filament['type'] ?? 'sem carretel') ?></span>
        <span class="gauge-sub" id="g-filament-color">
          <?php if ($filament): ?>
            <span class="color-swatch" id="g-filament-swatch" style="background:<?= htmlspecialchars($filament['color']) ?>"></span><span id="g-filament-hex"><?= htmlspecialchars($filament['color']) ?></span>
          <?php endif; ?>
        </span>
      </div>
    </div>
  </section>

  <section class="charts">
    <div class="chart-panel">
      <div class="chart-head">
        <h3>Temperaturas</h3>
        <div class="range-buttons" data-target="chart-temp">
          <button data-hours="1">1h</button>
          <button data-hours="6" class="active">6h</button>
          <button data-hours="24">24h</button>
        </div>
      </div>
      <canvas id="chart-temp" height="90"></canvas>
    </div>
  </section>

<?php endif; ?>

</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="app.js?v=4"></script>
</body>
</html>
