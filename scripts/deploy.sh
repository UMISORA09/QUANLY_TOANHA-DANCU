#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Zero-Downtime Deployment & Verification Script
# ==============================================================================

set -eo pipefail

TARGET_IMAGE="$1"

if [ -z "$TARGET_IMAGE" ]; then
    echo "LỖI: Chưa cung cấp image cần deploy!"
    echo "Sử dụng: ./scripts/deploy.sh <IMAGE_TAG_OR_DIGEST>"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

echo "========================================================================"
echo "BẮT ĐẦU TRIỂN KHAI PHIÊN BẢN: $TARGET_IMAGE"
echo "Thời gian: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "========================================================================"

# Đăng nhập GHCR nếu có thông tin xác thực
if [ -n "$GITHUB_ACTOR" ] && [ -n "$GITHUB_TOKEN" ]; then
    echo "Đang xác thực với GitHub Container Registry..."
    echo "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
fi

# Lưu lại phiên bản hiện tại trước khi cập nhật để phục vụ Rollback
CURRENT_IMAGE=$(docker inspect --format='{{.Config.Image}}' smart_cassavas_app 2>/dev/null || true)
if [ -n "$CURRENT_IMAGE" ]; then
    echo "Sao lưu tham chiếu phiên bản trước: $CURRENT_IMAGE"
    echo "$CURRENT_IMAGE" > .last_deployed_image
fi

# Kéo Docker image mới nhất từ Registry
echo "Đang kéo Docker image: $TARGET_IMAGE ..."
docker pull "$TARGET_IMAGE"

# Cập nhật và khởi chạy container với image mới
echo "Đang kích hoạt container ứng dụng mới..."
export DEPLOY_IMAGE="$TARGET_IMAGE"
docker compose -f docker-compose.prod.yml up -d --remove-orphans

# Giai đoạn 1: Kiểm tra sức khỏe hệ thống (Health Check)
echo "Đang xác minh sức khỏe hệ thống sau triển khai (Phase 1: Health Check)..."
if ! "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 20 3; then
    echo "========================================================================"
    echo "CẢNH BÁO: Health check thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh"
    exit 1
fi

# Giai đoạn 2: Kiểm thử luồng nghiệp vụ sau triển khai (Phase 2: Smoke Test)
echo "Đang chạy bộ kiểm thử Smoke Test (Phase 2: Smoke Test)..."
if ! "$SCRIPT_DIR/smoke-test.sh" "http://localhost:8000"; then
    echo "========================================================================"
    echo "CẢNH BÁO: Smoke test thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh"
    exit 1
fi

echo "========================================================================"
echo "TRIỂN KHAI THÀNH CÔNG RỰC RỠ!"
echo "Phiên bản đang hoạt động: $TARGET_IMAGE"
echo "========================================================================"
exit 0
