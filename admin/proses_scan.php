<?php
header('Content-Type: application/json');
session_start();
require_once '../config/database.php';
require_once '../lib/core.php';
date_default_timezone_set('Asia/Jakarta');

// Endpoint khusus: admin login ATAU pos absensi yang sudah membuka kiosk dengan PIN
$kiosk_ok = isset($_SESSION['kiosk_unlocked']) && $_SESSION['kiosk_unlocked'] === true;
$admin_ok = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
if (!$admin_ok && !$kiosk_ok) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Buka pos absen dengan PIN atau login sebagai admin.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    $type = isset($_POST['type']) ? trim($_POST['type']) : 'hadir';
    $result = absensi_scan_admin($pdo, $_POST['qr_token'], $type);
    echo json_encode($result);
}
