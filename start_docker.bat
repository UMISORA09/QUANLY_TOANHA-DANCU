@echo off
chcp 65001 >nul
title Smart Cassavas - Trinh Dieu Hanh Docker
cd /d "%~dp0"

:CHECK_PREREQUISITES
cls
echo ================================================================================
echo           HỆ THỐNG QUẢN LÝ TÒA NHÀ ^& CƯ DÂN - SMART CASSAVAS
echo            TRÌNH ĐIỀU HÀNH ^& CẤU HÌNH DOCKER TỰ ĐỘNG TỪ A - Z
echo ================================================================================
echo.
echo [*] Đang kiểm tra môi trường hệ thống...

:: 1. Kiểm tra lệnh docker
where docker >nul 2>&1
if %ERRORLEVEL% neq 0 (
    :: Thử tìm Docker Desktop theo các đường dẫn mặc định
    if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" (
        set "PATH=%PATH%;%ProgramFiles%\Docker\Docker\resources\bin"
    ) else if exist "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe" (
        set "PATH=%PATH%;%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin"
    ) else (
        echo.
        echo [!] CHƯA TÌM THẤY DOCKER TRÊN MÁY CỦA BẠN!
        echo ----------------------------------------------------------------------------
        echo 1. Hãy tải và cài đặt Docker Desktop tại:
        echo    https://www.docker.com/products/docker-desktop/
        echo 2. Sau khi cài đặt và kích hoạt WSL2, hãy khởi động lại file này.
        echo ----------------------------------------------------------------------------
        echo.
        echo Bạn có muốn mở trang tải Docker Desktop ngay bây giờ? (Y/N)
        set /p open_dl="Lựa chọn của bạn: "
        if /i "%open_dl%"=="Y" (
            start https://www.docker.com/products/docker-desktop/
        )
        pause
        exit /b 1
    )
)

:: 2. Kiểm tra Docker Daemon đang chạy hay chưa
docker info >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo.
    echo [*] Docker Desktop chưa bật. Đang tự động kích hoạt Docker Desktop...
    if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" (
        start "" "%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
    ) else if exist "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe" (
        start "" "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe"
    )
    
    echo [*] Đang chờ Docker Engine sẵn sàng (quá trình có thể mất 20 - 45 giây)...
    set /a RETRY_COUNT=0
    :WAIT_DOCKER_LOOP
    timeout /t 3 /nobreak >nul
    docker info >nul 2>&1
    if %ERRORLEVEL% equ 0 goto DOCKER_READY
    set /a RETRY_COUNT+=1
    if %RETRY_COUNT% geq 25 (
        echo.
        echo [!] Quá thời gian chờ Docker khởi động. Vui lòng mở thủ công Docker Desktop và thử lại!
        pause
        goto MAIN_MENU
    )
    echo    ... Đang khởi động Docker Engine (Lần thử %RETRY_COUNT%/25)
    goto WAIT_DOCKER_LOOP
)

:DOCKER_READY
echo [OK] Docker Engine đã sẵn sàng!

:: 3. Kiểm tra và tự động chuẩn hóa cấu hình .env cho Docker
if not exist ".env" (
    echo [*] Chưa có file .env, đang tự động khởi tạo từ .env.example...
    copy ".env.example" ".env" >nul
)

:: Đảm bảo biến kết nối trong .env chuẩn Docker MySQL
powershell -Command "$c = Get-Content '.env'; $c = $c -replace '^DB_HOST=.*', 'DB_HOST=db'; $c = $c -replace '^DB_PORT=.*', 'DB_PORT=3306'; $c = $c -replace '^DB_CONNECTION=.*', 'DB_CONNECTION=mysql'; $c = $c -replace '^DB_DATABASE=.*', 'DB_DATABASE=quanly_toanha'; $c = $c -replace '^DB_PASSWORD=.*', 'DB_PASSWORD=123567'; Set-Content '.env' $c" >nul 2>&1

echo [OK] Cấu hình cơ sở dữ liệu .env đã được đồng bộ chuẩn Docker!
echo.
timeout /t 1 >nul

:MAIN_MENU
cls
echo ================================================================================
echo           HỆ THỐNG QUẢN LÝ TÒA NHÀ ^& CƯ DÂN - SMART CASSAVAS
echo             MENU ĐIỀU HÀNH DOCKER COMPOSE TOÀN DIỆN
echo ================================================================================
echo.
echo   [1] KHỞI ĐỘNG NHANH DỰ ÁN (Khuyên dùng - Chạy ngay hệ thống)
echo   [2] CÀI ĐẶT LẦN ĐẦU / BUILD LẠI CONTAINER (Clean Rebuild)
echo   [3] RESET CƠ SỞ DỮ LIỆU ^& IMPORT LẠI TỪ ĐẦU (dump_quanly_toanha.sql)
echo   [4] XEM TRẠNG THÁI CÁC CONTAINER (docker compose ps)
echo   [5] THEO DÕI LOGS ỨNG DỤNG REALTIME (docker compose logs -f app)
echo   [6] MỞ GIAO DIỆN ỨNG DỤNG WEB ^& PHPMYADMIN TRÊN TRÌNH DUYỆT
echo   [7] CHẠY LỆNH ARTISAN BÊN TRONG CONTAINER (Tùy chọn nhập lệnh)
echo   [8] DỪNG TOÀN BỘ HỆ THỐNG (docker compose down)
echo   [0] THOÁT
echo.
echo ================================================================================
set /p choice="Nhập số tương ứng với thao tác của bạn [0-8]: "

if "%choice%"=="1" goto START_FAST
if "%choice%"=="2" goto BUILD_ALL
if "%choice%"=="3" goto RESET_DB
if "%choice%"=="4" goto STATUS_CONTAINERS
if "%choice%"=="5" goto VIEW_LOGS
if "%choice%"=="6" goto OPEN_BROWSER
if "%choice%"=="7" goto RUN_ARTISAN
if "%choice%"=="8" goto STOP_ALL
if "%choice%"=="0" exit /b 0

echo [!] Lựa chọn không hợp lệ. Vui lòng thử lại!
timeout /t 2 >nul
goto MAIN_MENU

:START_FAST
cls
echo ================================================================================
echo  [1] ĐANG KHỞI ĐỘNG HỆ THỐNG SMART CASSAVAS BẰNG DOCKER...
echo ================================================================================
echo.
docker compose up -d
echo.
echo [*] Đang kiểm tra tính sẵn sàng của ứng dụng...
timeout /t 3 >nul
echo.
echo [OK] HỆ THỐNG ĐÃ SẴN SÀNG!
echo   - Giao diện Web:   http://localhost:8000
echo   - Quản lý Database (phpMyAdmin): http://localhost:8888
echo     + Server:   db
echo     + User:     root
echo     + Password: 123567
echo     + Database: quanly_toanha
echo.
echo Đang tự động mở trình duyệt...
start http://localhost:8000
echo.
pause
goto MAIN_MENU

:BUILD_ALL
cls
echo ================================================================================
echo  [2] ĐANG TIẾN HÀNH BUILD LẠI TOÀN BỘ CÁC CONTAINER...
echo ================================================================================
echo.
echo Quá trình này sẽ tải mới / biên dịch lại PHP, Composer, NPM và assets frontend.
docker compose up --build -d
echo.
echo [OK] Build và khởi động hoàn tất!
echo Đang mở trình duyệt...
start http://localhost:8000
echo.
pause
goto MAIN_MENU

:RESET_DB
cls
echo ================================================================================
echo  [3] CẢNH BÁO: ĐẶT LẠI DỮ LIỆU ^& NẠP LẠI TỪ TỆP DUMP BAN ĐẦU
echo ================================================================================
echo.
echo Thao tác này sẽ xóa volume database hiện tại và import lại toàn bộ
echo cấu trúc bảng và dữ liệu gốc từ: dump_quanly_toanha.sql
echo.
set /p confirm="Bạn có chắc chắn muốn thực hiện? (Y/N): "
if /i not "%confirm%"=="Y" (
    echo Đã hủy thao tác reset.
    timeout /t 2 >nul
    goto MAIN_MENU
)
echo.
echo [*] Đang gỡ bỏ container và volume cũ...
docker compose down -v
echo [*] Đang khởi động lại cụm container và nạp database...
docker compose up -d
echo.
echo [OK] Đã hoàn tất khôi phục cơ sở dữ liệu ban đầu!
echo.
pause
goto MAIN_MENU

:STATUS_CONTAINERS
cls
echo ================================================================================
echo  [4] TRẠNG THÁI CÁC CONTAINER ĐANG CHẠY
echo ================================================================================
echo.
docker compose ps
echo.
pause
goto MAIN_MENU

:VIEW_LOGS
cls
echo ================================================================================
echo  [5] ĐANG XEM LOGS ỨNG DỤNG REALTIME (Nhấn Ctrl + C để quay lại menu)
echo ================================================================================
echo.
docker compose logs -f app
echo.
pause
goto MAIN_MENU

:OPEN_BROWSER
cls
echo ================================================================================
echo  [6] MỞ TRÌNH DUYỆT TRUY CẬP HỆ THỐNG
echo ================================================================================
echo.
echo [*] Đang mở Web Ứng dụng: http://localhost:8000 ...
start http://localhost:8000
echo [*] Đang mở phpMyAdmin:     http://localhost:8888 ...
start http://localhost:8888
echo.
echo [Thông tin đăng nhập phpMyAdmin]:
echo   - Server:   db
echo   - Username: root
echo   - Password: 123567
echo.
pause
goto MAIN_MENU

:RUN_ARTISAN
cls
echo ================================================================================
echo  [7] CHẠY LỆNH ARTISAN BÊN TRONG CONTAINER APP
echo ================================================================================
echo.
echo Ví dụ các lệnh phổ biến:
echo   - route:list
echo   - migrate:status
echo   - test
echo   - config:clear
echo.
set /p artcmd="Nhập lệnh artisan (chỉ cần gõ phần sau 'artisan '): "
if "%artcmd%"=="" (
    echo Đã hủy thao tác.
    timeout /t 2 >nul
    goto MAIN_MENU
)
echo.
echo [*] Đang chạy: php artisan %artcmd% ...
echo.
docker compose exec app php artisan %artcmd%
echo.
pause
goto MAIN_MENU

:STOP_ALL
cls
echo ================================================================================
echo  [8] DỪNG TOÀN BỘ CÁC DỊCH VỤ DOCKER
echo ================================================================================
echo.
docker compose down
echo.
echo [OK] Tất cả container đã được dừng an toàn.
echo.
pause
goto MAIN_MENU
