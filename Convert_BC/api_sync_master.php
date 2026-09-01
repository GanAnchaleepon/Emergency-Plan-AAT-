<?php
/**
 * Endpoint สำหรับปุ่ม "ดึงข้อมูลเดี๋ยวนี้" และแสดงสถานะรอบล่าสุด
 *   GET  ?action=status  -> ข้อมูลรอบดึงล่าสุด
 *   POST action=run      -> สั่งดึงทันที
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/MasterDataSync.php';

$config = require __DIR__ . '/../config/ftmseq.php';
$sync = new MasterDataSync($conn, $config);

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? 'run') : 'status';

if ($action === 'status') {
    $state = $sync->readState();
    echo json_encode([
        'status'      => 'ok',
        'configured'  => trim((string)($config['user'] ?? '')) !== '' && ($config['pass'] ?? '') !== '',
        'schedule'    => $config['master']['schedule'],
        'last_status' => $state['status'] ?? null,
        'last_message'=> $state['message'] ?? null,
        'last_time'   => isset($state['time']) ? date('d/m/Y H:i:s', (int)$state['time']) : null,
        'last_trigger'=> $state['trigger'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('max_execution_time', '300');

try {
    $result = $sync->run('manual');
    echo json_encode([
        'status'   => 'success',
        'message'  => $result['message'],
        'fetched'  => $result['fetched'],
        'imported' => $result['imported'],
        'duplicate'=> $result['duplicate'],
        'time'     => date('d/m/Y H:i:s', $result['time']),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
