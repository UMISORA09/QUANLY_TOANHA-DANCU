@echo off
cd /d "%~dp0"
title Smart Cassavas - Docker Launcher

:: Khoi chay Bang dieu khien noi co Tab (WPF GUI Dashboard)
start "" powershell.exe -STA -NoProfile -ExecutionPolicy Bypass -File "%~dp0docker_dashboard.ps1"
exit /b 0
