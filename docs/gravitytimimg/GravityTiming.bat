@echo off
chcp 65001 >nul 2>&1
title GravityTiming 2.0

echo.
echo   ╔═══════════════════════════════════╗
echo   ║       GravityTiming 2.0           ║
echo   ║   Startar tidtagningssystemet...  ║
echo   ╚═══════════════════════════════════╝
echo.

cd /d "%~dp0"

:: Hitta Python
where python >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    where python3 >nul 2>&1
    if %ERRORLEVEL% NEQ 0 (
        echo   FEL: Python saknas.
        echo   Ladda ner fran: https://www.python.org/downloads/
        echo   VIKTIGT: Kryssa i "Add Python to PATH" vid installation!
        echo.
        pause
        exit /b 1
    )
    set PYTHON=python3
) else (
    set PYTHON=python
)

:: Installera beroenden om det behövs
%PYTHON% -c "import fastapi" 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo   Installerar beroenden (forsta gangen)...
    %PYTHON% -m pip install -q -r requirements.txt
    echo   Klart!
    echo.
)

echo   Servern kors. Webblasaren oppnas automatiskt.
echo   Stang detta fonster for att stoppa.
echo   ─────────────────────────────────────────────────
echo.

:: Starta servern (öppnar webbläsaren automatiskt)
%PYTHON% server.py
