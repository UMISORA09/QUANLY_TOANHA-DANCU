#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Container Deployment & Verification Script
# Kiến trúc:
#   CURRENT_IMAGE -> PREVIOUS_IMAGE -> NEW_IMAGE -> DB MIGRATION -> DEPLOY ->
#   HEALTH -> SMOKE -> FRESHNESS -> MARK_STABLE
# ==============================================================================

set -Eeuo pipefail

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
echo "Thời gian: $(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')"
echo "========================================================================"

# Đăng nhập GHCR nếu có thông tin xác thực
if [ -n "${GITHUB_ACTOR:-}" ] && [ -n "${GITHUB_TOKEN:-}" ]; then
    echo "Đang xác thực với GitHub Container Registry..."
    echo "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
fi

# Xác định file lưu trữ metadata theo môi trường
if [ "$DEPLOY_ENV" = "staging" ]; then
    PREVIOUS_IMAGE_FILE=".previous_staging_image"
    STABLE_IMAGE_FILE=".last_deployed_staging_image"
    TIME_FILE=".last_deployed_staging_time"
else
    PREVIOUS_IMAGE_FILE=".previous_image"
    STABLE_IMAGE_FILE=".last_deployed_image"
    TIME_FILE=".last_deployed_time"
fi

# 1. Xác định và lưu trữ PREVIOUS_IMAGE trước khi thay đổi container
CURRENT_RUNNING_IMAGE=$(docker inspect --format='{{.Config.Image}}' smart_cassavas_app 2>/dev/null || true)

if [ -n "$CURRENT_RUNNING_IMAGE" ] && [ "$CURRENT_RUNNING_IMAGE" != "<no value>" ]; then
    PREVIOUS_IMAGE="$CURRENT_RUNNING_IMAGE"
    echo "Phát hiện image đang hoạt động thực tế: $PREVIOUS_IMAGE"
    echo "$PREVIOUS_IMAGE" > "$PREVIOUS_IMAGE_FILE"
elif [ -f "$STABLE_IMAGE_FILE" ] && [ -s "$STABLE_IMAGE_FILE" ]; then
    PREVIOUS_IMAGE=$(cat "$STABLE_IMAGE_FILE" | tr -d ' \t\n\r')
    echo "Phát hiện phiên bản ổn định đã ghi nhận trước đó: $PREVIOUS_IMAGE"
    echo "$PREVIOUS_IMAGE" > "$PREVIOUS_IMAGE_FILE"
else
    PREVIOUS_IMAGE=""
    echo "CẢNH BÁO: Đây là lần triển khai đầu tiên (First deployment) - Chưa có PREVIOUS_IMAGE!"
    rm -f "$PREVIOUS_IMAGE_FILE"
fi

# 2. Kéo Docker image mới nhất từ Registry
echo "Đang kéo Docker image mới: $TARGET_IMAGE ..."
docker pull "$TARGET_IMAGE"

# 3. Khởi chạy container với image mới trước (Expand phase)
# Đảm bảo container chạy được mã nguồn mới tương thích với schema hiện tại/mới
echo "Đang kích hoạt container ứng dụng mới với target: $TARGET_IMAGE ..."
export DEPLOY_IMAGE="$TARGET_IMAGE"
if ! docker compose -f docker-compose.prod.yml up -d --remove-orphans; then
    echo "========================================================================"
    echo "❌ LỖI NGHIÊM TRỌNG: Khởi chạy container ứng dụng mới thất bại!"
    echo "========================================================================"
    if [ -n "$PREVIOUS_IMAGE" ]; then
        echo "Kích hoạt Rollback về phiên bản trước: $PREVIOUS_IMAGE ..."
        "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    else
        echo "ROLLBACK_UNAVAILABLE: Không có phiên bản trước để rollback."
    fi
    exit 1
fi

# 4. Thực thi Database Migration an toàn (Expand-Contract principle)
# Migration chạy sau khi app container mới đã online, không phá vỡ tính tương thích ngược
echo "Đang kiểm tra và thực thi Database Migrations an toàn..."
if ! docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force --no-interaction; then
    echo "========================================================================"
    echo "❌ LỖI NGHIÊM TRỌNG: Database Migration thất bại!"
    echo "Giới hạn kiến trúc: Rollback phục hồi Application Image."
    echo "Database schema được bảo toàn theo nguyên tắc tương thích ngược (Expand-Contract)."
    echo "Không tự động chạy DB rollback nguy hiểm để tránh mất dữ liệu sản xuất."
    echo "========================================================================"
    if [ -n "$PREVIOUS_IMAGE" ]; then
        echo "Kích hoạt Rollback về phiên bản trước: $PREVIOUS_IMAGE ..."
        "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    else
        echo "ROLLBACK_UNAVAILABLE: Không có phiên bản trước để rollback."
    fi
    exit 1
fi
echo "✅ Database Migrations hoàn tất an toàn."

# 5. Giai đoạn 1: Kiểm tra sức khỏe hệ thống (Phase 1: Health Check)
echo "Đang xác minh sức khỏe hệ thống sau triển khai (Phase 1: Health Check)..."
if ! "$SCRIPT_DIR/health-check.sh" "http://localhost:8000/health" 20 3; then
    echo "========================================================================"
    echo "❌ CẢNH BÁO: Health check thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

# 6. Giai đoạn 2: Kiểm thử luồng nghiệp vụ sau triển khai (Phase 2: Smoke Test)
echo "Đang chạy bộ kiểm thử Smoke Test (Phase 2: Smoke Test)..."
if ! "$SCRIPT_DIR/smoke-test.sh" "http://localhost:8000"; then
    echo "========================================================================"
    echo "❌ CẢNH BÁO: Smoke test thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

# 7. Giai đoạn 3: Xác minh độ tươi mới của dữ liệu quan sát (Phase 3: Freshness Verification)
echo "Đang kiểm tra Freshness Observability (Phase 3: Freshness Verification)..."
if ! "$SCRIPT_DIR/freshness-verify.sh" "http://localhost:8000/api/monitoring/freshness" 15 3; then
    echo "========================================================================"
    echo "❌ CẢNH BÁO: Freshness Verification thất bại! Đang tự động kích hoạt Rollback..."
    echo "========================================================================"
    "$SCRIPT_DIR/rollback.sh" "$DEPLOY_ENV"
    exit 1
fi

# 8. HẬU KIỂM HOÀN TẤT: CHỈ ĐÁNH DẤU NEW_IMAGE LÀ STABLE KHI VƯỢT QUA TẤT CẢ CÁC BƯỚC
echo "Đánh dấu phiên bản mới là STABLE ($STABLE_IMAGE_FILE): $TARGET_IMAGE"
echo "$TARGET_IMAGE" > "$STABLE_IMAGE_FILE"
echo "$(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')" > "$TIME_FILE"

echo "========================================================================"
echo "DEPLOYMENT_VERIFIED: TRIỂN KHAI VÀ XÁC MINH HOÀN TẤT THÀNH CÔNG!"
echo "Môi trường: $DEPLOY_ENV"
echo "Phiên bản đang hoạt động (STABLE): $TARGET_IMAGE"
echo "Phiên bản trước đó (PREVIOUS): ${PREVIOUS_IMAGE:-None}"
echo "Health Check:    PASSED"
echo "Smoke Test:      PASSED"
echo "Freshness Check: PASSED"
echo "Thời gian ghi nhận: $(cat "$TIME_FILE")"
echo "========================================================================"
exit 0
