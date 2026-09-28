#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Safe Rollback Script
# Phục hồi tức thì về phiên bản PREVIOUS_IMAGE thực tế ổn định trước đó
# Đảm bảo an toàn 100% dữ liệu CSDL (Không migrate:fresh / Không xóa dữ liệu)
# ==============================================================================

set -eo pipefail

DEPLOY_ENV="${1:-production}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

echo "========================================================================"
echo "BẮT ĐẦU QUY TRÌNH ROLLBACK KHẨN CẤP"
echo "Môi trường: $DEPLOY_ENV"
echo "Thời gian: $(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')"
echo "========================================================================"

if [ "$DEPLOY_ENV" = "staging" ]; then
    PREVIOUS_IMAGE_FILE=".previous_staging_image"
    STABLE_IMAGE_FILE=".last_deployed_staging_image"
else
    PREVIOUS_IMAGE_FILE=".previous_image"
    STABLE_IMAGE_FILE=".last_deployed_image"
fi

# 1. Tìm PREVIOUS_IMAGE hợp lệ để phục hồi
PREVIOUS_IMAGE=""
if [ -f "$PREVIOUS_IMAGE_FILE" ] && [ -s "$PREVIOUS_IMAGE_FILE" ]; then
    PREVIOUS_IMAGE=$(cat "$PREVIOUS_IMAGE_FILE" | tr -d ' \t\n\r')
fi

# Nếu PREVIOUS_IMAGE_FILE không có, thử kiểm tra STABLE_IMAGE_FILE nếu khác image hiện tại đang chạy
CURRENT_RUNNING_IMAGE=$(docker inspect --format='{{.Config.Image}}' smart_cassavas_app 2>/dev/null || true)
if [ -z "$PREVIOUS_IMAGE" ] && [ -f "$STABLE_IMAGE_FILE" ] && [ -s "$STABLE_IMAGE_FILE" ]; then
    CANDIDATE=$(cat "$STABLE_IMAGE_FILE" | tr -d ' \t\n\r')
    if [ "$CANDIDATE" != "$CURRENT_RUNNING_IMAGE" ]; then
        PREVIOUS_IMAGE="$CANDIDATE"
    fi
fi

# 2. Xử lý trường hợp lần đầu tiên triển khai hoặc không có image cũ
if [ -z "$PREVIOUS_IMAGE" ]; then
    echo "========================================================================"
    echo "❌ LỖI: Không tìm thấy PREVIOUS_IMAGE hợp lệ để khôi phục!"
    echo "Đây là lần đầu tiên triển khai (First deployment) hoặc không có bản sao lưu trước đó."
    echo "Trạng thái: ROLLBACK_UNAVAILABLE"
    echo "========================================================================"
    exit 1
fi

echo "Tìm thấy phiên bản trước đó để phục hồi: $PREVIOUS_IMAGE"
echo "Đang triển khai lại PREVIOUS_IMAGE vào container..."

export DEPLOY_IMAGE="$PREVIOUS_IMAGE"
docker compose -f docker-compose.prod.yml up -d --remove-orphans

echo "Đang kiểm tra sức khỏe hệ thống sau khi phục hồi (Rollback Verification)..."
if "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 20 2; then
    echo "========================================================================"
    echo "✅ ROLLBACK HOÀN TẤT THÀNH CÔNG AN TOÀN!"
    echo "Hệ thống Smart Cassavas đã khôi phục ổn định về: $PREVIOUS_IMAGE"
    echo "Dữ liệu cơ sở dữ liệu được bảo toàn nguyên vẹn 100%."
    echo "========================================================================"
    # Cập nhật lại file stable image về phiên bản đã rollback thành công
    echo "$PREVIOUS_IMAGE" > "$STABLE_IMAGE_FILE"
    exit 0
else
    echo "========================================================================"
    echo "🚨 CẢNH BÁO NGUY CẤP: Rollback không thể đưa ứng dụng về trạng thái sẵn sàng!"
    echo "Phiên bản khôi phục ($PREVIOUS_IMAGE) cũng không vượt qua health check."
    echo "Vui lòng kiểm tra nhật ký container bằng lệnh: docker logs smart_cassavas_app"
    echo "========================================================================"
    exit 1
fi
