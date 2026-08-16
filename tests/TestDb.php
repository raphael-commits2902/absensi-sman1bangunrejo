<?php
/**
 * TestDb
 * Mengelola database uji terpisah: absensi_sman1bangunrejo_test.
 * Database produksi TIDAK pernah disentuh oleh test.
 */
class TestDb
{
    const DB = 'absensi_sman1bangunrejo_test';
    const HOST = 'localhost';
    const USER = 'root';
    const PASS = '';

    private static function server()
    {
        return new PDO('mysql:host=' . self::HOST, self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    public static function pdo()
    {
        return new PDO('mysql:host=' . self::HOST . ';dbname=' . self::DB, self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    /**
     * Memastikan database + skema test ada (idempotent).
     */
    public static function ensure()
    {
        self::server()->exec("CREATE DATABASE IF NOT EXISTS `" . self::DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $pdo = self::pdo();
        foreach (self::schema() as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Membersihkan dan mengisi ulang data test. Mengembalikan PDO siap pakai.
     */
    public static function fresh()
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['attendance', 'guru', 'students', 'users', 'kelas', 'waktu_absensi'] as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $passwords = [
            'admin'  => password_hash('admin123', PASSWORD_DEFAULT),
            'guru'   => password_hash('guru123', PASSWORD_DEFAULT),
            'siswa'  => password_hash('siswa123', PASSWORD_DEFAULT),
        ];

        $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([1, 'admin', $passwords['admin'], 'admin']);
        $stmt->execute([2, 'guru_ips1', $passwords['guru'], 'guru']);
        $stmt->execute([3, '12345678', $passwords['siswa'], 'student']);

        $stmt = $pdo->prepare("INSERT INTO guru (id, user_id, name, nip) VALUES (?, ?, ?, ?)");
        $stmt->execute([1, 2, 'Nur Aman', '199001012010011001']);

        $stmt = $pdo->prepare("INSERT INTO students (id, user_id, nis, nisn, name, jenis_kelamin, class, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([1, 3, '001', '0000000001', 'Budi Santoso', 'L', 'XII IPS 1', 'STUDENT1']);
        $stmt->execute([2, null, '002', '0000000002', 'Ani Rahayu', 'P', 'XII IPA 1', 'STUDENT2']);

        $stmt = $pdo->prepare("INSERT INTO kelas (id, name) VALUES (?, ?)");
        $stmt->execute([1, 'XII IPS 1']);
        $stmt->execute([2, 'XII IPA 1']);

        $stmt = $pdo->prepare("INSERT INTO waktu_absensi (id, jenis, jam_mulai, jam_batas) VALUES (?, ?, ?, ?)");
        $stmt->execute([1, 'hadir', '06:00:00', '07:00:00']);
        $stmt->execute([2, 'pulang', '13:00:00', '15:00:00']);
        $stmt->execute([3, 'sholat', '11:30:00', '12:30:00']);

        $stmt = $pdo->prepare("INSERT INTO attendance (id, student_id, date, time, time_in, time_out, time_sholat, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([1, 1, '2026-08-16', '06:45:00', '06:45:00', null, null, 'Hadir']);

        return $pdo;
    }

    private static function schema()
    {
        return [
            "CREATE TABLE IF NOT EXISTS `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `password` varchar(255) NOT NULL,
                `role` enum('admin','student','guru') NOT NULL DEFAULT 'student',
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `guru` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `name` varchar(100) NOT NULL,
                `nip` varchar(30) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                CONSTRAINT `guru_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `students` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) DEFAULT NULL,
                `nis` varchar(20) NOT NULL,
                `nisn` varchar(20) DEFAULT NULL,
                `name` varchar(100) NOT NULL,
                `tempat_lahir` varchar(100) DEFAULT NULL,
                `tanggal_lahir` date DEFAULT NULL,
                `jenis_kelamin` enum('L','P') DEFAULT NULL,
                `alamat` varchar(255) DEFAULT NULL,
                `foto` varchar(255) DEFAULT NULL,
                `class` varchar(50) NOT NULL,
                `qr_token` varchar(100) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `nis` (`nis`),
                UNIQUE KEY `qr_token` (`qr_token`),
                KEY `user_id` (`user_id`),
                CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `attendance` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `student_id` int(11) DEFAULT NULL,
                `date` date NOT NULL,
                `time` time NOT NULL,
                `time_in` time DEFAULT NULL,
                `time_out` time DEFAULT NULL,
                `time_sholat` time DEFAULT NULL,
                `status` enum('Hadir','Sakit','Izin','Alpa') DEFAULT 'Hadir',
                PRIMARY KEY (`id`),
                KEY `student_id` (`student_id`),
                CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `waktu_absensi` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `jenis` varchar(20) NOT NULL,
                `jam_mulai` time DEFAULT NULL,
                `jam_batas` time DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `jenis` (`jenis`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `kelas` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(50) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}
