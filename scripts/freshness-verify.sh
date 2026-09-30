#!/bin/bash
# ==============================================================================
# SMART CASSAVAS - Automated Post-Deployment Freshness Verification Script
# Xác thực độ tươi mới của dữ liệu quan sát và collector sau triển khai
# ==============================================================================

set -eo pipefail

FRESHNESS_URL="${1:-http://localhost:8000/api/monitoring/freshness}"
MAX_RETRIES="${2:-15}"
RETRY_INTERVAL="${3:-3}"
ENVIRONMENT="${4:-production}"

echo "========================================================================"
echo "BẮT ĐẦU XÁC MINH DATA FRESHNESS & OBSERVABILITY: $FRESHNESS_URL"
echo "Môi trường: $ENVIRONMENT"
echo "Thời gian: $(TZ='Asia/Ho_Chi_Minh' date +'%Y-%m-%dT%H:%M:%S+07:00')"
echo "Số lần thử tối đa: $MAX_RETRIES (Chu kỳ: ${RETRY_INTERVAL}s)"
echo "========================================================================"

for ((i=1; i<=MAX_RETRIES; i++)); do
    echo -n "[Thử lần $i/$MAX_RETRIES] Kiểm tra $FRESHNESS_URL ... "
    
    RESPONSE=$(curl -s -w "\n%{http_code}" "$FRESHNESS_URL" 2>/dev/null || true)
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    BODY=$(echo "$RESPONSE" | sed '$d')

    if [ "$HTTP_CODE" = "200" ]; then
        # Kiểm tra trạng thái collector và overall
        OVERALL_STATUS=$(echo "$BODY" | grep -o '"overall_state":"[^"]*' | cut -d'"' -f4 || echo "UNKNOWN")
        COLLECTOR_STATUS=$(echo "$BODY" | grep -o '"collector":{[^}]*' | grep -o '"status":"[^"]*' | cut -d'"' -f4 || echo "UNKNOWN")
        DB_STATUS=$(echo "$BODY" | grep -o '"database":{[^}]*' | grep -o '"status":"[^"]*' | cut -d'"' -f4 || echo "UNKNOWN")

        echo "HTTP 200 (Overall: $OVERALL_STATUS, Collector: $COLLECTOR_STATUS, DB: $DB_STATUS)"

        # Policy nghiệm thu Freshness thống nhất toàn hệ thống (config/freshness.php):
        # Production: FRESH, HEALTHY, WARNING -> ACCEPT; STALE, CRITICAL, UNAVAILABLE, UNKNOWN -> FAIL
        # Preview / Staging: FRESH, HEALTHY, WARNING, STALE -> ACCEPT; CRITICAL, UNAVAILABLE, UNKNOWN -> FAIL
        IS_ACCEPTABLE=false
        if [ "$ENVIRONMENT" = "production" ]; then
            case "$OVERALL_STATUS" in
                FRESH|HEALTHY|WARNING)
                    IS_ACCEPTABLE=true
                    ;;
            esac
        else
            case "$OVERALL_STATUS" in
                FRESH|HEALTHY|WARNING|STALE)
                    IS_ACCEPTABLE=true
                    ;;
            esac
        fi

        if [ "$IS_ACCEPTABLE" = "true" ]; then
            if [ "$COLLECTOR_STATUS" != "UNAVAILABLE" ]; then
                echo "========================================================================"
                echo "✅ XÁC MINH FRESHNESS OBSERVABILITY THÀNH CÔNG!"
                echo "Environment:      $ENVIRONMENT"
                echo "Overall State:    $OVERALL_STATUS"
                echo "Collector State:  $COLLECTOR_STATUS"
                echo "Database State:   $DB_STATUS"
                echo "Checked At:       $(echo "$BODY" | grep -o '"checked_at":"[^"]*' | cut -d'"' -f4 || echo "N/A")"
                echo "========================================================================"
                exit 0
            else
                echo "⚠️ Collector đang báo trạng thái UNAVAILABLE. Đang thử lại..."
            fi
        else
            echo "⚠️ Trạng thái overall_state '$OVERALL_STATUS' không đạt chuẩn nghiệm thu môi trường '$ENVIRONMENT'. Đang thử lại..."
        fi
    else
        echo "CHƯA SẴN SÀNG (HTTP Code: $HTTP_CODE)"
    fi

    sleep "$RETRY_INTERVAL"
done

echo "========================================================================"
echo "❌ LỖI: Xác minh Freshness Observability thất bại sau $MAX_RETRIES lần thử!"
echo "Chi tiết response cuối: $BODY"
echo "========================================================================"
exit 1
