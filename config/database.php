<?php
// Konfigurasi koneksi database.
// Di server produksi, gunakan environment variable (cPanel/php-fpm/shell):
//   DB_HOST, DB_NAME, DB_USER, DB_PASS
// Jika tidak diset, fallback ke konfigurasi lokal (XAMPP).
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'absensi_sman1bangunrejo';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die("Koneksi Database Gagal.");
}