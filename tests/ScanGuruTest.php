<?php

/**
 * Test proses scan absen oleh Guru (absensi_scan_guru).
 * Waktu ditentukan via parameter $now agar deterministik.
 */
class ScanGuruTest extends BaseTestCase
{
    public function testScanQRInvalid(): void
    {
        $r = absensi_scan_guru($this->pdo, 'TIDAK-ADA', '2026-08-17 07:00:00');
        $this->assertSame('invalid', $r['status']);
    }

    public function testScanPertamaSuksesMembuatAbsen(): void
    {
        $r = absensi_scan_guru($this->pdo, 'STUDENT2', '2026-08-17 06:50:00');
        $this->assertSame('success', $r['status']);
        $this->assertSame('Ani Rahayu', $r['name']);

        $row = $this->pdo->query("SELECT * FROM attendance WHERE student_id = 2 AND date = '2026-08-17'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('06:50:00', $row['time_in']);
        $this->assertSame('06:50:00', $row['time']);
        $this->assertSame('Hadir', $row['status']);
    }

    public function testScanGandaDitolak(): void
    {
        absensi_scan_guru($this->pdo, 'STUDENT1', '2026-08-16 06:50:00');
        $r = absensi_scan_guru($this->pdo, 'STUDENT1', '2026-08-16 09:00:00');
        $this->assertSame('already', $r['status']);
        $this->assertSame('Budi Santoso', $r['name']);
    }

    public function testScanHariBerbedaDiizinkan(): void
    {
        $r = absensi_scan_guru($this->pdo, 'STUDENT1', '2026-08-17 07:10:00');
        $this->assertSame('success', $r['status']);
    }

    public function testHasilScanMembawaInformasiSiswa(): void
    {
        $r = absensi_scan_guru($this->pdo, 'STUDENT1', '2026-08-17 06:45:00');
        $this->assertSame('Budi Santoso', $r['name']);
        $this->assertSame('0000000001', $r['nisn']);
        $this->assertSame('XII IPS 1', $r['class']);
        $this->assertArrayHasKey('foto', $r);
    }
}
