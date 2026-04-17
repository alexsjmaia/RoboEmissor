@echo off
setlocal

if /I "%~1" neq "__run__" (
    powershell -NoProfile -WindowStyle Hidden -Command "Start-Process cmd.exe -ArgumentList '/c ""%~f0"" __run__' -WindowStyle Hidden"
    exit /b 0
)

cd /d "%~dp0"

set "PHP_EXE=C:\RoboEmissor\php\php.exe"

if not exist "%PHP_EXE%" (
    echo PHP nao foi encontrado em %PHP_EXE%.
    exit /b 1
)

"%PHP_EXE%" "%~dp0login_automation_fast.php"

if errorlevel 1 (
    echo.
    echo A automacao rapida terminou com erro.
    exit /b 1
)

exit /b 0
