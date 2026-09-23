@echo off
cd /d "%~dp0"
title QJ Local Web Server
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0webserver.ps1"
echo.
echo Web server exited.
pause
