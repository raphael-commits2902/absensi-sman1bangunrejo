<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../lib/core.php';
session_start();

date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    $result = absensi_scan_guru($pdo, $_POST['qr_token']);
    echo json_encode($result);
}
