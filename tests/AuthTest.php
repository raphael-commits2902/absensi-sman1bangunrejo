<?php

/**
 * Test autentikasi login (absensi_auth).
 */
class AuthTest extends BaseTestCase
{
    public function testLoginAdminBerhasil(): void
    {
        $user = absensi_auth($this->pdo, 'admin', 'admin123');
        $this->assertNotNull($user);
        $this->assertSame('admin', $user['role']);
        $this->assertSame(1, (int)$user['id']);
    }

    public function testLoginGuruBerhasil(): void
    {
        $user = absensi_auth($this->pdo, 'guru_ips1', 'guru123');
        $this->assertNotNull($user);
        $this->assertSame('guru', $user['role']);
    }

    public function testLoginSiswaBerhasil(): void
    {
        $user = absensi_auth($this->pdo, '12345678', 'siswa123');
        $this->assertNotNull($user);
        $this->assertSame('student', $user['role']);
    }

    public function testLoginPasswordSalah(): void
    {
        $this->assertNull(absensi_auth($this->pdo, 'admin', 'salah'));
    }

    public function testLoginUsernameTidakDikenal(): void
    {
        $this->assertNull(absensi_auth($this->pdo, 'tidak_ada', 'apapun'));
    }

    public function testLoginUsernameKosong(): void
    {
        $this->assertNull(absensi_auth($this->pdo, '', 'admin123'));
    }

    public function testLoginUsernameTrimmed(): void
    {
        $user = absensi_auth($this->pdo, '  admin  ', 'admin123');
        $this->assertNotNull($user);
    }
}
