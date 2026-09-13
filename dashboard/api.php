<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

try {
    $pdo = db_connect();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha ao conectar no banco']);
    exit;
}

$action = $_GET['action'] ?? 'latest';

if ($action === 'latest') {
    $row = $pdo->query('SELECT * FROM snapshots ORDER BY captured_at DESC LIMIT 1')->fetch();
    echo json_encode($row ?: null);
    exit;
}

if ($action === 'history') {
    $hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 6;
    $hours = max(1, min(72, $hours));
    $stmt = $pdo->prepare(
        'SELECT captured_at, gcode_state, mc_percent, nozzle_temper, bed_temper, chamber_temper
         FROM snapshots
         WHERE captured_at >= (UTC_TIMESTAMP() - INTERVAL :hours HOUR)
         ORDER BY captured_at ASC'
    );
    $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode($stmt->fetchAll());
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'acao invalida']);
