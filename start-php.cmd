@echo off
setlocal
cd /d "%~dp0"
set "MNM_CONFIG=%~dp0php-version\config\local.php"
echo M^&M WORKS PHP: http://127.0.0.1:4082
"%~dp0.tmp\php-runtime\bin\php.exe" -S 127.0.0.1:4082 -t "%~dp0php-version\public"
endlocal
