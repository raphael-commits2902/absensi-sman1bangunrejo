<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Unduh file template CSV (dengan BOM agar terbaca rapi di Excel)
$filename = 'template_import_siswa.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// BOM untuk kompatibilitas Microsoft Excel (UTF-8)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header
fputcsv($out, ['NISN', 'Nama', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin', 'Alamat']);

// Contoh baris (hapus sebelum import, atau biarkan — baris ini bisa diisi data siswa Anda)
fputcsv($out, ['0123456789', 'Budi Santoso', 'XII IPS 1', 'Bangunrejo', '2009-05-12', 'L', 'Desa Bangunrejo RT 01']);
fputcsv($out, ['0123456790', 'Siti Aminah', 'XII IPS 1', 'Gunung Sugih', '2009-08-03', 'P', 'Gunung Sugih Timur']);

fclose($out);
exit;