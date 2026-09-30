#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Safe Rollback Script
# Phục hồi tức thì về phiên bản PREVIOUS_IMAGE thực tế ổn định trước đó
# Đảm bảo an toàn 100% dữ liệu CSDL (Không migrate:fresh / Không xóa dữ liệu)
# ==============================================================================

set -Eeuo pipefail

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
    TIME_FILE=".last_deployed_staging_time"
else
    PREVIOUS_IMAGE_FILE=".previous_image"
    STABLE_IMAGE_FILE=".last_deployed_image"
    TIME_FILE=".last_deployed_time"
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

# Đăng nhập GHCR nếu có thông tin xác thực
if [ -n "${GITHUB_ACTOR:-}" ] && [ -n "${GITHUB_TOKEN:-}" ]; then
    echo "Đang xác thực với GitHub Container Registry..."
    echo "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
fi

# 3. Kéo Docker image trước đó từ Registry (Fail-fast nếu image không tồn tại)
echo "Đang kéo Docker image khôi phục từ Registry: $PREVIOUS_IMAGE ..."
if ! docker pull "$PREVIOUS_IMAGE"; then
    echo "========================================================================"
    echo "❌ LỖI NGHIÊM TRỌNG: Không thể docker pull phiên bản trước: $PREVIOUS_IMAGE"
    echo "Không tìm thấy image trong local cache hoặc registry. Rollback bị hủy bỏ."
    echo "========================================================================"
    exit 1
fi

echo "Đang triển khai lại PREVIOUS_IMAGE vào container..."
export DEPLOY_IMAGE="$PREVIOUS_IMAGE"
if ! docker compose -f docker-compose.prod.yml up -d --remove-orphans; then
    echo "========================================================================"
    echo "❌ LỖI NGHIÊM TRỌNG: Khởi chạy container cho previous image thất bại!"
    echo "========================================================================"
    exit 1
fi

# 4. Xác minh toàn diện sau phục hồi (Health + Smoke + Freshness)
echo "Đang xác minh hệ thống sau khi phục hồi..."

echo "Phase 1: Health Check..."
if ! "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 20 2; then
    echo "========================================================================"
    echo "🚨 CẢNH BÁO NGUY CẤP: Rollback không thể đưa ứng dụng về trạng thái sẵn sàng!"
    echo "Phiên bản khôi phục ($PREVIOUS_IMAGE) không vượt qua health check."
    echo "========================================================================"
    exit 1
fi

echo "Phase 2: Smoke Test..."
if ! "$SCRIPT_DIR/smoke-test.sh" "http://localhost:8000"; then
    echo "========================================================================"
    echo "🚨 CẢNH BÁO NGUY CẤP: Phiên bản khôi phục ($PREVIOUS_IMAGE) không vượt qua smoke test!"
    echo "========================================================================"
    exit 1
fi

echo "Phase 3: Freshness Verification..."
if ! "$SCRIPT_DIR/freshness-verify.sh" "http://localhost:8000/api/monitoring/freshness" 15 2 "$DEPLOY_ENV"; then
    echo "========================================================================"
    echo "🚨 CẢNH BÁO NGUY CẤP: Phiên bản khôi phục ($PREVIOUS_IMAGE) không vượt qua freshness check!"
    echo "========================================================================"
    exit 1
fi

# 5. Cập nhật trạng thái ổn định khi và chỉ khi TẤT CẢ kiểm thử thành công
echo "$PREVIOUS_IMAGE" > "$STABLE_IMAGE_FILE"
echo "$(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')" > "$TIME_FILE"

echo "========================================================================"
echo "✅ ROLLBACK HOÀN TẤT THÀNH CÔNG VÀ ĐÃ XÁC MINH TOÀN DIỆN!"
echo "Hệ thống Smart Cassavas đã khôi phục ổn định về: $PREVIOUS_IMAGE"
echo "Health Check:    PASSED"
echo "Smoke Test:      PASSED"
echo "Freshness Check: PASSED"
echo "Application image restored."
echo "Database schema remains according to backward-compatible migration strategy."
echo "Thời gian ghi nhận: $(cat "$TIME_FILE")"
echo "========================================================================"
exit 0
