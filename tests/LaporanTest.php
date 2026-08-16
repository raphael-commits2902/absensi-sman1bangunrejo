<?php

/**
 * Test query & agregasi laporan (absensi_laporan_query, absensi_alpa_students).
 */
class LaporanTest extends BaseTestCase
{
    public function testQueryTanpaFilter(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo);
        $this->assertStringContainsString('JOIN students s ON a.student_id = s.id', $sql);
        $this->assertSame([], $params);
        $rows = $this->pdo->prepare($sql);
        $rows->execute($params);
        $this->assertSame(1, $rows->rowCount());
    }

    public function testQueryFilterNama(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo, 'Budi');
        $this->assertStringContainsString('s.name LIKE ?', $sql);
        $this->assertSame(['%Budi%'], $params);
    }

    public function testQueryFilterKelas(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo, '', 'XII IPS 1');
        $this->assertStringContainsString('s.class = ?', $sql);
        $this->assertSame(['XII IPS 1'], $params);
    }

    public function testQueryFilterTanggal(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo, '', '', '2026-08-16');
        $this->assertStringContainsString('a.date = ?', $sql);
        $this->assertSame(['2026-08-16'], $params);
    }

    public function testQuerySemuaFilterMengembalikanSatuBaris(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo, 'Budi', 'XII IPS 1', '2026-08-16');
        $rows = $this->pdo->prepare($sql);
        $rows->execute($params);
        $this->assertSame(1, $rows->rowCount());
        $row = $rows->fetch();
        $this->assertSame('Budi Santoso', $row['name']);
    }

    public function testQueryFilterTidakCocokKosong(): void
    {
        [$sql, $params] = absensi_laporan_query($this->pdo, 'Zzz', '', '2026-08-16');
        $rows = $this->pdo->prepare($sql);
        $rows->execute($params);
        $this->assertSame(0, $rows->rowCount());
    }

    public function testAlpaStudentsMengembalikanYangBelumAbsen(): void
    {
        $alpa = absensi_alpa_students($this->pdo, '2026-08-17');
        $names = array_column($alpa, 'name');
        $this->assertContains('Budi Santoso', $names);
        $this->assertContains('Ani Rahayu', $names);
    }

    public function testAlpaStudentsFilterKelas(): void
    {
        $alpa = absensi_alpa_students($this->pdo, '2026-08-17', 'XII IPA 1');
        $this->assertCount(1, $alpa);
        $this->assertSame('Ani Rahayu', $alpa[0]['name']);
    }

    public function testAlpaStudentsYangSudahAbsenTidakMuncul(): void
    {
        $alpa = absensi_alpa_students($this->pdo, '2026-08-16', 'XII IPS 1');
        $this->assertSame([], $alpa);
    }

    public function testAlpaTanpaTanggalKosong(): void
    {
        $this->assertSame([], absensi_alpa_students($this->pdo, ''));
    }
}
