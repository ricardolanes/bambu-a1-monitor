const REFRESH_MS = 15000;

const TONE = { RUNNING: 'hot', PAUSE: 'idle', FINISH: 'ok', FAILED: 'error', IDLE: 'idle' };
const LABEL = { RUNNING: 'Imprimindo', PAUSE: 'Pausado', FINISH: 'Concluído', FAILED: 'Falhou', IDLE: 'Ocioso' };

const RING_R = 54;
const RING_CIRC = 2 * Math.PI * RING_R;

function fmtTemp(v) {
  return v === null || v === undefined ? '—' : Number(v).toFixed(1) + '°C';
}

function fmtMinutes(min) {
  if (min === null || min === undefined) return '—';
  min = Number(min);
  if (min < 60) return `${min} min`;
  return `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}min`;
}

function predictedFinish(capturedAtUtc, remainingMinutes) {
  if (remainingMinutes === null || remainingMinutes === undefined) return '—';

  const finish = new Date(capturedAtUtc.replace(' ', 'T') + 'Z');
  finish.setUTCMinutes(finish.getUTCMinutes() + Number(remainingMinutes));

  const dayFmt = { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' };
  const time = finish.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });

  const now = new Date();
  const finishDay = finish.toLocaleDateString('pt-BR', dayFmt);
  const todayDay = now.toLocaleDateString('pt-BR', dayFmt);
  if (finishDay === todayDay) return `hoje às ${time}`;

  const tomorrow = new Date(now);
  tomorrow.setDate(tomorrow.getDate() + 1);
  if (finishDay === tomorrow.toLocaleDateString('pt-BR', dayFmt)) return `amanhã às ${time}`;

  const shortDate = finish.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit' });
  return `${shortDate} às ${time}`;
}

function timeAgo(mysqlUtc) {
  const then = new Date(mysqlUtc.replace(' ', 'T') + 'Z').getTime();
  const diff = Math.floor((Date.now() - then) / 1000);
  if (diff < 5) return 'agora mesmo';
  if (diff < 60) return `há ${diff}s`;
  if (diff < 3600) return `há ${Math.floor(diff / 60)} min`;
  return `há ${Math.floor(diff / 3600)}h`;
}

function bambuColorToCss(hex8) {
  if (!hex8 || hex8.length < 6) return '#8B8F9C';
  return '#' + hex8.slice(0, 6).toUpperCase();
}

function wifiBars(wifiSignal) {
  if (!wifiSignal) return 0;
  const m = String(wifiSignal).match(/-?\d+/);
  if (!m) return 0;
  const dbm = parseInt(m[0], 10);
  if (dbm >= -50) return 4;
  if (dbm >= -60) return 3;
  if (dbm >= -70) return 2;
  return 1;
}

function activeFilament(amsJson, vtTrayJson) {
  let ams = {}, vt = {};
  try { ams = amsJson ? JSON.parse(amsJson) : {}; } catch (e) {}
  try { vt = vtTrayJson ? JSON.parse(vtTrayJson) : {}; } catch (e) {}

  const trayNow = ams.tray_now;
  const units = ams.ams || [];

  if (trayNow && trayNow !== '255') {
    for (const unit of units) {
      for (const tray of (unit.tray || [])) {
        if (tray.id === trayNow && tray.tray_type) {
          return { type: tray.tray_type, color: bambuColorToCss(tray.tray_color), remain: tray.remain ?? null };
        }
      }
    }
  }
  for (const unit of units) {
    for (const tray of (unit.tray || [])) {
      if (tray.tray_type) {
        return { type: tray.tray_type, color: bambuColorToCss(tray.tray_color), remain: tray.remain ?? null };
      }
    }
  }
  if (vt.tray_type) {
    return { type: vt.tray_type, color: bambuColorToCss(vt.tray_color), remain: vt.remain ?? null };
  }
  return null;
}

function setToneClass(el, tone) {
  if (!el) return;
  el.classList.remove('tone-hot', 'tone-hot2', 'tone-cool', 'tone-ok', 'tone-error', 'tone-idle');
  el.classList.add('tone-' + tone);
}

function updateWifiIcon(bars) {
  const icon = document.getElementById('wifi-icon');
  if (!icon) return;
  const rects = icon.querySelectorAll('svg rect');
  rects.forEach((r, i) => { r.setAttribute('opacity', bars >= i + 1 ? '1' : '0.25'); });
}

function updateSpoolIcon(color) {
  const icon = document.getElementById('g-spool-icon');
  if (!icon) return;
  const ring = icon.querySelectorAll('svg circle')[1];
  if (ring) ring.setAttribute('stroke', color);
}

function applyLatest(row) {
  if (!row) return;
  lastCapturedAt = row.captured_at;

  const tone = TONE[row.gcode_state] || 'idle';
  const label = LABEL[row.gcode_state] || 'Sem dados';
  const percent = row.mc_percent ?? 0;

  const ring = document.getElementById('ring-fill');
  if (ring) {
    ring.setAttribute('stroke-dasharray', RING_CIRC);
    ring.setAttribute('stroke-dashoffset', RING_CIRC * (1 - percent / 100));
    setToneClass(ring.closest('.progress-ring'), tone);
  }
  setToneClass(document.querySelector('.state-dot'), tone);

  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('hero-percent', percent + '%');
  set('hero-state', label);
  set('hero-name', row.subtask_name || 'sem tarefa ativa');
  set('hero-layer', `${row.layer_num ?? 0} / ${row.total_layer_num ?? 0}`);
  set('hero-eta', fmtMinutes(row.mc_remaining_time));
  set('hero-finish', predictedFinish(row.captured_at, row.mc_remaining_time));
  set('g-nozzle', fmtTemp(row.nozzle_temper));
  set('g-nozzle-target', 'alvo ' + fmtTemp(row.nozzle_target_temper));
  set('g-nozzle-dia', (row.nozzle_diameter ?? '?') + 'mm');
  set('g-bed', fmtTemp(row.bed_temper));
  set('g-bed-target', 'alvo ' + fmtTemp(row.bed_target_temper));
  set('updated-ago', timeAgo(row.captured_at));

  const fil = activeFilament(row.ams_json, row.vt_tray_json);
  set('g-filament-type', fil ? fil.type : 'sem carretel');
  const colorWrap = document.getElementById('g-filament-color');
  if (colorWrap) {
    colorWrap.innerHTML = fil
      ? `<span class="color-swatch" style="background:${fil.color}"></span>${fil.color}`
      : '';
  }
  updateSpoolIcon(fil ? fil.color : '#4B4F5A');

  updateWifiIcon(wifiBars(row.wifi_signal));
}

async function pollLatest() {
  try {
    const res = await fetch('api.php?action=latest');
    const row = await res.json();
    applyLatest(row);
  } catch (e) {
    console.error('falha ao atualizar status', e);
  }
}

/* ---- graficos ---- */

const chartColors = {
  nozzle: '#FF5A36',
  bed: '#FF9F1C',
  grid: '#2C2F38',
  text: '#8B8F9C',
};

function baseChartOptions(yLabel) {
  return {
    responsive: true,
    animation: false,
    interaction: { mode: 'index', intersect: false },
    scales: {
      x: {
        grid: { color: chartColors.grid },
        ticks: { color: chartColors.text, maxTicksLimit: 8, font: { family: 'IBM Plex Mono', size: 10.5 } },
      },
      y: {
        title: { display: !!yLabel, text: yLabel, color: chartColors.text, font: { size: 11 } },
        grid: { color: chartColors.grid },
        ticks: { color: chartColors.text, font: { family: 'IBM Plex Mono', size: 10.5 } },
      },
    },
    plugins: {
      legend: { labels: { color: chartColors.text, font: { size: 11.5 }, boxWidth: 12 } },
    },
  };
}

let tempChart;
let lastCapturedAt = null;

function timeLabel(mysqlUtc) {
  const d = new Date(mysqlUtc.replace(' ', 'T') + 'Z');
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
}

function buildCharts() {
  const tempCtx = document.getElementById('chart-temp');
  if (!tempCtx) return;

  tempChart = new Chart(tempCtx, {
    type: 'line',
    data: { labels: [], datasets: [
      { label: 'Bico', data: [], borderColor: chartColors.nozzle, backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
      { label: 'Mesa', data: [], borderColor: chartColors.bed, backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
    ]},
    options: baseChartOptions('°C'),
  });
}

async function loadHistory(hours) {
  try {
    const res = await fetch(`api.php?action=history&hours=${hours}`);
    const rows = await res.json();
    const labels = rows.map(r => timeLabel(r.captured_at));

    if (tempChart) {
      tempChart.data.labels = labels;
      tempChart.data.datasets[0].data = rows.map(r => r.nozzle_temper);
      tempChart.data.datasets[1].data = rows.map(r => r.bed_temper);
      tempChart.update();
    }
  } catch (e) {
    console.error('falha ao carregar historico', e);
  }
}

function wireRangeButtons() {
  document.querySelectorAll('.range-buttons').forEach(group => {
    group.querySelectorAll('button').forEach(btn => {
      btn.addEventListener('click', () => {
        group.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        loadHistory(btn.dataset.hours);
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  buildCharts();
  loadHistory(6);
  wireRangeButtons();
  pollLatest();
  setInterval(pollLatest, REFRESH_MS);
  setInterval(() => {
    if (!lastCapturedAt) return;
    const el = document.getElementById('updated-ago');
    if (el) el.textContent = timeAgo(lastCapturedAt);
  }, 1000);
});
