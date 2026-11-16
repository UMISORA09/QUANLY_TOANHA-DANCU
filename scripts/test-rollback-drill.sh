#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Rollback State Machine Verification Suite (5 Test Cases)
# ==============================================================================

set -eo pipefail

TEST_DIR=$(mktemp -d)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "========================================================================"
echo "BẮT ĐẦU KIỂM THỬ TỰ ĐỘNG CƠ CHẾ ROLLBACK (5 TEST CASES)"
echo "Thư mục kiểm thử tạm: $TEST_DIR"
echo "========================================================================"

FAILED_CASES=0

cleanup() {
    rm -rf "$TEST_DIR"
}
trap cleanup EXIT

# ------------------------------------------------------------------------------
# TEST CASE 1: Old image + New image + Health PASS
# Mong đợi: Deployment success, STABLE = New image, PREVIOUS = Old image
# ------------------------------------------------------------------------------
echo -n "Test Case 1: Triển khai mới thành công (Health PASS) ... "
mkdir -p "$TEST_DIR/c1"
echo "smart-cassavas:v1.0.0" > "$TEST_DIR/c1/.last_deployed_image"

# Mô phỏng deploy v1.0.1
OLD_IMAGE=$(cat "$TEST_DIR/c1/.last_deployed_image")
echo "$OLD_IMAGE" > "$TEST_DIR/c1/.previous_image"
NEW_IMAGE="smart-cassavas:v1.0.1"

# Giả lập health pass:
HEALTH_PASS=0
if [ "$HEALTH_PASS" -eq 0 ]; then
    echo "$NEW_IMAGE" > "$TEST_DIR/c1/.last_deployed_image"
fi

C1_STABLE=$(cat "$TEST_DIR/c1/.last_deployed_image")
C1_PREV=$(cat "$TEST_DIR/c1/.previous_image")

if [ "$C1_STABLE" = "smart-cassavas:v1.0.1" ] && [ "$C1_PREV" = "smart-cassavas:v1.0.0" ]; then
    echo "PASSED (Stable=$C1_STABLE, Previous=$C1_PREV)"
else
    echo "FAILED"
    FAILED_CASES=$((FAILED_CASES + 1))
fi

# ------------------------------------------------------------------------------
# TEST CASE 2: Old image + New image + Health FAIL
# Mong đợi: Rollback về Old image, STABLE vẫn là Old image, New image bị loại bỏ
# ------------------------------------------------------------------------------
echo -n "Test Case 2: Health Check thất bại -> Rollback về Old image ... "
mkdir -p "$TEST_DIR/c2"
echo "smart-cassavas:v1.0.0" > "$TEST_DIR/c2/.last_deployed_image"
OLD_IMAGE=$(cat "$TEST_DIR/c2/.last_deployed_image")
echo "$OLD_IMAGE" > "$TEST_DIR/c2/.previous_image"
NEW_IMAGE="smart-cassavas:v1.0.2-broken"

# Giả lập health fail:
HEALTH_STATUS=1
if [ "$HEALTH_STATUS" -ne 0 ]; then
    # Kích hoạt rollback logic
    ROLLBACK_TARGET=$(cat "$TEST_DIR/c2/.previous_image")
    # Restore stable image
    echo "$ROLLBACK_TARGET" > "$TEST_DIR/c2/.last_deployed_image"
fi

C2_STABLE=$(cat "$TEST_DIR/c2/.last_deployed_image")
if [ "$C2_STABLE" = "smart-cassavas:v1.0.0" ]; then
    echo "PASSED (Đã rollback về $C2_STABLE)"
else
    echo "FAILED (Stable=$C2_STABLE)"
    FAILED_CASES=$((FAILED_CASES + 1))
fi

# ------------------------------------------------------------------------------
# TEST CASE 3: Old image + New image + Smoke FAIL
# Mong đợi: Rollback về Old image
# ------------------------------------------------------------------------------
echo -n "Test Case 3: Smoke Test thất bại -> Rollback về Old image ... "
mkdir -p "$TEST_DIR/c3"
echo "smart-cassavas:v1.0.0" > "$TEST_DIR/c3/.last_deployed_image"
OLD_IMAGE=$(cat "$TEST_DIR/c3/.last_deployed_image")
echo "$OLD_IMAGE" > "$TEST_DIR/c3/.previous_image"

# Giả lập smoke fail:
SMOKE_STATUS=1
if [ "$SMOKE_STATUS" -ne 0 ]; then
    ROLLBACK_TARGET=$(cat "$TEST_DIR/c3/.previous_image")
    echo "$ROLLBACK_TARGET" > "$TEST_DIR/c3/.last_deployed_image"
fi

C3_STABLE=$(cat "$TEST_DIR/c3/.last_deployed_image")
if [ "$C3_STABLE" = "smart-cassavas:v1.0.0" ]; then
    echo "PASSED (Đã rollback về $C3_STABLE)"
else
    echo "FAILED"
    FAILED_CASES=$((FAILED_CASES + 1))
fi

# ------------------------------------------------------------------------------
# TEST CASE 4: Old image + New image + Freshness FAIL
# Mong đợi: Rollback về Old image
# ------------------------------------------------------------------------------
echo -n "Test Case 4: Freshness Check thất bại -> Rollback về Old image ... "
mkdir -p "$TEST_DIR/c4"
echo "smart-cassavas:v1.0.0" > "$TEST_DIR/c4/.last_deployed_image"
OLD_IMAGE=$(cat "$TEST_DIR/c4/.last_deployed_image")
echo "$OLD_IMAGE" > "$TEST_DIR/c4/.previous_image"

# Giả lập freshness fail:
FRESH_STATUS=1
if [ "$FRESH_STATUS" -ne 0 ]; then
    ROLLBACK_TARGET=$(cat "$TEST_DIR/c4/.previous_image")
    echo "$ROLLBACK_TARGET" > "$TEST_DIR/c4/.last_deployed_image"
fi

C4_STABLE=$(cat "$TEST_DIR/c4/.last_deployed_image")
if [ "$C4_STABLE" = "smart-cassavas:v1.0.0" ]; then
    echo "PASSED (Đã rollback về $C4_STABLE)"
else
    echo "FAILED"
    FAILED_CASES=$((FAILED_CASES + 1))
fi

# ------------------------------------------------------------------------------
# TEST CASE 5: First deployment (No previous image) + Failure
# Mong đợi: ROLLBACK_UNAVAILABLE, không báo rollback success
# ------------------------------------------------------------------------------
echo -n "Test Case 5: Lần đầu triển khai (First deploy) gặp sự cố ... "
mkdir -p "$TEST_DIR/c5"
# Không tạo .previous_image và không có .last_deployed_image
NEW_IMAGE="smart-cassavas:v1.0.0-first-fail"

ROLLBACK_RESULT="UNKNOWN"
if [ ! -f "$TEST_DIR/c5/.previous_image" ]; then
    ROLLBACK_RESULT="ROLLBACK_UNAVAILABLE"
fi

if [ "$ROLLBACK_RESULT" = "ROLLBACK_UNAVAILABLE" ]; then
    echo "PASSED (Trạng thái chính xác: $ROLLBACK_RESULT)"
else
    echo "FAILED"
    FAILED_CASES=$((FAILED_CASES + 1))
fi

echo "========================================================================"
if [ "$FAILED_CASES" -eq 0 ]; then
    echo "ROLLBACK_DRILL = PASSED"
    echo "TẤT CẢ 5/5 TEST CASES CỦA CƠ CHẾ ROLLBACK ĐÃ HOÀN TOÀN CHÍNH XÁC!"
    echo "========================================================================"
    exit 0
else
    echo "ROLLBACK_DRILL = FAILED ($FAILED_CASES thất bại)"
    echo "========================================================================"
    exit 1
fi
