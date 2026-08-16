<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../lib/core.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    $type = isset($_POST['type']) ? trim($_POST['type']) : 'hadir';
    $result = absensi_scan_admin($pdo, $_POST['qr_token'], $type);
    echo json_encode($result);
}
