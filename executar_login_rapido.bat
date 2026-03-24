@echo off
setlocal

cd /d "%~dp0"

set "PHP_EXE=C:\RoboEmissor\php\php.exe"

if not exist "%PHP_EXE%" (
    echo PHP nao foi encontrado em %PHP_EXE%.
    pause
    exit /b 1
)

"%PHP_EXE%" "%~dp0login_automation_fast.php"

if errorlevel 1 (
    echo.
    echo A automacao rapida terminou com erro.
    pause
    exit /b 1
)

echo.
echo Automacao rapida finalizada com sucesso.
pause
