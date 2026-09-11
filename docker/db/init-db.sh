#!/bin/bash
set -e

SA_PASSWORD="${MSSQL_SA_PASSWORD:-SmartCassavas@2026}"
SERVER="db"

echo "=========================================================="
echo " [Docker DB-Init] Đang kiểm tra kết nối SQL Server ($SERVER)..."
echo "=========================================================="

# Vòng lặp chờ SQL Server sẵn sàng tiếp nhận kết nối
MAX_RETRIES=30
COUNT=0
until /opt/mssql-tools18/bin/sqlcmd -S "$SERVER" -U sa -P "$SA_PASSWORD" -C -Q "SELECT 1" &> /dev/null
do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_RETRIES ]; then
        echo " [ERROR] Quá thời gian chờ SQL Server khởi động!"
        exit 1
    fi
    echo " [Chờ...] SQL Server đang khởi tạo ($COUNT/$MAX_RETRIES)..."
    sleep 2
done

echo " [OK] SQL Server đã sẵn sàng!"
echo "=========================================================="
echo " [Docker DB-Init] Đang nạp CSDL_CHUNGCU&DANCU.sql vào database..."
echo "=========================================================="

# Chạy tệp SQL nạp cấu trúc bảng và dữ liệu khởi tạo
/opt/mssql-tools18/bin/sqlcmd -S "$SERVER" -U sa -P "$SA_PASSWORD" -C -i "/docker-entrypoint-initdb.d/CSDL_CHUNGCU&DANCU.sql"

echo "=========================================================="
echo " [Xác thực] Kiểm tra số lượng bảng đã nạp:"
/opt/mssql-tools18/bin/sqlcmd -S "$SERVER" -U sa -P "$SA_PASSWORD" -C -d "CSDL_CHUNGCU&DANCU" -Q "SELECT COUNT(*) AS [Tong_So_Bang_Da_Tao] FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE';"

echo "=========================================================="
echo " [HOÀN TẤT] Cơ sở dữ liệu đồ án đã được nạp thành công!"
echo "=========================================================="
