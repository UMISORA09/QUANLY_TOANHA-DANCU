@echo off
chcp 65001 >nul
title Smart Cassavas - Docker Launcher
cd /d "%~dp0"

:: Nếu người dùng truyền cờ --cli hoặc -c thì mở giao diện Terminal Console
if /i "%1"=="--cli" goto CLI_MENU
if /i "%1"=="-c" goto CLI_MENU

:: Mặc định: Khởi chạy ngay Cửa sổ Bảng điều khiển nổi có Tab (Floating Tabbed GUI Window)
echo Đang mở Bảng điều khiển nổi Smart Cassavas Docker...
start "" powershell.exe -ExecutionPolicy Bypass -NoProfile -WindowStyle Hidden -File "%~dp0docker_dashboard.ps1"
exit /b 0

:CLI_MENU
:: Chế độ dòng lệnh dự phòng (Console Menu Mode)
cls
echo ================================================================================
echo           HỆ THỐNG QUẢN LÝ TÒA NHÀ ^& CƯ DÂN - SMART CASSAVAS
echo             MENU ĐIỀU HÀNH DOCKER COMPOSE (CONSOLE MODE)
echo ================================================================================
echo.
echo   [1] KHỞI ĐỘNG NHANH DỰ ÁN (Chạy ngay hệ thống)
echo   [2] CÀI ĐẶT LẦN ĐẦU / BUILD LẠI CONTAINER (Clean Rebuild)
echo   [3] RESET CƠ SỞ DỮ LIỆU ^& IMPORT LẠI TỪ ĐẦU (dump_quanly_toanha.sql)
echo   [4] XEM TRẠNG THÁI CÁC CONTAINER (docker compose ps)
echo   [5] THEO DÕI LOGS ỨNG DỤNG REALTIME (docker compose logs -f app)
echo   [6] MỞ GIAO DIỆN ỨNG DỤNG WEB ^& PHPMYADMIN
echo   [7] MỞ BẢNG ĐIỀU KHIỂN NỔI CÓ TAB (GUI DASHBOARD)
echo   [8] DỪNG TOÀN BỘ HỆ THỐNG (docker compose down)
echo   [0] THOÁT
echo.
echo ================================================================================
set /p choice="Nhập số tương ứng với thao tác của bạn [0-8]: "

if "%choice%"=="1" (
    docker compose up -d
    start http://localhost:8000
    pause
    goto CLI_MENU
)
if "%choice%"=="2" (
    docker compose up --build -d
    start http://localhost:8000
    pause
    goto CLI_MENU
)
if "%choice%"=="3" (
    docker compose down -v
    docker compose up -d
    pause
    goto CLI_MENU
)
if "%choice%"=="4" (
    docker compose ps
    pause
    goto CLI_MENU
)
if "%choice%"=="5" (
    docker compose logs -f app
    pause
    goto CLI_MENU
)
if "%choice%"=="6" (
    start http://localhost:8000
    start http://localhost:8888
    goto CLI_MENU
)
if "%choice%"=="7" (
    start "" powershell.exe -ExecutionPolicy Bypass -NoProfile -WindowStyle Hidden -File "%~dp0docker_dashboard.ps1"
    exit /b 0
)
if "%choice%"=="8" (
    docker compose down
    pause
    goto CLI_MENU
)
if "%choice%"=="0" exit /b 0

goto CLI_MENU
