<?php
/**
 * BaseTestCase
 * Setiap test method mendapat database test bersih (fresh) via setUp().
 */

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDb::fresh();
    }
}
