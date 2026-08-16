<?php
/**
 * bootstrap.php
 * Berjalan sebelum seluruh test. Menyiapkan autoload + database test.
 */

define('ABSENSI_ROOT', dirname(__DIR__));
define('ABSENSI_LIB', ABSENSI_ROOT . '/lib');

require ABSENSI_LIB . '/core.php';
require __DIR__ . '/TestDb.php';
require __DIR__ . '/BaseTestCase.php';

TestDb::ensure();
