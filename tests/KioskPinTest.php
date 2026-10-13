<?php

/**
 * Test logika PIN kiosk (absensi_kiosk_*) untuk halaman pos absen.
 */
class KioskPinTest extends BaseTestCase
{
    public function testTabelDibuatOtomatis(): void
    {
        absensi_kiosk_ensure_table($this->pdo);
        absensi_kiosk_ensure_table($this->pdo); // idempotent, tidak boleh error
        $tables = $this->pdo->query("SHOW TABLES LIKE 'kiosk_pin'")->fetch();
        $this->assertNotFalse($tables);
    }

    public function testBelumDiatur(): void
    {
        $this->assertFalse(absensi_kiosk_has_pin($this->pdo));
        $this->assertFalse(absensi_kiosk_verify_pin($this->pdo, '1234'));
    }

    public function testSetPinLaluVerifikasiBenar(): void
    {
        $this->assertTrue(absensi_kiosk_set_pin($this->pdo, '1234'));
        $this->assertTrue(absensi_kiosk_has_pin($this->pdo));
        $this->assertTrue(absensi_kiosk_verify_pin($this->pdo, '1234'));
    }

    public function testVerifikasiPinSalah(): void
    {
        absensi_kiosk_set_pin($this->pdo, '1234');
        $this->assertFalse(absensi_kiosk_verify_pin($this->pdo, '9999'));
        $this->assertFalse(absensi_kiosk_verify_pin($this->pdo, ''));
    }

    public function testPinTersimpanHashBukanPlaintext(): void
    {
        absensi_kiosk_set_pin($this->pdo, '1234');
        $hash = $this->pdo->query("SELECT pin_hash FROM kiosk_pin LIMIT 1")->fetchColumn();
        $this->assertStringStartsWith('$', $hash);
        $this->assertStringNotContainsString('1234', $hash);
    }

    public function testGantiPinMenimpaLama(): void
    {
        absensi_kiosk_set_pin($this->pdo, '1234');
        absensi_kiosk_set_pin($this->pdo, '5678');
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM kiosk_pin")->fetchColumn();
        $this->assertSame(1, $count, 'Kiosk_PIN selalu hanya satu baris aktif');
        $this->assertFalse(absensi_kiosk_verify_pin($this->pdo, '1234'));
        $this->assertTrue(absensi_kiosk_verify_pin($this->pdo, '5678'));
    }

    public function testSetPinDitolakBilaFormatSalah(): void
    {
        $this->assertFalse(absensi_kiosk_set_pin($this->pdo, '123'));      // kurang dari 4 digit
        $this->assertFalse(absensi_kiosk_set_pin($this->pdo, '1234567890123')); // lebih dari 12 digit
        $this->assertFalse(absensi_kiosk_set_pin($this->pdo, '12ab'));     // bukan angka
        $this->assertFalse(absensi_kiosk_set_pin($this->pdo, ''));         // kosong
        $this->assertFalse(absensi_kiosk_has_pin($this->pdo));
    }

    public function testPinDenganSpasiDiTrim(): void
    {
        $this->assertTrue(absensi_kiosk_set_pin($this->pdo, ' 1234 '));
        $this->assertTrue(absensi_kiosk_verify_pin($this->pdo, ' 1234 '));
    }
}
