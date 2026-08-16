<?php

use PHPUnit\Framework\TestCase;

/**
 * Test logika murni (pure functions) di lib/core.php.
 */
class CoreLogicTest extends TestCase
{
    public function testIsTerlambatLewatBatas(): void
    {
        $this->assertTrue(absensi_is_terlambat('07:01:00', '07:00:00'));
        $this->assertTrue(absensi_is_terlambat('07:00:01', '07:00:00'));
    }

    public function testIsTerlambatTepatBatasTidakTerlambat(): void
    {
        $this->assertFalse(absensi_is_terlambat('07:00:00', '07:00:00'));
        $this->assertFalse(absensi_is_terlambat('06:59:59', '07:00:00'));
    }

    public function testIsTerlambatDenganNilaiKosong(): void
    {
        $this->assertFalse(absensi_is_terlambat('', '07:00:00'));
        $this->assertFalse(absensi_is_terlambat('07:00:00', ''));
        $this->assertFalse(absensi_is_terlambat(null, null));
    }

    public function testStatusAbsenSelainHadirTetap(): void
    {
        $this->assertSame('Sakit', absensi_status(['status' => 'Sakit', 'time_in' => '08:00:00'], '07:00:00'));
        $this->assertSame('Alpa', absensi_status(['status' => 'Alpa'], '07:00:00'));
        $this->assertSame('Izin', absensi_status(['status' => 'Izin'], '07:00:00'));
    }

    public function testStatusAbsenTepatWaktuHadir(): void
    {
        $row = ['status' => 'Hadir', 'time_in' => '06:45:00'];
        $this->assertSame('Hadir', absensi_status($row, '07:00:00'));
    }

    public function testStatusAbsenTerlambat(): void
    {
        $row = ['status' => 'Hadir', 'time_in' => '07:15:00'];
        $this->assertSame('Terlambat', absensi_status($row, '07:00:00'));
    }

    public function testStatusAbsenTanpaTimeInTetapHadir(): void
    {
        $row = ['status' => 'Hadir', 'time_in' => ''];
        $this->assertSame('Hadir', absensi_status($row, '07:00:00'));
    }

    public function testFormatTanggalIndonesia(): void
    {
        $this->assertSame('16 Agustus 2026', absensi_format_tgl_id('2026-08-16'));
        $this->assertSame('01 Januari 2026', absensi_format_tgl_id('2026-01-01'));
        $this->assertSame('31 Desember 2026', absensi_format_tgl_id('2026-12-31'));
    }

    public function testInitialsDuaKata(): void
    {
        $this->assertSame('BS', absensi_initials('Budi Santoso'));
    }

    public function testInitialsSatuKata(): void
    {
        $this->assertSame('A', absensi_initials('Ani'));
    }

    public function testInitialsNamaKosong(): void
    {
        $this->assertSame('?', absensi_initials(''));
    }

    public function testAvatarColorKonsisten(): void
    {
        $this->assertSame(absensi_avatar_color('Budi Santoso'), absensi_avatar_color('Budi Santoso'));
        $this->assertNotEmpty(absensi_avatar_color('Ani'));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', absensi_avatar_color('Ani'));
    }

    public function testGetWaktuMengembalikanSetting(): void
    {
        $pdo = TestDb::fresh();
        $waktu = absensi_get_waktu($pdo);
        $this->assertArrayHasKey('hadir', $waktu);
        $this->assertArrayHasKey('pulang', $waktu);
        $this->assertArrayHasKey('sholat', $waktu);
        $this->assertSame('07:00:00', $waktu['hadir']['jam_batas']);
    }
}
