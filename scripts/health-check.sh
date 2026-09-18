#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Health Check Script
# Xác thực ứng dụng, CSDL và hệ thống bộ nhớ đệm
# ==============================================================================

set -eo pipefail

HEALTH_URL="${1:-http://localhost:8000/health}"
MAX_RETRIES="${2:-20}"
RETRY_INTERVAL="${3:-3}"

echo "========================================================================"
echo "BẮT ĐẦU KIỂM TRA SỨC KHỎE HỆ THỐNG: $HEALTH_URL"
echo "Thời gian: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "Số lần thử tối đa: $MAX_RETRIES (Chu kỳ: ${RETRY_INTERVAL}s)"
echo "========================================================================"

for ((i=1; i<=MAX_RETRIES; i++)); do
    echo -n "[Thử lần $i/$MAX_RETRIES] Gọi $HEALTH_URL ... "
    
    RESPONSE=$(curl -s -w "\n%{http_code}" "$HEALTH_URL" 2>/dev/null || true)
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    BODY=$(echo "$RESPONSE" | sed '$d')

    if [ "$HTTP_CODE" = "200" ]; then
        if echo "$BODY" | grep -q '"status":"healthy"' || echo "$BODY" | grep -q '"status":"ok"'; then
            echo "THÀNH CÔNG (HTTP 200 - HEALTHY)"
            echo "Chi tiết: $BODY"
            echo "========================================================================"
            echo "Hệ thống Smart Cassavas đang hoạt động tối ưu."
            echo "========================================================================"
            exit 0
        else
            echo "CẢNH BÁO: Trả về HTTP 200 nhưng trạng thái không đạt: $BODY"
        fi
    else
        echo "CHƯA SẴN SÀNG (HTTP Code: $HTTP_CODE)"
    fi

    sleep "$RETRY_INTERVAL"
done

echo "========================================================================"
echo "LỖI: Health Check thất bại hoặc quá thời gian chờ sau $MAX_RETRIES lần thử!"
echo "========================================================================"
exit 1
