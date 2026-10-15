/**
 * Service Worker cho Smart Apartment Management System (Cassavas Smart)
 * Xử lý Web Push Notification & Điều hướng khi tương tác
 */

self.addEventListener('push', function (event) {
    let payload = {
        title: 'Bạn có bưu phẩm mới',
        body: 'Lễ tân đã tiếp nhận bưu phẩm cho căn hộ của bạn. Vui lòng kiểm tra thông tin trong hệ thống.',
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        data: {
            url: '/cu-dan?tab=overview',
        },
    };

    if (event.data) {
        try {
            const json = event.data.json();
            payload.title = json.title || payload.title;
            payload.body = json.body || payload.body;
            if (json.icon) payload.icon = json.icon;
            if (json.badge) payload.badge = json.badge;
            if (json.data) payload.data = json.data;
        } catch (e) {
            payload.body = event.data.text() || payload.body;
        }
    }

    const options = {
        body: payload.body,
        icon: payload.icon,
        badge: payload.badge,
        data: payload.data,
        tag: 'parcel-notification-' + (payload.data.parcel_id || Date.now()),
        renotify: true,
        vibrate: [200, 100, 200],
    };

    event.waitUntil(
        self.registration.showNotification(payload.title, options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const targetUrl = (event.notification.data && event.notification.data.url) 
        ? event.notification.data.url 
        : '/cu-dan?tab=overview';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // Nếu đã có tab đang mở trang cư dân, focus tab đó
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url.includes('/cu-dan') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Nếu chưa có tab nào mở, mở tab mới đến URL tương ứng
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
