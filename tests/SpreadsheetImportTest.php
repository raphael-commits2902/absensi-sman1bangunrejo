<?php

use PHPUnit\Framework\TestCase;

/**
 * Test import masal siswa: parsing spreadsheet (.xlsx/.csv) + aturan validasi baris.
 *
 * Latar belakang bug yang dikunci test ini:
 * Excel MENGHILANGKAN sel kosong dari XML. Dulu sel "Kelas" yang kosong membuat
 * seluruh kolom setelahnya bergeser (kolom Kelas malah terisi nilai "Tempat Lahir"),
 * sehingga siswa TANPA kelas tetap ikut terimpor dan kelas palsu terbuat otomatis.
 * Sekarang posisi kolom dijaga sesuai referensi sel dan baris tanpa kelas DILEWATI.
 */
class SpreadsheetImportTest extends TestCase
{
    /** @var string[] File sementara yang dibuat test, dibersihkan di tearDown(). */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        $this->tmpFiles = [];
    }

    /**
     * Membuat file .xlsx sementara (struktur minimal seperti keluaran Excel).
     * @param string[] $shared   Daftar shared string (indeks = urutan)
     * @param string   $sheetXml Isi xl/worksheets/sheet1.xml
     */
    private function buatXlsx(array $shared, string $sheetXml): string
    {
        $si = '';
        foreach ($shared as $s) {
            $si .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $file = sys_get_temp_dir() . '/absensi_test_' . uniqid() . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->fail('Gagal membuat file xlsx uji.');
        }
        $zip->addFromString('xl/sharedStrings.xml', '<sst>' . $si . '</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        $this->tmpFiles[] = $file;
        return $file;
    }

    /** Membuat file .csv sementara. */
    private function buatCsv(string $isi): string
    {
        $file = sys_get_temp_dir() . '/absensi_test_' . uniqid() . '.csv';
        file_put_contents($file, $isi);
        $this->tmpFiles[] = $file;
        return $file;
    }

    /** Ambil nilai teks satu kolom hasil parser ('' bila kolom tidak ada). */
    private function nilai(array $row, int $i): string
    {
        if (!isset($row[$i])) return '';
        $c = $row[$i];
        return is_array($c) ? (string)$c['v'] : (string)$c;
    }

    // ==================== Parser .xlsx ====================

    public function testSelKelasKosongTidakMenggeserKolomLain(): void
    {
        // Baris = siswa TANPA kelas: Excel menghilangkan <c r="C3"> sepenuhnya.
        $shared = ['NISN', 'Nama', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin', 'Alamat',
                   '0123456790', 'Siti Aminah', 'Gunung Sugih', '2009-08-03', 'P', 'Gunung Sugih Timur'];
        $sheet = '<worksheet><sheetData>'
            . '<row r="3">'
            . '<c r="A3" t="s"><v>7</v></c><c r="B3" t="s"><v>8</v></c>'
            . '<c r="D3" t="s"><v>9</v></c><c r="E3" t="s"><v>10</v></c>'
            . '<c r="F3" t="s"><v>11</v></c><c r="G3" t="s"><v>12</v></c></row>'
            . '</sheetData></worksheet>';

        $rows = absensi_spreadsheet_rows($this->buatXlsx($shared, $sheet), 'xlsx');
        $row = $rows[0];

        $this->assertSame('0123456790', $this->nilai($row, 0));
        $this->assertSame('Siti Aminah', $this->nilai($row, 1));
        // KUNCI: kolom Kelas harus tetap KOSONG — bukan terisi "Gunung Sugih".
        $this->assertSame('', $this->nilai($row, 2));
        $this->assertSame('Gunung Sugih', $this->nilai($row, 3));
        $this->assertSame('2009-08-03', $this->nilai($row, 4));
        $this->assertSame('P', $this->nilai($row, 5));
        $this->assertSame('Gunung Sugih Timur', $this->nilai($row, 6));
    }

    public function testBarisDenganKelasTerbacaLengkap(): void
    {
        $shared = ['NISN', 'Nama', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin', 'Alamat',
                   '0123456789', 'Budi Santoso', 'XA 1', 'Bangunrejo', '2009-05-12', 'L', 'Desa Bangunrejo'];
        $sheet = '<worksheet><sheetData>'
            . '<row r="2">'
            . '<c r="A2" t="s"><v>7</v></c><c r="B2" t="s"><v>8</v></c><c r="C2" t="s"><v>9</v></c>'
            . '<c r="D2" t="s"><v>10</v></c><c r="E2" t="s"><v>11</v></c><c r="F2" t="s"><v>12</v></c>'
            . '<c r="G2" t="s"><v>13</v></c></row>'
            . '</sheetData></worksheet>';

        $row = absensi_spreadsheet_rows($this->buatXlsx($shared, $sheet), 'xlsx')[0];

        $this->assertSame('0123456789', $this->nilai($row, 0));
        $this->assertSame('Budi Santoso', $this->nilai($row, 1));
        $this->assertSame('XA 1', $this->nilai($row, 2));
        $this->assertSame('Bangunrejo', $this->nilai($row, 3));
        $this->assertSame('2009-05-12', $this->nilai($row, 4));
        $this->assertSame('L', $this->nilai($row, 5));
        $this->assertSame('Desa Bangunrejo', $this->nilai($row, 6));
    }

    public function testSelKosongDiTengahDanDiAkhirBarisTetapKosong(): void
    {
        // Kolom A, C (Kelas), F kosong; kolom D, E, G, H terisi.
        $shared = ['Tempat Lahir', 'Bangunrejo', 'Nama', 'Ani'];
        $sheet = '<worksheet><sheetData>'
            . '<row r="1">'
            . '<c r="D1" t="s"><v>0</v></c><c r="E1" t="s"><v>1</v></c>'
            . '<c r="G1" t="s"><v>2</v></c><c r="H1" t="s"><v>3</v></c></row>'
            . '</sheetData></worksheet>';

        $row = absensi_spreadsheet_rows($this->buatXlsx($shared, $sheet), 'xlsx')[0];

        $this->assertSame('', $this->nilai($row, 0));           // A kosong
        $this->assertSame('', $this->nilai($row, 2));           // C (Kelas) kosong
        $this->assertSame('Bangunrejo', $this->nilai($row, 4)); // E
        $this->assertSame('', $this->nilai($row, 5));           // F kosong
        $this->assertSame('Nama', $this->nilai($row, 6));       // G
        $this->assertSame('Ani', $this->nilai($row, 7));        // H
    }

    public function testSelAngkaDitandaiNumUntukPemulihanNisn(): void
    {
        // Sel angka: Excel menulis angka TANPA atribut t (default "n").
        $shared = ['Budi'];
        $sheet = '<worksheet><sheetData>'
            . '<row r="1"><c r="A1"><v>106982596</v></c><c r="B1" t="s"><v>0</v></c></row>'
            . '</sheetData></worksheet>';

        $row = absensi_spreadsheet_rows($this->buatXlsx($shared, $sheet), 'xlsx')[0];

        $this->assertIsArray($row[0]);
        $this->assertTrue($row[0]['num']);
        $this->assertSame('106982596', $row[0]['v']);
        $this->assertFalse($row[1]['num']);
    }

    // ==================== Parser .csv ====================

    public function testCsvKelasKosongTetapKosong(): void
    {
        $csv = "NISN,Nama,Kelas,Tempat Lahir\n"
             . "0123456790,Siti Aminah,,Gunung Sugih\n";
        $rows = absensi_spreadsheet_rows($this->buatCsv($csv), 'csv');

        $this->assertSame('0123456790', (string)$rows[1][0]);
        $this->assertSame('Siti Aminah', (string)$rows[1][1]);
        $this->assertSame('', (string)$rows[1][2]); // Kelas kosong
        $this->assertSame('Gunung Sugih', (string)$rows[1][3]);
    }

    // ==================== Aturan validasi baris import ====================

    public function testBarisLengkapDiterima(): void
    {
        $this->assertSame('ok', absensi_import_row_reason('0123456789', 'Budi', 'X 1'));
    }

    public function testBarisTanpaKelasDitolak(): void
    {
        // Permintaan pengguna: siswa tanpa kelas JANGAN dimasukkan.
        $this->assertSame('kelas_kosong', absensi_import_row_reason('0123456789', 'Budi', ''));
        $this->assertSame('kelas_kosong', absensi_import_row_reason('0123456789', 'Budi', '   '));
    }

    public function testBarisTanpaNisnAtauNamaDitolak(): void
    {
        $this->assertSame('tidak_lengkap', absensi_import_row_reason('', 'Budi', 'X 1'));
        $this->assertSame('tidak_lengkap', absensi_import_row_reason('0123456789', '', 'X 1'));
    }

    // ==================== Helper indeks kolom Excel ====================

    public function testExcelColIndexHurufTunggal(): void
    {
        $this->assertSame(0, absensi_excel_col_index('A1'));
        $this->assertSame(2, absensi_excel_col_index('C5'));
        $this->assertSame(25, absensi_excel_col_index('Z1'));
    }

    public function testExcelColIndexDuaHuruf(): void
    {
        $this->assertSame(26, absensi_excel_col_index('AA1'));
        $this->assertSame(27, absensi_excel_col_index('AB9'));
    }

    public function testExcelColIndexTanpaReferensi(): void
    {
        $this->assertNull(absensi_excel_col_index(''));
        $this->assertNull(absensi_excel_col_index('12'));
    }
}
