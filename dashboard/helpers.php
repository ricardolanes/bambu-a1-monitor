<?php

/** Estado bruto (gcode_state) -> rotulo em pt-BR + "tom" pra estilizacao. */
function state_info(?string $state): array
{
    return match ($state) {
        'RUNNING' => ['label' => 'Imprimindo', 'tone' => 'hot'],
        'PAUSE'   => ['label' => 'Pausado',    'tone' => 'idle'],
        'FINISH'  => ['label' => 'Concluído',  'tone' => 'ok'],
        'FAILED'  => ['label' => 'Falhou',     'tone' => 'error'],
        'IDLE'    => ['label' => 'Ocioso',     'tone' => 'idle'],
        default   => ['label' => 'Sem dados',  'tone' => 'idle'],
    };
}

/** "00AEEFFF" (RGBA hex da Bambu) -> "#00AEEF" (CSS). */
function bambu_color_to_css(?string $hex8): string
{
    $hex8 = ltrim((string) $hex8, '#');
    if (strlen($hex8) < 6 || !ctype_xdigit(substr($hex8, 0, 6))) {
        return '#8B8F9C'; // cinza neutro quando nao ha cor valida
    }
    return '#' . strtoupper(substr($hex8, 0, 6));
}

/** tray individual (do AMS ou vt_tray) -> forma normalizada pro template. */
function tray_to_filament(array $tray): array
{
    return [
        'type'   => $tray['tray_type'] ?: '—',
        'color'  => bambu_color_to_css($tray['tray_color'] ?? null),
        'remain' => isset($tray['remain']) && $tray['remain'] !== '' ? (int) $tray['remain'] : null,
    ];
}

/**
 * Decodifica ams_json + vt_tray_json e devolve o carretel ativo no formato
 * ['type'=>.., 'color'=>'#RRGGBB', 'remain'=>int|null], ou null se nada
 * carregado. Prioriza o tray apontado por tray_now; cai pro primeiro tray
 * com filamento, depois pro carretel externo (vt_tray).
 */
function parse_active_filament(?string $amsJson, ?string $vtTrayJson): ?array
{
    $ams = json_decode($amsJson ?? '', true) ?: [];
    $vt  = json_decode($vtTrayJson ?? '', true) ?: [];

    $trayNow = $ams['tray_now'] ?? null;
    if ($trayNow !== null && $trayNow !== '255' && !empty($ams['ams'])) {
        foreach ($ams['ams'] as $unit) {
            foreach (($unit['tray'] ?? []) as $tray) {
                if (($tray['id'] ?? null) === $trayNow && !empty($tray['tray_type'])) {
                    return tray_to_filament($tray);
                }
            }
        }
    }
    foreach (($ams['ams'] ?? []) as $unit) {
        foreach (($unit['tray'] ?? []) as $tray) {
            if (!empty($tray['tray_type'])) {
                return tray_to_filament($tray);
            }
        }
    }
    if (!empty($vt['tray_type'])) {
        return tray_to_filament($vt);
    }
    return null;
}

/** "-52dBm" -> 0 a 4 barras de sinal. */
function wifi_bars(?string $wifiSignal): int
{
    if (!$wifiSignal || !preg_match('/-?\d+/', $wifiSignal, $m)) {
        return 0;
    }
    $dbm = (int) $m[0];
    if ($dbm >= -50) return 4;
    if ($dbm >= -60) return 3;
    if ($dbm >= -70) return 2;
    return 1;
}

function format_temp($val): string
{
    return $val === null ? '—' : number_format((float) $val, 1) . '°C';
}

function format_minutes(?int $min): string
{
    if ($min === null) return '—';
    if ($min < 60) return "{$min} min";
    return sprintf('%dh %02dmin', intdiv($min, 60), $min % 60);
}

/**
 * captured_at (UTC) + minutos restantes -> data/hora prevista de termino,
 * em horario local (America/Sao_Paulo), formatado como "hoje as HH:MM",
 * "amanha as HH:MM" ou "DD/MM as HH:MM".
 */
function predicted_finish(string $capturedAtUtc, ?int $remainingMinutes): ?string
{
    if ($remainingMinutes === null) return null;

    $finish = new DateTime($capturedAtUtc, new DateTimeZone('UTC'));
    $finish->modify("+{$remainingMinutes} minutes");
    $finish->setTimezone(new DateTimeZone('America/Sao_Paulo'));

    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $time = $finish->format('H:i');

    if ($finish->format('Y-m-d') === $now->format('Y-m-d')) {
        return "hoje às {$time}";
    }
    $tomorrow = (clone $now)->modify('+1 day');
    if ($finish->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
        return "amanhã às {$time}";
    }
    return $finish->format('d/m') . " às {$time}";
}

/** captured_at (armazenado em UTC pelo coletor) -> "há Ns/min/h". */
function time_ago(string $mysqlDatetimeUtc): string
{
    $dt  = new DateTime($mysqlDatetimeUtc, new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 5) return 'agora mesmo';
    if ($diff < 60) return "há {$diff}s";
    if ($diff < 3600) return 'há ' . intdiv($diff, 60) . ' min';
    return 'há ' . intdiv($diff, 3600) . 'h';
}

/** captured_at (UTC) -> horario local formatado (America/Sao_Paulo). */
function to_local_time(string $mysqlDatetimeUtc, string $format = 'H:i:s'): string
{
    $dt = new DateTime($mysqlDatetimeUtc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return $dt->format($format);
}
