/**
 * Web Worker for Invoice Management (Chức năng 7)
 * Chạy trên luồng nền (background thread) riêng biệt:
 * 1. Định thời đồng bộ dữ liệu hóa đơn tự động (Background Sync Loop)
 * 2. Tính toán phân tích chỉ số tài chính (Financial Metrics Calculation)
 * 3. Giúp giao diện mượt mà tuyệt đối, không lag giật và không cần bấm nút load thủ công
 */

interface WorkerMessage {
    type: 'START_SYNC' | 'STOP_SYNC' | 'FORCE_SYNC' | 'CALCULATE_METRICS';
    payload?: any;
}

let syncTimer: any = null;
let currentInterval = 12000; // 12 giây

self.onmessage = (event: MessageEvent<WorkerMessage>) => {
    const { type, payload } = event.data;

    switch (type) {
        case 'START_SYNC': {
            if (payload?.interval && typeof payload.interval === 'number') {
                currentInterval = payload.interval;
            }
            if (syncTimer) {
                clearInterval(syncTimer);
            }
            syncTimer = setInterval(() => {
                self.postMessage({
                    type: 'SYNC_TICK',
                    timestamp: Date.now(),
                });
            }, currentInterval);

            self.postMessage({
                type: 'SYNC_STATUS',
                status: 'RUNNING',
                interval: currentInterval,
                message: 'Web Worker tự động đồng bộ hóa đơn đang chạy ngầm',
            });
            break;
        }

        case 'STOP_SYNC': {
            if (syncTimer) {
                clearInterval(syncTimer);
                syncTimer = null;
            }
            self.postMessage({
                type: 'SYNC_STATUS',
                status: 'STOPPED',
                message: 'Web Worker đã tạm dừng đồng bộ',
            });
            break;
        }

        case 'FORCE_SYNC': {
            self.postMessage({
                type: 'SYNC_TICK',
                timestamp: Date.now(),
                forced: true,
            });
            break;
        }

        case 'CALCULATE_METRICS': {
            const list = payload?.invoices || [];
            let totalAmount = 0;
            let paidAmount = 0;
            let remainingAmount = 0;
            const statusCounts: Record<string, number> = {
                ISSUED: 0,
                PAID: 0,
                OVERDUE: 0,
                CANCELLED: 0,
                PARTIALLY_PAID: 0,
            };

            for (let i = 0; i < list.length; i++) {
                const item = list[i];
                totalAmount += Number(item.total_amount || 0);
                paidAmount += Number(item.paid_amount || 0);
                remainingAmount += Number(item.remaining_balance || (Number(item.total_amount || 0) - Number(item.paid_amount || 0)));
                const st = item.status || 'UNKNOWN';
                statusCounts[st] = (statusCounts[st] || 0) + 1;
            }

            self.postMessage({
                type: 'METRICS_RESULT',
                data: {
                    totalAmount,
                    paidAmount,
                    remainingAmount,
                    statusCounts,
                    totalCount: list.length,
                    calculatedAt: Date.now(),
                },
            });
            break;
        }

        default:
            break;
    }
};

export {};
