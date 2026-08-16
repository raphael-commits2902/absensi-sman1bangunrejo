@echo off
REM Menjalankan seluruh test suite aplikasi absensi.
REM Dibutuhkan: PHP di C:\xampp\php\php.exe (default XAMPP).
"C:\xampp\php\php.exe" "%~dp0bin\phpunit.phar" -c "%~dp0phpunit.xml"
