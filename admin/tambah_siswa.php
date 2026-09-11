<?php
// Tampilkan error saat pengembangan

session_start();
require_once '../config/database.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success = '';
$error = '';

$import_success = '';
$import_errors = [];

// Ambil daftar kelas dari database
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

/**
 * Simpan & kompres foto siswa (resize maks 400px, output JPG kualitas 85).
 * @param string $src  Path file sumber gambar
 * @param string $nisn NISN untuk penamaan file
 * @return string Path relatif foto (mis. assets/foto_siswa/123.jpg) atau '' bila gagal
 */
function absensi_save_photo($src, $nisn)
{
    if (!is_dir('../assets/foto_siswa')) @mkdir('../assets/foto_siswa', 0777, true);
    if (!is_file($src) || filesize($src) <= 0) return '';
    $info = @getimagesize($src);
    if (!$info) return '';
    $img = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src);  break;
        case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($src); break;
        default: return '';
    }
    if (!$img) return '';

    $w = imagesx($img);
    $h = imagesy($img);
    $max = 400;
    if ($w > $max || $h > $max) {
        $ratio = $max / max($w, $h);
        $nw = (int)($w * $ratio);
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefill($dst, 0, 0, $transparent);
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }

    $foto_name = $nisn . '_' . time() . '.jpg';
    $out = '../assets/foto_siswa/' . $foto_name;
    $ok = @imagejpeg($img, $out, 85);
    imagedestroy($img);
    return $ok ? 'assets/foto_siswa/' . $foto_name : '';
}

/**
 * Membaca file spreadsheet menjadi array baris.
 * Mendukung .xlsx (ZIP berisi XML — di-parse langsung, tanpa Composer) dan .csv/.txt.
 * @param string $file Parser: jalur file yang diunggah
 * @param string $ext  Ekstensi file (xlsx / csv / txt)
 * @return array Array baris (masing-masing array kolom)
 */
function absensi_spreadsheet_rows($file, $ext)
{
    $ext = strtolower($ext);
    $rows = [];
    if ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) return $rows;

        // Muat shared strings (teks di Excel sering disimpan di sini, dirujuk via indeks)
        $shared = [];
        if ($ssXml = $zip->getFromName('xl/sharedStrings.xml')) {
            $ssXml = preg_replace('/xmlns(:[a-z0-9]+)?="[^"]*"/i', '', $ssXml);
            $ss = @simplexml_load_string($ssXml);
            if ($ss) foreach ($ss->si ?? [] as $si) {
                $t = '';
                foreach ($si->t ?? [] as $tt) $t .= (string)$tt;
                $shared[] = $t;
            }
        }

        // Sheet pertama biasanya xl/worksheets/sheet1.xml (fallback cari sheet pertama)
        $sheet = null;
        for ($i = 1; $i <= 20; $i++) {
            $name = 'xl/worksheets/sheet' . $i . '.xml';
            if ($zip->getFromName($name) !== false) { $sheet = $name; break; }
        }
        if (!$sheet) { $zip->close(); return $rows; }
        $xml = $zip->getFromName($sheet);
        $zip->close();

        // Hapus deklarasi namespace default agar SimpleXML bisa mengakses elemen langsung
        $xml = preg_replace('/xmlns(:[a-z0-9]+)?="[^"]*"/i', '', $xml);

        $sx = @simplexml_load_string($xml);
        if (!$sx) return $rows;
        // Batasi jumlah baris yang diproses (anti-macet untuk file sangat besar/rusak)
        $max_rows = 5000;
        // Baris berada di dalam <sheetData>, bukan anak langsung <worksheet>
        $sheetData = $sx->sheetData ?? $sx;
        foreach ($sheetData->row ?? [] as $row) {
            if (count($rows) >= $max_rows) break;
            $cols = [];
            foreach ($row->c ?? [] as $cell) {
                $type = (string)($cell['t'] ?? '');
                // Tandai sel ANGKA: tanpa atribut t (default "n") atau t="n".
                // Dipakai aplikasi untuk memulihkan nol depan NISN yang dibuang Excel.
                if ($type === '' || $type === 'n') {
                    $cols[] = ['v' => isset($cell->v) ? trim((string)$cell->v) : '', 'num' => true];
                } elseif ($type === 'inlineStr') {
                    $cols[] = ['v' => trim((string)$cell->is->t), 'num' => false];
                } elseif ($type === 's' && isset($cell->v)) {
                    $idx = (int)trim((string)$cell->v);
                    $cols[] = ['v' => $shared[$idx] ?? '', 'num' => false];
                } else {
                    $cols[] = ['v' => isset($cell->v) ? trim((string)$cell->v) : '', 'num' => false];
                }
            }
            $rows[] = $cols;
        }
    } else {
        // CSV / TXT: deteksi delimiter seperti proses utama
        $h = fopen($file, 'r');
        if (!$h) return $rows;
        $first = fgets($h); rewind($h);
        $delim = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if (count($rows) >= 5000) break;
            $rows[] = $r;
        }
        fclose($h);
    }
    return $rows;
}

/**
 * Normalisasi NISN — APLIKASI yang mengubah angka menjadi teks.
 *  - Sel TEKS  : dipertahankan apa adanya (nol depan utuh). "0106982596" -> "0106982596"
 *  - Sel ANGKA : Excel sudah membuang nol depan. Dipulihkan otomatis menjadi 10 digit
 *                (standar NISN nasional): 106982596 -> 0106982596.
 *                Jika >= 10 digit, dipakai apa adanya (nol depan memang tidak ada).
 *  - "3.10371048E+9" (notasi ilmiah) -> "3103710480"
 *  - Serial tanggal di kolom NISN (46266.69) -> ditolak ('')
 * @param string $v      Nilai mentah sel
 * @param bool   $is_num true bila sel Excel bertipe angka
 * @return string Digit bersih, atau '' bila tidak valid.
 */
function absensi_normalize_nisn($v, $is_num = false)
{
    $v = trim((string)$v);
    if ($v === '') return '';

    // Notasi ilmiah Excel: 3.10371048E+9 -> 3103710480
    if (preg_match('/^\d(\.\d+)?E\+?(\d+)$/i', $v)) {
        $v = number_format((float)$v, 0, '', '');
        $is_num = true;
    }

    // Serial tanggal Excel di kolom NISN (mis. 46266.69) -> jelas salah kolom, tolak
    if (preg_match('/^\d{4,5}\.\d+$/', $v)) return '';

    // Pertahankan nol depan: ambil digit apa adanya tanpa konversi angka
    $digits = preg_replace('/\D/', '', $v);
    if ($digits === '' || strlen($digits) < 4 || strlen($digits) > 15) return '';

    // Sel ANGKA (bukan teks): Excel membuang nol depannya -> pulihkan ke 10 digit NISN
    if ($is_num && strlen($digits) === 9 && $digits[0] !== '0') {
        $digits = '0' . $digits;
    }
    // Sel TEKS: biarkan apa adanya (nol depan utuh, tanpa perubahan)
    return $digits;
}

/**
 * Normalisasi tanggal lahir dari Excel/CSV — menerima DATE dan TEKS:
 *  - Objek/tanggal asli Excel: nilai serial (mis. 39911) jika kolom diformat date
 *  - Serial number Excel (39911) -> 2009-04-01
 *  - "2009-05-12", "2009/05/12", "12-05-2009", "12/05/2009"
 *  - "12 Mei 2009" (nama bulan Indonesia)
 * @param string $v
 * @return string|null Y-m-d atau null bila tidak valid
 */
function absensi_normalize_tanggal($v, $is_num = false)
{
    $v = trim((string)$v);
    if ($v === '') return null;

    // Sel ANGKA dari Excel = serial tanggal otomatis (tanggal asli Excel)
    if ($is_num && preg_match('/^\d{1,7}(\.\d+)?$/', $v)) {
        $serial = (float)$v;
        if ($serial >= 20000 && $serial <= 60000) {
            $base = new DateTime('1899-12-30');
            $base->modify('+' . floor($serial) . ' days');
            return $base->format('Y-m-d');
        }
        return null;
    }

    // TEKS: bisa berupa serial Excel (jika diketik manual) atau format tanggal teks
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $v)) {
        $serial = (float)$v;
        if ($serial >= 20000 && $serial <= 60000) {
            $base = new DateTime('1899-12-30');
            $base->modify('+' . floor($serial) . ' days');
            return $base->format('Y-m-d');
        }
        return null;
    }

    $raw = str_replace('/', '-', $v);
    // TEKS: Y-m-d atau d-m-Y
    foreach (array('Y-m-d', 'd-m-Y') as $fmt) {
        $d = DateTime::createFromFormat($fmt, $raw);
        if ($d && $d->format($fmt) === $raw) {
            return $d->format('Y-m-d');
        }
    }
    // TEKS: nama bulan Indonesia, "12 Mei 2009"
    $bulan = ['januari'=>'01','februari'=>'02','maret'=>'03','april'=>'04','mei'=>'05','juni'=>'06',
              'juli'=>'07','agustus'=>'08','september'=>'09','oktober'=>'10','november'=>'11','desember'=>'12'];
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $v, $m)) {
        $b = strtolower($m[2]);
        if (isset($bulan[$b])) {
            return sprintf('%04d-%02d-%02d', $m[3], $bulan[$b], (int)$m[1]);
        }
    }
    return null;
}

/**
 * Download & simpan foto dari Google Drive (share link) lalu kompres.
 * Mendukung format link:
 *  - https://drive.google.com/file/d/FILE_ID/view
 *  - https://drive.google.com/open?id=FILE_ID
 *  - https://drive.google.com/uc?id=FILE_ID
 * @param string $drive  Link / teks Google Drive
 * @param string $nisn   Untuk penamaan file
 * @return string Path relatif foto ('' bila gagal)
 */
function absensi_foto_from_drive($drive, $nisn)
{
    $drive = trim((string)$drive);
    if ($drive === '') return '';
    // Ekstrak File ID dari berbagai format URL Drive
    if (preg_match('#/file/d/([A-Za-z0-9_-]+)#', $drive, $m)) {
        $id = $m[1];
    } elseif (preg_match('#[?&](?:id|openid)=([A-Za-z0-9_-]+)#', $drive, $m)) {
        $id = $m[1];
    } elseif (preg_match('#^([A-Za-z0-9_-]{20,})$#', $drive, $m)) {
        $id = $m[1];
    } else {
        return '';
    }
    if (empty($id)) return '';
    $url = 'https://drive.google.com/uc?export=download&id=' . urlencode($id);

    // Timeout kecil (4 detik) agar baris tidak menggantung lama bila link tidak valid.
    // Import ratusan baris tidak boleh terkunci menunggu download tiap siswa.
    $ctx = stream_context_create([
        'http' => ['timeout' => 4, 'follow_location' => 1, 'user_agent' => 'Mozilla/5.0'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $img = @file_get_contents($url, false, $ctx);
    if ($img === false || strlen($img) < 100) return '';

    $tmp = tempnam(sys_get_temp_dir(), 'gd') . '.img';
    @file_put_contents($tmp, $img);
    $foto = absensi_save_photo($tmp, $nisn);
    @unlink($tmp);
    return $foto;
}

// ============ PROSES IMPORT MASAL (ZIP / Excel / CSV + Foto + Foto Drive) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['file_zip']) || isset($_FILES['file_csv']))) {

    $import_success = '';
    $import_errors = [];
    $tmp_dir = null;

    // Sumber baris data & foto
    $data_rows = [];
    $photo_dir = null;
    $uploaded_photos = []; // nisn => tmp path (dari input file_fotos[])
    $photo_source_is_zip = false;

    // Kumpulkan foto yang di-upload langsung (file_fotos[])
    if (isset($_FILES['file_fotos']) && is_array($_FILES['file_fotos']['name'])) {
        $pf = $_FILES['file_fotos'];
        $n = count($pf['name']);
        for ($i = 0; $i < $n; $i++) {
            if ($pf['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $pf['name'][$i];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
            $nisn_foto = pathinfo($orig, PATHINFO_FILENAME);
            $uploaded_photos[$nisn_foto] = $pf['tmp_name'][$i];
        }
    }

    if (isset($_FILES['file_zip']) && $_FILES['file_zip']['error'] === UPLOAD_ERR_OK) {
        $zip_ext = strtolower(pathinfo($_FILES['file_zip']['name'], PATHINFO_EXTENSION));
        if ($zip_ext !== 'zip') {
            $import_success = 'Format file harus ZIP.';
            $import_errors[] = 'error';
        } else {
            $tmp_dir = sys_get_temp_dir() . '/absensi_imp_' . uniqid();
            @mkdir($tmp_dir, 0777, true);
            $zip = new ZipArchive();
            if ($zip->open($_FILES['file_zip']['tmp_name']) !== true) {
                $import_success = 'Gagal membuka file ZIP. Pastikan ZIP tidak rusak.';
                $import_errors[] = 'error';
            } else {
                $zip->extractTo($tmp_dir);
                $zip->close();
                // Cari file data (.csv / .xlsx) di mana saja di dalam ZIP (lewati folder junk)
                $data_found = null;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_dir, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $fe = strtolower($f->getExtension());
                    if (in_array($fe, ['csv', 'xlsx'])) { $data_found = $f->getPathname(); break; }
                }
                if (!$data_found) {
                    $import_success = 'File ZIP tidak berisi file data (.csv / .xlsx).';
                    $import_errors[] = 'error';
                } else {
                    $data_rows = absensi_spreadsheet_rows($data_found, pathinfo($data_found, PATHINFO_EXTENSION));
                    $photo_dir = $tmp_dir;
                    $photo_source_is_zip = true;
                }
            }
        }
    } elseif (isset($_FILES['file_csv']) && $_FILES['file_csv']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file_csv'];
        if ($file['size'] <= 0) {
            $import_success = 'File yang diunggah kosong.';
            $import_errors[] = 'error';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'])) {
                $import_success = 'Format file harus CSV (.csv) atau Excel (.xlsx) — kolom: NISN, Nama, Kelas.';
                $import_errors[] = 'error';
            } else {
                $data_rows = absensi_spreadsheet_rows($file['tmp_name'], $ext);
            }
        }
    } else {
        $import_success = 'Pilih file ZIP, Excel, atau CSV terlebih dahulu.';
        $import_errors[] = 'error';
    }

    if (!empty($data_rows)) {

        // Jangan batasi waktu eksekusi — import masal bisa memakan waktu lama, dan
        // batas default 120s menyebabkan fatal "Maximum execution time exceeded".
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        // LEPAS KUNCI SESSION — tanpa ini, halaman lain (login/dashboard/logout)
        // ikut mengantre di session_start() selama import berjalan dan terasa "hang".
        @session_write_close();

        $berhasil = 0;
        $duplikat = 0;
        $gagal = 0;
        $baris = 0;
        $row_errors = [];
        $nisn_seen = [];
        $drive_deadline = null;  // anggaran waktu total unduhan foto Drive
        $drive_skipped = 0;      // jumlah foto Drive yang dilewati karena budget habis

        // Preload NISN siswa yang sudah terdaftar (sekali saja, hindari SELECT per baris)
        foreach ($pdo->query("SELECT nisn FROM students WHERE nisn <> ''") as $r) {
            $nisn_seen[$r['nisn']] = true;
        }
        // Preload kelas yang sudah ada (sekali saja)
        $kelas_ada = [];
        foreach ($pdo->query("SELECT name FROM kelas") as $r) {
            $kelas_ada[$r['name']] = true;
        }

        // Helper: ambil foto berdasarkan NISN.
        // 1) Dari upload langsung (file_fotos[]) 2) Dari folder ZIP (rekursif, NISN sebagai prefix)
        $find_photo = function ($dir, $nisn) use ($uploaded_photos) {
            // Foto yang di-upload langsung lewat input file_fotos[]
            if (isset($uploaded_photos[$nisn])) return $uploaded_photos[$nisn];
            if (!$dir) return null;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
                $name = pathinfo($f->getFilename(), PATHINFO_FILENAME);
                if ($name === $nisn || strpos($name, $nisn) === 0) return $f->getPathname();
            }
            return null;
        };

        // Mulai transaksi besar SEKALI untuk seluruh baris (jauh lebih cepat)
        $pdo->beginTransaction();

        $first = true;
        foreach ($data_rows as $row) {
            $baris++;

            // Ambil nilai sel + tanda sel-angka (xlsx memberi array ['v'=>,'num'=>], CSV string biasa)
            $cell_val = function ($i) use ($row) {
                if (!isset($row[$i])) return ['', false];
                $c = $row[$i];
                if (is_array($c)) return [$c['v'], !empty($c['num'])];
                return [trim((string)$c), false];
            };
            list($raw0, $nisn_is_num) = $cell_val(0);

            // Lewati baris kosong, header, atau baris judul/petunjuk template
            $kolom1 = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw0));
            $is_header = (strtolower($kolom1) === 'nisn');
            if ($is_header && $first) { $first = false; continue; }
            if (stripos($kolom1, 'TEMPLATE IMPORT') === 0 || stripos($kolom1, 'PENTING:') === 0) continue;
            $flat = [];
            foreach ($row as $c) { $flat[] = is_array($c) ? $c['v'] : $c; }
            if (count($row) < 3 || trim(implode('', $flat)) === '') continue;

            // NISN: aplikasi mengubah angka -> teks (memulihkan nol depan yang dibuang Excel)
            $nisn = absensi_normalize_nisn($kolom1, $nisn_is_num);
            $name = trim($cell_val(1)[0]);
            $class = trim($cell_val(2)[0]);
            $tempat_lahir = trim($cell_val(3)[0]);
            $tanggal_lahir = trim($cell_val(4)[0]);
            $tanggal_is_num = $cell_val(4)[1];
            $jenis_kelamin = trim($cell_val(5)[0]);
            $alamat = trim($cell_val(6)[0]);
            $foto_link_drive = trim($cell_val(7)[0]); // kolom Foto = link Google Drive

            // Normalisasi tanggal lahir — menerima DATE (serial Excel) dan TEKS
            $tgl_lahir_db = absensi_normalize_tanggal($tanggal_lahir, $tanggal_is_num);
            // Normalisasi jenis kelamin: terima L/P, laki-laki/perempuan, pria/wanita, dll.
            $jk_upper = strtoupper(trim($jenis_kelamin));
            if (in_array($jk_upper, array('L', 'LAKI', 'LAKI-LAKI', 'LAKI LAKI', 'PRIA', 'COWOK', 'MALE'))) {
                $jenis_kelamin = 'L';
            } elseif (in_array($jk_upper, array('P', 'PEREMPUAN', 'WANITA', 'CEWEK', 'CEW', 'FEMALE'))) {
                $jenis_kelamin = 'P';
            } else {
                $jenis_kelamin = null;
            }

            if (empty($nisn) || empty($name) || empty($class)) {
                $gagal++;
                $row_errors[] = "Baris $baris: kolom tidak lengkap / NISN tidak valid (NISN, Nama, Kelas wajib diisi).";
                continue;
            }

            // NISN duplikat: baris pertama dipakai, duplikat berikutnya DILEWATI/dibuang
            if (isset($nisn_seen[$nisn])) {
                $duplikat++;
                $row_errors[] = "Baris $baris: NISN <strong>$nisn</strong> muncul lebih dari satu kali — duplikat dibuang, hanya baris pertama yang disimpan.";
                continue;
            }
            $nisn_seen[$nisn] = true;

            // Cari foto (dari upload langsung / ZIP)
            $isi_foto = null;
            $foto = '';
            $src = $find_photo($photo_dir, $nisn);
            if ($src && is_file($src) && filesize($src) > 0) {
                $isi_foto = $src;
            }

            // Simpan & kompres foto (jika ditemukan)
            if ($isi_foto) {
                $foto = absensi_save_photo($isi_foto, $nisn);
                if ($foto === '' && $photo_source_is_zip) {
                    // Fallback: salin apa adanya bila kompresi gagal
                    $ext = strtolower(pathinfo($isi_foto, PATHINFO_EXTENSION));
                    $foto_name = $nisn . '_' . time() . '.' . $ext;
                    if (!is_dir('../assets/foto_siswa')) @mkdir('../assets/foto_siswa', 0777, true);
                    if (copy($isi_foto, '../assets/foto_siswa/' . $foto_name)) $foto = 'assets/foto_siswa/' . $foto_name;
                }
            }
            // Foto dari link Google Drive (kolom ke-8) — prioritas jika foto lokal tidak ada.
            // Ada BUDGET WAKTU TOTAL (60s): setelah itu unduhan dihentikan agar import
            // tidak berjam-jam. Foto yang gagal dilaporkan & bisa dilengkapi via Edit.
            if ($foto === '' && !empty($foto_link_drive)) {
                if ($drive_deadline === null) $drive_deadline = microtime(true) + 60;
                if (microtime(true) < $drive_deadline) {
                    $foto = absensi_foto_from_drive($foto_link_drive, $nisn);
                    if ($foto === '') {
                        $row_errors[] = "Baris $baris: $nisn — link foto Google Drive gagal diunduh (pastikan di-share 'Siapa saja dengan link').";
                    }
                } else {
                    $drive_skipped++;
                    $row_errors[] = "Baris $baris: $nisn — foto Drive dilewati (anggaran waktu unduh habis). Lengkapi lewat menu Edit.";
                }
            }

            try {
                // Pastikan kelas terdaftar di tabel kelas (auto-daftar jika belum ada)
                if (!isset($kelas_ada[$class])) {
                    $pdo->prepare("INSERT INTO kelas (name) VALUES (?)")->execute([$class]);
                    $kelas_ada[$class] = true;
                }

                // Buat akun login: username & password default = NISN
                // (password_hash dijalankan DI DALAM transaksi besar — bukan commit per baris.
                //  cost 8 agar import masal jauh lebih cepat; password_verify tetap bekerja.)
                $hashed_password = password_hash($nisn, PASSWORD_BCRYPT, ['cost' => 8]);
                $stmt_user = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                $stmt_user->execute([$nisn, $hashed_password]);
                $user_id = $pdo->lastInsertId();

                // Generate Token QR Code Unik
                $random_string = bin2hex(random_bytes(16));
                $qr_token = $random_string . '_' . $nisn;

                $stmt_student = $pdo->prepare("INSERT INTO students (user_id, nis, nisn, name, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat, foto, class, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_student->execute([$user_id, $nisn, $nisn, $name, $tempat_lahir, $tgl_lahir_db, $jenis_kelamin, $alamat, $foto, $class, $qr_token]);

                $berhasil++;
            } catch (Exception $e) {
                $gagal++;
                $row_errors[] = "Baris $baris: $nisn — " . $e->getMessage();
            }
        }

        // Satu commit untuk seluruh batch. Baris yang "dilewati" (duplikat/kolom
        // tidak lengkap) sudah di-skip sebelum insert & dicatat di $row_errors,
        // jadi transaksi tetap konsisten. Jika ada error DB nyata, rollback.
        try {
            if ($pdo->inTransaction()) $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $gagal += $berhasil;
            $berhasil = 0;
            $row_errors[] = "Transaksi dibatalkan: " . $e->getMessage();
        }

        // Bersihkan folder ekstraksi ZIP
        if ($tmp_dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($tmp_dir);
        }

        $import_success = "Import selesai! <strong>$berhasil siswa</strong> berhasil ditambahkan.";
        if ($duplikat > 0) {
            $import_success .= " <strong>$duplikat</strong> dilewati karena NISN duplikat.";
        }
        if ($gagal > 0) {
            $import_success .= " <strong>$gagal</strong> baris gagal diproses.";
        }
        if ($drive_skipped > 0) {
            $import_success .= " <strong>$drive_skipped</strong> foto Drive dilewati karena anggaran waktu unduh habis — lengkapi lewat menu Edit.";
        }
        $import_errors = $row_errors;
    }
}


// ============ PROSES TAMBAH SISWA SATU PER SATU ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['file_csv'])) {
    $nisn = trim($_POST['nisn']);
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);
    $tempat_lahir = trim(isset($_POST['tempat_lahir']) ? $_POST['tempat_lahir'] : '');
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = isset($_POST['jenis_kelamin']) ? strtoupper(trim($_POST['jenis_kelamin'])) : null;
    if (!in_array($jenis_kelamin, array('L', 'P'))) $jenis_kelamin = null;
    $alamat = trim(isset($_POST['alamat']) ? $_POST['alamat'] : '');
    $foto = '';

    // Validasi input tidak boleh kosong
    if(!empty($nisn) && !empty($name) && !empty($class)) {
        try {
            // 1. Cek apakah NISN sudah ada untuk mencegah duplikasi
            $stmt_check = $pdo->prepare("SELECT id FROM students WHERE nisn = ?");
            $stmt_check->execute([$nisn]);
            
            if($stmt_check->rowCount() > 0) {
                $error = 'Gagal: Siswa dengan NISN <strong>' . htmlspecialchars($nisn) . '</strong> sudah terdaftar!';
            } else {
                // Upload foto (opsional)
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $allowed = array('jpg', 'jpeg', 'png', 'webp');
                    if (in_array($ext, $allowed) && $_FILES['foto']['size'] <= 2097152) {
                        $foto_name = $nisn . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], '../assets/foto_siswa/' . $foto_name)) {
                            $foto = 'assets/foto_siswa/' . $foto_name;
                        }
                    } else {
                        $error = 'Foto harus berformat JPG/PNG/WEBP maksimal 2MB.';
                    }
                }

                if (empty($error)) {
                    // Mulai transaksi database
                    $pdo->beginTransaction();
                    
                    // 2. Buat Password default = NISN
                    $hashed_password = password_hash($nisn, PASSWORD_DEFAULT);
                    
                    // 3. Masukkan ke tabel users
                    $stmt_user = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                    $stmt_user->execute([$nisn, $hashed_password]);
                    
                    // Ambil ID dari user yang baru dibuat
                    $user_id = $pdo->lastInsertId();
                    
                    // 4. Generate Token QR Code Aman & Unik
                    if (function_exists('random_bytes')) {
                        $random_string = bin2hex(random_bytes(16));
                    } else {
                        $random_string = md5(uniqid(rand(), true));
                    }
                    $qr_token = $random_string . '_' . $nisn;
                    
                    // 5. Masukkan ke tabel students
                    $stmt_student = $pdo->prepare("INSERT INTO students (user_id, nis, nisn, name, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat, foto, class, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_student->execute([$user_id, $nisn, $nisn, $name, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $alamat, $foto, $class, $qr_token]);
                    
                    // Konfirmasi transaksi berhasil
                    $pdo->commit();
                    $success = 'Berhasil! Data siswa <strong>' . htmlspecialchars($name) . '</strong> telah ditambahkan. QR Code siap digunakan.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database Error: ' . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Sistem Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Perhatian: NISN, Nama, dan Kelas wajib diisi!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Siswa - Admin Panel</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* CSS Reset & Variabel Konsisten */
        :root {
            --primary: #3b82f6; --primary-hover: #2563eb; --primary-light: #eff6ff;
            --success: #10b981; --success-light: #d1fae5;
            --warning: #f59e0b; --warning-light: #fef3c7;
            --danger: #ef4444; --danger-light: #fee2e2;
            --dark: #0f172a; --dark-secondary: #1e293b;
            --gray: #64748b; --bg-light: #f8fafc; --border: #e2e8f0;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); color: var(--dark-secondary); overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar { width: 260px; background: var(--dark); color: #94a3b8; padding: 24px 20px; position: fixed; height: 100vh; top: 0; left: 0; z-index: 1000; transition: transform 0.3s ease; transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,0.35); }
        .sidebar.active { transform: translateX(0); }
        .sidebar-brand { color: white; font-size: 14px; font-weight: 800; margin-bottom: 40px; display: flex; align-items: center; gap: 12px; padding-left: 10px; }
        .sidebar-brand i { color: var(--primary); font-size: 24px; }
        .sidebar-brand img.brand-logo { width: 56px; height: 56px; border-radius: 12px; background: white; padding: 6px; object-fit: contain; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li { margin-bottom: 8px; }
        .sidebar-menu a { display: flex; align-items: center; gap: 14px; padding: 12px 16px; color: #94a3b8; text-decoration: none; border-radius: 10px; font-weight: 500; transition: all 0.2s ease; }
        .sidebar-menu a i { font-size: 18px; width: 24px; text-align: center; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: var(--dark-secondary); color: white; }
        
        /* Submenu Absensi */
        .submenu-arrow { margin-left: auto; font-size: 12px; transition: transform 0.3s ease; }
        .has-submenu.open .submenu-arrow { transform: rotate(180deg); }
        .submenu { list-style: none; display: none; margin: 2px 0 6px; padding-left: 12px; }
        .has-submenu.open .submenu { display: block; }
        .submenu a { padding: 10px 12px; font-size: 14px; }
        .submenu a:hover, .submenu a.active { background: var(--dark-secondary); color: white; }
        
        /* Main Content */
        .main-content { margin-left: 0; padding: 30px 40px; min-height: 100vh; }
        
        /* Navbar */
        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar h2 { font-size: 20px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        .user-profile { display: flex; align-items: center; gap: 12px; font-weight: 600; background: var(--bg-light); padding: 8px 16px; border-radius: 30px; }
        .user-profile i { color: var(--primary); font-size: 20px; }
        
        /* Alert Message */
        .alert { padding: 16px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; font-weight: 500; border-left: 4px solid; display: flex; align-items: flex-start; gap: 12px; line-height: 1.5; }
        .alert i { font-size: 18px; margin-top: 2px; }
        .alert.success { background: var(--success-light); border-left-color: var(--success); color: #065f46; }
        .alert.danger { background: var(--danger-light); border-left-color: var(--danger); color: #991b1b; }
        
        /* Form Panel & Grid Dua Kolom */
        .grid-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
        .form-panel { background: white; padding: 35px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .form-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 25px; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .form-panel h3 i { color: var(--primary); }
        .form-panel .subtitle { color: var(--gray); font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--gray); font-weight: 600; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); transition: all 0.3s ease; background: var(--bg-light); outline: none; }
        .form-group input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        
        /* Button */
        .btn-save { padding: 14px 24px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; width: 100%; display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 10px; }
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }
        .btn-template { width: 100%; margin-top: 12px; padding: 13px 24px; background: white; color: var(--success); border: 2px solid var(--success); border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; justify-content: center; align-items: center; gap: 10px; text-decoration: none; }
        .btn-template:hover { background: var(--success-light); }
        
        /* File Zone */
        .file-zone { border: 2px dashed var(--border); border-radius: 14px; padding: 25px; text-align: center; background: var(--bg-light); transition: all 0.2s ease; margin-bottom: 20px; cursor: pointer; display: block; }
        .file-zone:hover { border-color: var(--primary); background: var(--primary-light); }
        .file-zone i { font-size: 34px; color: var(--primary); margin-bottom: 10px; display: block; }
        .file-zone p { color: var(--gray); font-size: 13px; }
        .file-zone p strong { color: var(--dark-secondary); }
        .file-zone input[type="file"] { display: none; }
        .file-zone .file-name { color: var(--primary); font-weight: 700; margin-top: 8px; font-size: 13px; }
        
        /* Info Box */
        .info-box { background: var(--primary-light); padding: 15px; border-radius: 10px; margin-top: 20px; font-size: 13px; color: var(--primary-hover); display: flex; gap: 10px; align-items: flex-start; line-height: 1.6; }
        .info-box i { font-size: 16px; margin-top: 2px; }
        
        /* Rincian Error */
        .error-list { margin-top: 12px; max-height: 200px; overflow-y: auto; font-size: 13px; padding-left: 0; }
        .error-list li { margin-bottom: 6px; list-style: none; padding: 8px 12px; background: white; border: 1px solid var(--danger-light); border-radius: 8px; color: var(--danger); }
        
        /* Mobile Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        
        /* Responsif (Mobile & Tablet) */
        @media(max-width: 992px) {
            .main-content { padding: 20px; }
            .navbar h2 { font-size: 18px; }
            .grid-panel { grid-template-columns: 1fr; }
        }
    

        /* Header Atas (satu oval, lembut) */
        .top-header { position: relative; z-index: 1500; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 20px 0; padding: 10px 14px; border-radius: 999px; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border: 1px solid rgba(255,255,255,0.18); box-shadow: 0 12px 30px -10px rgba(37, 99, 235, 0.6); transition: margin-left 0.3s ease; }
        .top-header.active { margin-left: 260px; }
        .top-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: white; cursor: pointer; }
        .top-brand img { width: 42px; height: 42px; border-radius: 50%; background: white; padding: 4px; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .top-brand h1 { color: white; font-size: 16px; font-weight: 800; letter-spacing: 0.4px; margin: 0; white-space: nowrap; }
        .top-header .user-profile { background: rgba(255,255,255,0.15); color: white; box-shadow: none; padding: 8px 16px; border-radius: 999px; }
        .top-header .user-profile i { color: white; }
        .top-header .user-profile span { color: white; }

        /* Dropdown Profil */
        .top-header .user-profile { position: relative; cursor: pointer; }
        .profile-menu { display: none; position: absolute; top: calc(100% + 12px); right: 0; background: white; border-radius: 14px; box-shadow: 0 12px 32px rgba(0,0,0,0.18); min-width: 200px; padding: 8px; z-index: 2000; }
        .profile-menu.show { display: block; }
        .profile-menu-name { padding: 10px 14px; font-weight: 700; color: var(--dark); font-size: 14px; border-bottom: 1px solid var(--border); margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .profile-menu-logout { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 10px 16px; border-radius: 999px; color: #dc2626; font-weight: 700; font-size: 14px; text-decoration: none; background: #fee2e2; }
        .profile-menu-logout:hover { background: #dc2626; color: white; }

        /* Header responsif: panjang menyesuaikan desktop/mobile */
        @media (max-width: 576px) {
            .top-header { margin: 12px 12px 0; padding: 8px 10px; gap: 8px; }
            .top-header.active { padding: 6px 8px; }
            .top-brand h1 { display: none; }
            .top-brand img { width: 38px; height: 38px; }
            .top-header .user-profile span { display: inline; }
        }

</style>
</head>
<body>
    <!-- Header Atas -->
    <header class="top-header">
        <a href="index" class="top-brand">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
            <h1>Absensi SMAN 1 Bangunrejo</h1>
        </a>
                <div class="user-profile" onclick="toggleProfileMenu(event)">
            <i class="fa-solid fa-circle-user"></i>
            <span>Administrator</span>
            <i class="fa-solid fa-chevron-down" style="font-size: 11px; opacity: 0.8;"></i>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-name">Administrator</div>
                <a href="../logout" class="profile-menu-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </header>

    <!-- Mobile Overlay -->
    <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo" class="brand-logo"> <span>Absensi SMAN 1 Bangunrejo</span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="index"><i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span></a></li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-clipboard-check"></i> <span>Absensi</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="scan?type=hadir"><i class="fa-solid fa-right-to-bracket"></i> <span>Absen Hadir</span></a></li>
                    <li><a href="scan?type=pulang"><i class="fa-solid fa-right-from-bracket"></i> <span>Absen Pulang</span></a></li>
                    <li><a href="scan?type=sholat"><i class="fa-solid fa-mosque"></i> <span>Absen Sholat</span></a></li>
                    <li><a href="waktu_absensi"><i class="fa-solid fa-clock"></i> <span>Waktu Absensi</span></a></li>
                    <li><a href="hapus_data"><i class="fa-solid fa-broom"></i> <span>Bersihkan Data</span></a></li>
                </ul>
            </li>
            <li><a href="data_siswa"><i class="fa-solid fa-users"></i> <span>Data Siswa</span></a></li>
            <li><a href="kelas"><i class="fa-solid fa-school"></i> <span>Kelas</span></a></li>
            <li><a href="cetak_kartu"><i class="fa-solid fa-id-card"></i> <span>Cetak Kartu Pelajar</span></a></li>
            <li><a href="tambah_guru"><i class="fa-solid fa-user-plus"></i> <span>Tambah Guru</span></a></li>
            <li><a href="input_absen"><i class="fa-solid fa-clipboard-user"></i> <span>Input Manual</span></a></li>
            <li><a href="laporan"><i class="fa-solid fa-file-lines"></i> <span>Laporan Absensi</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar">
            <div class="navbar-left">
                <button class="btn-menu" onclick="toggleSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2>Manajemen Data Siswa</h2>
            </div>
            
        </div>

        <div class="grid-panel">
            <!-- Panel: Tambah Siswa Satuan -->
            <div class="form-panel">
                <h3><i class="fa-solid fa-address-card"></i> Formulir Registrasi Siswa Baru</h3>
                
                <?php if(!empty($success)): ?>
                    <div class="alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <div><?= $success ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if(!empty($error)): ?>
                    <div class="alert danger">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="nisn">NISN (Nomor Induk Siswa Nasional)</label>
                        <input type="text" id="nisn" name="nisn" placeholder="Contoh: 0123456789" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="name">Nama Lengkap Siswa</label>
                        <input type="text" id="name" name="name" placeholder="Masukkan nama lengkap siswa" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="tempat_lahir">Tempat Lahir</label>
                        <input type="text" id="tempat_lahir" name="tempat_lahir" placeholder="Contoh: Bangunrejo" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir">
                    </div>
                    <div class="form-group">
                        <label for="jenis_kelamin">Jenis Kelamin</label>
                        <select id="jenis_kelamin" name="jenis_kelamin" style="width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); background: var(--bg-light); outline: none;">
                            <option value="">-- Pilih Jenis Kelamin --</option>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="alamat">Alamat</label>
                        <input type="text" id="alamat" name="alamat" placeholder="Contoh: Desa Bangunrejo RT 01 RW 02" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="foto">Foto Siswa (opsional, maks 2MB)</label>
                        <input type="file" id="foto" name="foto" accept=".jpg,.jpeg,.png,.webp">
                    </div>
                    <div class="form-group">
                        <label for="class">Kelas / Rombongan Belajar</label>
                        <select id="class" name="class" required style="width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); background: var(--bg-light); outline: none;">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Data & Buat QR Code
                    </button>
                    
                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <p>Setelah disimpan, sistem akan otomatis membuat akun untuk siswa. <strong>Username</strong> dan <strong>Password</strong> default untuk login adalah NISN mereka.</p>
                    </div>
                </form>
            </div>

            <!-- Panel: Import Siswa Masal -->
            <div class="form-panel">
                <h3><i class="fa-solid fa-file-import"></i> Import Siswa Masal</h3>
                <p class="subtitle">Tiga cara import: (<strong>A</strong>) file <strong>ZIP</strong> (data + folder foto), (<strong>B</strong>) upload <strong>Excel/CSV</strong> + pilih foto sekaligus, atau (<strong>C</strong>) Excel/CSV dengan <strong>link foto Google Drive</strong> di kolom Foto. Format kolom: <strong>NISN, Nama, Kelas</strong> (Tempat Lahir, Tanggal Lahir, Jenis Kelamin, Alamat, Foto = link Drive opsional). Setiap siswa otomatis mendapat akun login &amp; QR Code.</p>
                
                <?php if(!empty($import_success)): ?>
                    <?php $is_error = in_array('error', $import_errors); ?>
                    <div class="alert <?= $is_error ? 'danger' : 'success' ?>">
                        <i class="fa-solid <?= $is_error ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                        <div>
                            <?= $import_success ?>
                            <?php if(!empty($import_errors) && !$is_error): ?>
                                <ul class="error-list">
                                    <?php foreach($import_errors as $err): ?>
                                        <li><i class="fa-solid fa-xmark"></i> <?= $err ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" id="importForm">
                    <div class="form-group">
                        <label for="file_zip">Metode A — Upload ZIP (CSV + folder foto)</label>
                        <label class="file-zone" for="file_zip">
                            <i class="fa-solid fa-file-zipper"></i>
                            <p><strong>Pilih file ZIP</strong><br>berisi data.csv + folder foto/</p>
                            <div class="file-name" id="file_zip_name"></div>
                            <input type="file" id="file_zip" name="file_zip" accept=".zip">
                        </label>
                    </div>

                    <div style="text-align:center; margin: 6px 0 18px; color: var(--gray); font-size: 13px; font-weight: 700;">— ATAU —</div>

                    <div class="form-group">
                        <label for="file_csv">Metode B/C — Upload Excel atau CSV</label>
                        <label class="file-zone" for="file_csv">
                            <i class="fa-solid fa-file-excel"></i>
                            <p><strong>Pilih file Excel (.xlsx) atau CSV</strong><br>NISN, Nama, Kelas, ... (kolom Foto = link Google Drive)</p>
                            <div class="file-name" id="file_csv_name"></div>
                            <input type="file" id="file_csv" name="file_csv" accept=".xlsx,.csv,.txt">
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="file_fotos">Foto Siswa (opsional, bisa pilih banyak)</label>
                        <label class="file-zone" for="file_fotos">
                            <i class="fa-solid fa-images"></i>
                            <p><strong>Pilih foto</strong><br>nama file sesuai NISN (mis. 0123456789.jpg) — bisa banyak sekaligus</p>
                            <div class="file-name" id="file_fotos_name"></div>
                            <input type="file" id="file_fotos" name="file_fotos[]" accept=".jpg,.jpeg,.png,.webp" multiple>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn-save" id="btn_import">
                        <i class="fa-solid fa-file-import"></i> Import Data Siswa
                    </button>
                    
                    <a href="template_siswa" class="btn-template">
                        <i class="fa-solid fa-file-csv"></i> Unduh Template CSV
                    </a>
                    
                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            <strong>Format kolom (Excel / CSV):</strong> <span style="font-family:monospace;">NISN, Nama, Kelas, Tempat Lahir, Tanggal Lahir, Jenis Kelamin, Alamat, Foto</span><br>
                            <strong>Kolom Foto</strong> (opsional) = link Google Drive, mis. <span style="font-family:monospace;">https://drive.google.com/file/d/FILE_ID/view</span> — file harus di-share <strong>"Siapa saja dengan link"</strong> agar sistem bisa mengunduhnya.<br><br>
                            <strong>Metode B</strong> (termudah): pilih Excel/CSV lalu pilih semua foto sekaligus (Ctrl+A/serat), foto bernama sesuai NISN dicocokkan otomatis. <strong>Metode C</strong>: cukup taruh link Drive di kolom Foto.<br>
                            <strong>Metode A (ZIP):</strong> <span style="font-family:monospace;">data.csv<br>foto/0123456789.jpg</span><br>
                            Koma (<strong>,</strong>/<strong>;</strong>) didukung; data dicari di mana saja dalam ZIP. Kelas belum terdaftar didaftarkan otomatis. Siswa tanpa foto tetap terimport.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Script Interaksi Mobile -->
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
            document.querySelector('.top-header').classList.toggle('active');
        }

        function toggleSubmenu(el) {
            el.closest('.has-submenu').classList.toggle('open');
        }
        function bindFileLabel(inputId, labelId) {
            var input = document.getElementById(inputId);
            if (!input) return;
            input.addEventListener('change', function() {
                var label = document.getElementById(labelId);
                if (label) label.textContent = this.files.length > 1 ? this.files.length + ' file dipilih' : (this.files.length > 0 ? this.files[0].name : '');
            });
        }
        bindFileLabel('file_zip', 'file_zip_name');
        bindFileLabel('file_csv', 'file_csv_name');
        bindFileLabel('file_fotos', 'file_fotos_name');

        // Validasi & indikator proses dipasang di event SUBMIT form — bukan click tombol.
        // (Men-disable tombol submit di event click membuat Chrome/Edge MEMBATALKAN
        //  pengiriman form secara diam-diam, sehingga import tidak pernah terkirim.)
        document.getElementById('importForm').addEventListener('submit', function(e) {
            var zip = document.getElementById('file_zip');
            var csv = document.getElementById('file_csv');
            if (!zip.files.length && !csv.files.length) {
                e.preventDefault();
                alert('Silakan pilih file ZIP atau CSV terlebih dahulu.');
                return;
            }
            var btn = document.getElementById('btn_import');
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sedang mengimpor... mohon tunggu';
        });
    </script>


    <!-- Footer -->
    <footer style="text-align: center; padding: 18px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(100,116,139,0.2); margin-top: 24px;">
        Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
    </footer>

    <script>
        function toggleProfileMenu(e) {
            e.stopPropagation();
            document.getElementById('profileMenu').classList.toggle('show');
        }
        document.addEventListener('click', function () {
            var m = document.getElementById('profileMenu');
            if (m) m.classList.remove('show');
        });
    </script>
</body>
</html>