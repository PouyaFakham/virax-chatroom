@echo off
chcp 65001 >nul
title VIRAX realtime server - stop

set FOUND=0
for /f "tokens=5" %%P in ('netstat -ano ^| findstr /R /C:"127.0.0.1:3000 .*LISTENING"') do (
    echo [VIRAX] stopping realtime server, pid %%P
    taskkill /PID %%P /F >nul 2>&1
    set FOUND=1
)

if "%FOUND%"=="0" echo [VIRAX] nothing was listening on 127.0.0.1:3000
timeout /t 2 >nul
