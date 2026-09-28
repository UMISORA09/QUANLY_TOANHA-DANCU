#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Container Deployment & Verification Script
# Cơ chế: Container Rolling Restart với kiểm thử 2 giai đoạn & Tự động Rollback
# Lưu ý: Quá trình triển khai có restart container (downtime ngắn trong vài giây khi container khởi động)
# ==============================================================================

set -eo pipefail

TARGET_IMAGE="$1"
DEPLOY_ENV="${2:-production}"

if [ -z "$TARGET_IMAGE" ]; then
    echo "LỖI: Chưa cung cấp image cần deploy!"
    echo "Sử dụng: ./scripts/deploy.sh <IMAGE_TAG_OR_DIGEST> [staging|production]"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

echo "========================================================================"
echo "BẮT ĐẦU TRIỂN KHAI PHIÊN BẢN: $TARGET_IMAGE"
echo "Môi trường: $DEPLOY_ENV"
echo "Phương thức: Container Restart (có thời gian tái khởi động ngắn)"
echo "Thời gian: $(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')"
echo "========================================================================"

# Đăng nhập GHCR nếu có thông tin xác thực
if [ -n "$GITHUB_ACTOR" ] && [ -n "$GITHUB_TOKEN" ]; then
    echo "Đang xác thực với GitHub Container Registry..."
    echo "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
fi

# Xác định file lưu trữ metadata theo môi trường
if [ "$DEPLOY_ENV" = "staging" ]; then
    BACKUP_FILE=".last_deployed_staging_image"
    TIME_FILE=".last_deployed_staging_time"
else
    BACKUP_FILE=".last_deployed_image"
    TIME_FILE=".last_deployed_time"
fi

# Lưu lại phiên bản hiện tại trước khi cập nhật để phục vụ Rollback
CURRENT_IMAGE=$(docker inspect --format='{{.Config.Image}}' smart_cassavas_app 2>/dev/null || true)
if [ -n "$CURRENT_IMAGE" ]; then
    echo "Sao lưu tham chiếu phiên bản trước ($BACKUP_FILE): $CURRENT_IMAGE"
    echo "$CURRENT_IMAGE" > "$BACKUP_FILE"
fi

# Kéo Docker image mới nhất từ Registry
echo "Đang kéo Docker image: $TARGET_IMAGE ..."
docker pull "$TARGET_IMAGE"

# Cập nhật và khởi chạy container với image mới
echo "Đang kích hoạt container ứng dụng mới (Restarting container)..."
export DEPLOY_IMAGE="$TARGET_IMAGE"
docker compose -f docker-compose.prod.yml up -d --remove-orphans

# Ghi nhận thời gian và image mới triển khai (Giờ Việt Nam UTC+7)
echo "$(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')" > "$TIME_FILE"
if [ "$DEPLOY_ENV" = "staging" ]; then
    echo "$TARGET_IMAGE" > .last_deployed_staging_image
else
    echo "$TARGET_IMAGE" > .last_deployed_image
fi

# Giai đoạn 1: Kiểm tra sức khỏe hệ thống (Phase 1: Health Check)
echo "Đang xác minh sức khỏe hệ thống sau triển khai (Phase 1: Health Check)..."
if ! "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 20 3; then
    echo "========================================================================"
    echo "CẢNH BÁO: Health check thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

# Giai đoạn 2: Kiểm thử luồng nghiệp vụ sau triển khai (Phase 2: Smoke Test)
echo "Đang chạy bộ kiểm thử Smoke Test (Phase 2: Smoke Test)..."
if ! "$SCRIPT_DIR/smoke-test.sh" "http://localhost:8000"; then
    echo "========================================================================"
    echo "CẢNH BÁO: Smoke test thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

# Giai đoạn 3: Xác minh độ tươi mới của dữ liệu quan sát (Phase 3: Freshness Verification)
echo "Đang kiểm tra Freshness Observability (Phase 3: Freshness Verification)..."
if ! "$SCRIPT_DIR/freshness-verify.sh" "http://localhost:8000/api/monitoring/freshness" 15 3; then
    echo "========================================================================"
    echo "CẢNH BÁO: Freshness Verification thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

echo "========================================================================"
echo "TRIỂN KHAI VÀ XÁC MINH 3 GIAI ĐOẠN HOÀN TẤT THÀNH CÔNG!"
echo "Môi trường: $DEPLOY_ENV"
echo "Phiên bản đang hoạt động: $TARGET_IMAGE"
echo "Health Check:    PASSED"
echo "Smoke Test:      PASSED"
echo "Freshness Check: PASSED"
echo "Thời gian ghi nhận: $(cat "$TIME_FILE")"
echo "========================================================================"
exit 0
