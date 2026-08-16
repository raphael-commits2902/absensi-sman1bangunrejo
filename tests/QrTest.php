<?php

use PHPUnit\Framework\TestCase;

/**
 * Test generate QR Code lokal (absensi_qr_png) — menggantikan API eksternal.
 */
class QrTest extends TestCase
{
    public function testQrPngMenghasilkanFileValid(): void
    {
        $tmp = sys_get_temp_dir() . '/qr_test_' . uniqid() . '.png';
        try {
            $ok = absensi_qr_png('STUDENT1', $tmp);
            $this->assertTrue($ok, 'QR PNG seharusnya berhasil dibuat');
            $this->assertFileExists($tmp);
            $this->assertGreaterThan(100, filesize($tmp));
            $head = file_get_contents($tmp, false, null, 0, 8);
            $this->assertSame("\x89PNG\r\n\x1a\n", $head, 'File harus PNG valid');
        } finally {
            @unlink($tmp);
        }
    }

    public function testQrDuaTokenBerbedaHasilBerbeda(): void
    {
        $a = sys_get_temp_dir() . '/qr_a_' . uniqid() . '.png';
        $b = sys_get_temp_dir() . '/qr_b_' . uniqid() . '.png';
        try {
            absensi_qr_png('TOKEN-A', $a);
            absensi_qr_png('TOKEN-B', $b);
            $this->assertNotSame(md5_file($a), md5_file($b));
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testQrPathTidakValidMengembalikanFalse(): void
    {
        $bad = 'Z:\\tidak_ada_dir\\' . uniqid() . '.png';
        $this->assertFalse(absensi_qr_png('TOKEN', $bad));
    }
}
