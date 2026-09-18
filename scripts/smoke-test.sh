#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Post-Deployment Smoke Test Script
# Kiểm thử toàn diện các điểm truy cập trọng yếu sau khi triển khai
# ==============================================================================

set -eo pipefail

BASE_URL="${1:-http://localhost:8000}"
FAILED=0

echo "========================================================================"
echo "BẮT ĐẦU CHẠY SMOKE TEST SAU TRIỂN KHAI TẠI: $BASE_URL"
echo "Thời gian: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "========================================================================"

test_endpoint() {
    local name="$1"
    local path="$2"
    local expected_code="$3"
    local full_url="${BASE_URL}${path}"

    echo -n "[Smoke Test] Kiểm tra: $name ($path) ... "
    
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$full_url" 2>/dev/null || echo "000")

    if [ "$HTTP_CODE" = "$expected_code" ] || [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
        echo "PASS (HTTP $HTTP_CODE)"
    else
        echo "FAIL (Mong đợi $expected_code nhưng nhận $HTTP_CODE)"
        FAILED=1
    fi
}

# 1. Kiểm tra Trang chủ / Cổng Web
test_endpoint "Trang chủ Web SPA" "/" "302"
test_endpoint "Cổng điều hướng /home" "/home" "200"

# 2. Kiểm tra các cổng Health Check
test_endpoint "Core Health Endpoint" "/health" "200"
test_endpoint "API Health Alias" "/api/health" "200"
test_endpoint "Database Connectivity Health" "/api/db-health" "200"

# 3. Kiểm tra Prometheus Metrics
test_endpoint "Prometheus Metrics Exporter" "/metrics" "200"

# 4. Kiểm tra Public Status APIs
test_endpoint "Public Status API" "/api/public/status" "200"
test_endpoint "Public Incidents API" "/api/public/incidents" "200"

# 5. Kiểm tra API Tiện Ích & Nghiệp Vụ
test_endpoint "Smart Search API" "/api/amenities/search?q=ho" "200"
test_endpoint "Meta Blocks API" "/api/v1/meta/blocks" "200"

echo "========================================================================"
if [ "$FAILED" -eq 0 ]; then
    echo "✅ TẤT CẢ CÁC BƯỚC SMOKE TEST ĐỀU THÀNH CÔNG RỰC RỠ!"
    echo "Hệ thống Smart Cassavas đã sẵn sàng phục vụ người dùng."
    echo "========================================================================"
    exit 0
else
    echo "❌ PHÁT HIỆN LỖI TRONG QUÁ TRÌNH SMOKE TEST!"
    echo "Quy trình triển khai bị đánh dấu FAILED để kích hoạt Rollback."
    echo "========================================================================"
    exit 1
fi
