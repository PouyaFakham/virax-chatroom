@echo off
chcp 65001 >nul
title VIRAX realtime server - start with Windows

rem Puts a tiny launcher in the per-user Startup folder. No admin rights, no
rem COM objects, no registry: deleting that one file undoes it completely.

set "LAUNCHER=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\VIRAX-Realtime.cmd"
set "DIR=%~dp0"

> "%LAUNCHER%" echo @echo off
>> "%LAUNCHER%" echo cd /d "%DIR%"
>> "%LAUNCHER%" echo netstat -ano ^| findstr /R /C:"127.0.0.1:3000 .*LISTENING" ^>nul 2^>^&1
>> "%LAUNCHER%" echo if errorlevel 1 start "" /MIN "%DIR%start-ws.bat"

if exist "%LAUNCHER%" (
    echo [VIRAX] done. The realtime server will start with Windows.
    echo         launcher: %LAUNCHER%
    echo         to undo:  delete that file.
) else (
    echo [VIRAX] could not write the launcher.
)
timeout /t 4 >nul
