@echo off
chcp 65001 >nul
title VIRAX realtime server
cd /d "%~dp0"

rem ── already running? ────────────────────────────────────────────────────────
netstat -ano | findstr /R /C:"127.0.0.1:3000 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [VIRAX] realtime server is already listening on 127.0.0.1:3000
    timeout /t 3 >nul
    exit /b 0
)

where node >nul 2>&1
if errorlevel 1 (
    echo [VIRAX] Node.js was not found on PATH.
    echo         Install Node 18+ from https://nodejs.org and run this file again.
    pause
    exit /b 1
)

if not exist "node_modules\socket.io" (
    echo [VIRAX] installing dependencies, this runs once...
    call npm install --no-audit --no-fund
    if errorlevel 1 (
        echo [VIRAX] npm install failed.
        pause
        exit /b 1
    )
)

echo [VIRAX] starting realtime server on 127.0.0.1:3000
echo         keep this window open; closing it stops realtime delivery.
node server.js

echo.
echo [VIRAX] realtime server stopped.
pause
