<?php
/**
 * lib/core.php
 * Lapisan logika bisnis (domain logic) aplikasi Absensi SMAN 1 Bangunrejo.
 * Dipisahkan dari file halaman agar mudah diuji (testable) dengan TDD.
 * Semua fungsi murni (pure) kecuali yang membutuhkan PDO.
 */

if (!function_exists('absensi_is_terlambat')) {

    /**
     * Menentukan apakah sebuah jam scan melewati jam batas.
     * @param string|null $time  Waktu scan (H:i:s atau H:i)
     * @param string|null $batas Jam batas (H:i:s atau H:i)
     * @return bool
     */
    function absensi_is_terlambat($time, $batas)
    {
        if (empty($time) || empty($batas)) return false;
        return strtotime($time) > strtotime($batas);
    }

    /**
     * Menentukan status absensi: Hadir / Terlambat / status lain (Sakit/Izin/Alpa).
     * @param array       $row         Baris attendance
     * @param string|null $batas_hadir Jam batas absen hadir
     * @param string|null $batas_sholat Jam batas absen sholat
     * @return string
     */
    function absensi_status($row, $batas_hadir = null, $batas_sholat = null)
    {
        if (($row['status'] ?? '') !== 'Hadir') return $row['status'];
        $time_in = $row['time_in'] ?? '';
        if (absensi_is_terlambat($time_in, $batas_hadir)) {
            return 'Terlambat';
        }
        return 'Hadir';
    }

    /**
     * Mengambil seluruh pengaturan waktu absensi sebagai array asosiatif.
     * @param PDO $pdo
     * @return array<int|string, array>
     */
    function absensi_get_waktu($pdo)
    {
        $waktu = [];
        foreach ($pdo->query("SELECT * FROM waktu_absensi") as $row) {
            $waktu[$row['jenis']] = $row;
        }
        return $waktu;
    }

    /**
     * Format tanggal ke Bahasa Indonesia.
     * @param string $date Format Y-m-d
     * @return string
     */
    function absensi_format_tgl_id($date)
    {
        $months = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
        $d = new DateTime($date);
        return $d->format('d') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
    }

    /**
     * Inisial nama (maksimal 2 huruf).
     * @param string $name
     * @return string
     */
    function absensi_initials($name)
    {
        $words = preg_split('/\s+/', trim($name));
        $ini = '';
        foreach ($words as $w) {
            if ($w !== '') $ini .= strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($ini) >= 2) break;
        }
        return $ini !== '' ? $ini : '?';
    }

    /**
     * Warna avatar konsisten per nama.
     * @param string $name
     * @return string
     */
    function absensi_avatar_color($name)
    {
        $colors = array('#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#f97316');
        $idx = 0;
        for ($i = 0; $i < strlen($name); $i++) $idx += ord($name[$i]);
        return $colors[$idx % count($colors)];
    }

    /**
     * Menyusun query + parameter untuk laporan absensi berdasarkan filter.
     * @param PDO    $pdo
     * @param string $search_name
     * @param string $filter_class
     * @param string $filter_date
     * @return array{0: string, 1: array}
     */
    function absensi_laporan_query($pdo, $search_name = '', $filter_class = '', $filter_date = '')
    {
        $query_sql = "SELECT a.*, s.name, s.class, s.nisn FROM attendance a JOIN students s ON a.student_id = s.id WHERE 1=1";
        $params = [];
        if (!empty($search_name)) {
            $query_sql .= " AND s.name LIKE ?";
            $params[] = '%' . $search_name . '%';
        }
        if (!empty($filter_class)) {
            $query_sql .= " AND s.class = ?";
            $params[] = $filter_class;
        }
        if (!empty($filter_date)) {
            $query_sql .= " AND a.date = ?";
            $params[] = $filter_date;
        }
        $query_sql .= " ORDER BY a.date DESC, a.time DESC";
        return [$query_sql, $params];
    }

    /**
     * Mendapatkan daftar siswa yang Alpa (belum ada catatan) pada tanggal tertentu.
     * @param PDO    $pdo
     * @param string $filter_date
     * @param string $filter_class
     * @return array
     */
    function absensi_alpa_students($pdo, $filter_date, $filter_class = '')
    {
        if (empty($filter_date)) return [];
        $sql = "SELECT s.id, s.name, s.nisn, s.class FROM students s
                WHERE NOT EXISTS (
                    SELECT 1 FROM attendance a WHERE a.student_id = s.id AND a.date = ?
                )";
        $params = [$filter_date];
        if (!empty($filter_class)) {
            $sql .= " AND s.class = ?";
            $params[] = $filter_class;
        }
        $sql .= " ORDER BY s.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Autentikasi login. Mengembalikan baris user bila berhasil, null bila gagal.
     * @param PDO    $pdo
     * @param string $username
     * @param string $password
     * @return array|null
     */
    function absensi_auth($pdo, $username, $password)
    {
        $username = trim($username);
        if ($username === '' || $password === '') return null;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        return null;
    }

    /**
     * Proses scan absen hadir oleh Guru (satu jenis: hadir).
     * Mengembalikan array hasil (bukan JSON) agar mudah diuji.
     * @param PDO         $pdo
     * @param string      $qr_token
     * @param string|null $now        Format 'Y-m-d H:i:s', default waktu server
     * @return array
     */
    function absensi_scan_guru($pdo, $qr_token, $now = null)
    {
        $now = $now !== null ? $now : date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $current_time = substr($now, 11, 8);

        $stmt_student = $pdo->prepare("SELECT id, name, nis, nisn, class, foto FROM students WHERE qr_token = ?");
        $stmt_student->execute([trim($qr_token)]);
        $student = $stmt_student->fetch();

        if (!$student) {
            return ['status' => 'invalid', 'message' => 'Kode QR tidak dikenali atau siswa tidak terdaftar.'];
        }

        $student_id = $student['id'];
        $stmt_check = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
        $stmt_check->execute([$student_id, $today]);

        if ($stmt_check->rowCount() > 0) {
            return [
                'status' => 'already',
                'name' => $student['name'],
                'nisn' => $student['nisn'],
                'class' => $student['class'],
                'foto' => $student['foto'],
                'time' => $current_time
            ];
        }

        $stmt_insert = $pdo->prepare("INSERT INTO attendance (student_id, date, time, time_in, status) VALUES (?, ?, ?, ?, 'Hadir')");
        $stmt_insert->execute([$student_id, $today, $current_time, $current_time]);

        return [
            'status' => 'success',
            'name' => $student['name'],
            'nisn' => $student['nisn'],
            'class' => $student['class'],
            'foto' => $student['foto'],
            'time' => $current_time
        ];
    }

    /**
     * Proses scan absen oleh Admin (jenis: hadir/pulang/sholat).
     * Mengembalikan array hasil (bukan JSON) agar mudah diuji.
     * @param PDO         $pdo
     * @param string      $qr_token
     * @param string      $type
     * @param string|null $now Format 'Y-m-d H:i:s', default waktu server
     * @return array
     */
    function absensi_scan_admin($pdo, $qr_token, $type = 'hadir', $now = null)
    {
        $type = in_array($type, ['hadir', 'pulang', 'sholat'], true) ? $type : 'hadir';
        $now = $now !== null ? $now : date('Y-m-d H:i:s');
        $date = substr($now, 0, 10);
        $time = substr($now, 11, 8);

        $stmt = $pdo->prepare("SELECT id, name, nisn, class, foto FROM students WHERE qr_token = ?");
        $stmt->execute([trim($qr_token)]);
        $student = $stmt->fetch();

        if (!$student) {
            return ['status' => 'error', 'message' => 'QR Code tidak valid / tidak ditemukan.'];
        }

        $student_id = $student['id'];
        $cek = $pdo->prepare("SELECT id, time_in, time_out, time_sholat FROM attendance WHERE student_id = ? AND date = ?");
        $cek->execute([$student_id, $date]);
        $row = $cek->fetch();

        if ($type === 'hadir') {
            if ($row) {
                if (!empty($row['time_in'])) {
                    return ['status' => 'error', 'message' => $student['name'] . ' sudah absen Hadir hari ini.'];
                }
                $upd = $pdo->prepare("UPDATE attendance SET time_in = ?, time = ? WHERE id = ?");
                $upd->execute([$time, $time, $row['id']]);
                return ['status' => 'success', 'name' => $student['name'], 'nisn' => $student['nisn'], 'class' => $student['class'], 'foto' => $student['foto'], 'jenis' => 'Hadir', 'time' => $time];
            }
            $insert = $pdo->prepare("INSERT INTO attendance (student_id, date, time, time_in, status) VALUES (?, ?, ?, ?, 'Hadir')");
            $insert->execute([$student_id, $date, $time, $time]);
            return ['status' => 'success', 'name' => $student['name'], 'nisn' => $student['nisn'], 'class' => $student['class'], 'foto' => $student['foto'], 'jenis' => 'Hadir', 'time' => $time];
        }

        if ($type === 'pulang') {
            if (!$row) {
                return ['status' => 'error', 'message' => $student['name'] . ' belum absen Hadir hari ini.'];
            }
            if (!empty($row['time_out'])) {
                return ['status' => 'error', 'message' => $student['name'] . ' sudah absen Pulang hari ini.'];
            }
            $upd = $pdo->prepare("UPDATE attendance SET time_out = ?, time = ? WHERE id = ?");
            $upd->execute([$time, $time, $row['id']]);
            return ['status' => 'success', 'name' => $student['name'], 'nisn' => $student['nisn'], 'class' => $student['class'], 'foto' => $student['foto'], 'jenis' => 'Pulang', 'time' => $time];
        }

        // sholat
        if (!$row) {
            return ['status' => 'error', 'message' => $student['name'] . ' belum absen Hadir hari ini.'];
        }
        if (!empty($row['time_sholat'])) {
            return ['status' => 'error', 'message' => $student['name'] . ' sudah absen Sholat hari ini.'];
        }
        $upd = $pdo->prepare("UPDATE attendance SET time_sholat = ?, time = ? WHERE id = ?");
        $upd->execute([$time, $time, $row['id']]);
        return ['status' => 'success', 'name' => $student['name'], 'nisn' => $student['nisn'], 'class' => $student['class'], 'foto' => $student['foto'], 'jenis' => 'Sholat', 'time' => $time];
    }

    /**
     * Membuat file PNG QR Code secara lokal (tanpa API eksternal).
     * @param string $data    Data/token yang di-encode
     * @param string $outfile Path lengkap file output PNG
     * @param int    $size    Ukuran tiap modul dalam px
     * @param int    $margin  Margin modul
     * @return bool true bila file PNG berhasil dibuat
     */
    function absensi_qr_png($data, $outfile, $size = 8, $margin = 2)
    {
        static $loaded = false;
        if (!$loaded) {
            require_once dirname(__FILE__) . '/qrlib.php';
            $loaded = true;
        }
        try {
            @QRcode::png($data, $outfile, QR_ECLEVEL_L, $size, $margin);
            return is_file($outfile) && filesize($outfile) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
