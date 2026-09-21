#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Safe Rollback Script
# Phục hồi tức thì về phiên bản Docker Image ổn định trước đó
# Đảm bảo an toàn 100% dữ liệu CSDL (Không migrate:fresh / Không xóa dữ liệu)
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

echo "========================================================================"
echo "BẮT ĐẦU QUY TRÌNH ROLLBACK KHẨN CẤP"
echo "Thời gian: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "========================================================================"

BACKUP_IMAGE_FILE=".last_deployed_image"

if [ ! -f "$BACKUP_IMAGE_FILE" ] || [ ! -s "$BACKUP_IMAGE_FILE" ]; then
    echo "LỖI: Không tìm thấy tệp ghi nhận phiên bản ổn định trước đó ($BACKUP_IMAGE_FILE)!"
    echo "Vui lòng chỉ định thủ công image ổn định cần phục hồi: ./scripts/deploy.sh <IMAGE_TAG>"
    exit 1
fi

PREVIOUS_IMAGE=$(cat "$BACKUP_IMAGE_FILE" | tr -d ' \t\n\r')

echo "Đang khôi phục hệ thống về phiên bản trước đó: $PREVIOUS_IMAGE"

export DEPLOY_IMAGE="$PREVIOUS_IMAGE"
docker compose -f docker-compose.prod.yml up -d --remove-orphans

echo "Đang kiểm tra sức khỏe hệ thống sau khi phục hồi (Rollback Verification)..."
if "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 15 2; then
    echo "========================================================================"
    echo "ROLLBACK HOÀN TẤT THÀNH CÔNG AN TOÀN!"
    echo "Hệ thống Smart Cassavas đã khôi phục ổn định về: $PREVIOUS_IMAGE"
    echo "Dữ liệu cơ sở dữ liệu được bảo toàn nguyên vẹn 100%."
    echo "========================================================================"
    exit 0
else
    echo "========================================================================"
    echo "CẢNH BÁO NGUY CẤP: Rollback không thể đưa ứng dụng về trạng thái sẵn sàng!"
    echo "Vui lòng kiểm tra nhật ký container bằng lệnh: docker logs smart_cassavas_app"
    echo "========================================================================"
    exit 1
fi
