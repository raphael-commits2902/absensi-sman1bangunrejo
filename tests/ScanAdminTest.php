<?php

/**
 * Test proses scan absen oleh Admin (absensi_scan_admin) untuk hadir/pulang/sholat.
 */
class ScanAdminTest extends BaseTestCase
{
    public function testScanQRInvalid(): void
    {
        $r = absensi_scan_admin($this->pdo, 'TIDAK-ADA', 'hadir', '2026-08-17 07:00:00');
        $this->assertSame('error', $r['status']);
    }

    public function testHadirBaruMembuatAbsen(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT2', 'hadir', '2026-08-17 06:55:00');
        $this->assertSame('success', $r['status']);
        $this->assertSame('Hadir', $r['jenis']);
        $row = $this->pdo->query("SELECT * FROM attendance WHERE student_id = 2 AND date = '2026-08-17'")->fetch();
        $this->assertSame('06:55:00', $row['time_in']);
    }

    public function testHadirGandaDitolak(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT1', 'hadir', '2026-08-16 06:50:00');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('sudah absen Hadir', $r['message']);
    }

    public function testPulangSebelumHadirDitolak(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT2', 'pulang', '2026-08-17 14:00:00');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('belum absen Hadir', $r['message']);
    }

    public function testPulangSetelahHadirBerhasil(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT1', 'pulang', '2026-08-16 14:30:00');
        $this->assertSame('success', $r['status']);
        $this->assertSame('Pulang', $r['jenis']);
        $row = $this->pdo->query("SELECT * FROM attendance WHERE student_id = 1 AND date = '2026-08-16'")->fetch();
        $this->assertSame('14:30:00', $row['time_out']);
    }

    public function testPulangGandaDitolak(): void
    {
        absensi_scan_admin($this->pdo, 'STUDENT1', 'pulang', '2026-08-16 14:30:00');
        $r = absensi_scan_admin($this->pdo, 'STUDENT1', 'pulang', '2026-08-16 15:30:00');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('sudah absen Pulang', $r['message']);
    }

    public function testSholatSetelahHadirBerhasil(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT1', 'sholat', '2026-08-16 12:00:00');
        $this->assertSame('success', $r['status']);
        $this->assertSame('Sholat', $r['jenis']);
        $row = $this->pdo->query("SELECT * FROM attendance WHERE student_id = 1 AND date = '2026-08-16'")->fetch();
        $this->assertSame('12:00:00', $row['time_sholat']);
    }

    public function testSholatGandaDitolak(): void
    {
        absensi_scan_admin($this->pdo, 'STUDENT1', 'sholat', '2026-08-16 12:00:00');
        $r = absensi_scan_admin($this->pdo, 'STUDENT1', 'sholat', '2026-08-16 12:20:00');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('sudah absen Sholat', $r['message']);
    }

    public function testSholatSebelumHadirDitolak(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT2', 'sholat', '2026-08-17 12:00:00');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('belum absen Hadir', $r['message']);
    }

    public function testTypeTidakDikenalDianggapHadir(): void
    {
        $r = absensi_scan_admin($this->pdo, 'STUDENT2', 'bogus', '2026-08-17 06:55:00');
        $this->assertSame('success', $r['status']);
        $this->assertSame('Hadir', $r['jenis']);
    }
}
