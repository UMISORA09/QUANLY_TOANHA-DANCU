import React, { useState, useEffect } from 'react';
import { Bell, BellOff, BellRing, CheckCircle2, AlertCircle, ShieldAlert, Loader2 } from 'lucide-react';
import { api } from '../Services/api';

/**
 * Chuyển chuỗi base64 URL-safe thành Uint8Array cho applicationServerKey
 */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

export const ResidentPushNotificationToggle: React.FC = () => {
  const [isSupported, setIsSupported] = useState<boolean>(true);
  const [permission, setPermission] = useState<NotificationPermission>('default');
  const [isSubscribed, setIsSubscribed] = useState<boolean>(false);
  const [activeDevices, setActiveDevices] = useState<number>(0);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [message, setMessage] = useState<{ type: 'success' | 'warning' | 'error' | 'info'; text: string } | null>(null);

  useEffect(() => {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator) || !('PushManager' in window)) {
      setIsSupported(false);
      return;
    }

    setPermission(Notification.permission);

    // Kiểm tra trạng thái đã đăng ký từ backend
    api.getPushNotificationStatus()
      .then((res) => {
        if (res.success) {
          setIsSubscribed(res.is_subscribed);
          setActiveDevices(res.active_devices || 0);
        }
      })
      .catch((err) => {
        console.warn('Không thể kiểm tra trạng thái push:', err);
      });
  }, []);

  const handleSubscribe = async () => {
    if (!isSupported) {
      setMessage({ type: 'warning', text: 'Trình duyệt hiện tại không hỗ trợ tính năng Web Push Notification.' });
      return;
    }

    setIsLoading(true);
    setMessage(null);

    try {
      // 1. Yêu cầu quyền thông báo theo cơ chế native
      const perm = await Notification.requestPermission();
      setPermission(perm);

      if (perm === 'denied') {
        setMessage({
          type: 'warning',
          text: 'Bạn đã từ chối nhận thông báo. Hãy mở Cài đặt trình duyệt > Quyền riêng tư > Thông báo để cho phép lại.',
        });
        setIsLoading(false);
        return;
      }

      if (perm !== 'granted') {
        setIsLoading(false);
        return;
      }

      // 2. Đăng ký Service Worker
      const reg = await navigator.serviceWorker.register('/sw.js');
      await navigator.serviceWorker.ready;

      // 3. Lấy VAPID Public Key từ backend
      const vapidRes = await api.getVapidPublicKey();
      const subscribeOptions: PushSubscriptionOptionsInit = {
        userVisibleOnly: true,
      };

      if (vapidRes.success && vapidRes.public_key) {
        subscribeOptions.applicationServerKey = urlBase64ToUint8Array(vapidRes.public_key) as unknown as BufferSource;
      }

      // 4. Khởi tạo Push Subscription từ PushManager
      let subscription = await reg.pushManager.getSubscription();
      if (!subscription) {
        subscription = await reg.pushManager.subscribe(subscribeOptions);
      }

      const jsonSub = subscription.toJSON();
      const endpoint = subscription.endpoint;
      const p256dh = jsonSub.keys?.p256dh || '';
      const auth = jsonSub.keys?.auth || '';

      // 5. Gửi subscription lên backend lưu trữ
      const res = await api.registerPushSubscription({
        endpoint,
        keys: { p256dh, auth },
        content_encoding: 'aes128gcm',
      });

      if (res.success) {
        setIsSubscribed(true);
        setActiveDevices((prev) => Math.max(1, prev + 1));
        setMessage({
          type: 'success',
          text: 'Đã kích hoạt Web Push thành công! Bạn sẽ nhận thông báo tức thì ngay khi Lễ tân tiếp nhận bưu phẩm.',
        });
      } else {
        setMessage({ type: 'error', text: res.message || 'Lỗi khi kích hoạt thông báo.' });
      }
    } catch (err: any) {
      console.error('Lỗi khi đăng ký Web Push:', err);
      setMessage({
        type: 'error',
        text: err?.message || 'Đã xảy ra lỗi trong quá trình kích hoạt Web Push Notification.',
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleUnsubscribe = async () => {
    setIsLoading(true);
    setMessage(null);

    try {
      const reg = await navigator.serviceWorker.ready;
      const subscription = await reg.pushManager.getSubscription();

      if (subscription) {
        await api.unregisterPushSubscription(subscription.endpoint);
        await subscription.unsubscribe();
      }

      setIsSubscribed(false);
      setActiveDevices((prev) => Math.max(0, prev - 1));
      setMessage({
        type: 'info',
        text: 'Đã tắt nhận thông báo Web Push trên thiết bị này.',
      });
    } catch (err: any) {
      console.error('Lỗi khi hủy Web Push:', err);
      setMessage({
        type: 'error',
        text: 'Không thể tắt thông báo: ' + (err?.message || 'Lỗi không xác định'),
      });
    } finally {
      setIsLoading(false);
    }
  };

  if (!isSupported) {
    return (
      <div className="p-4 rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-600 text-xs flex items-center gap-2">
        <ShieldAlert className="w-4 h-4 text-amber-500 shrink-0" />
        <span>Trình duyệt này không hỗ trợ Web Push Notification. Bạn vẫn nhận được thông báo qua Email.</span>
      </div>
    );
  }

  return (
    <div className="p-5 rounded-2xl border border-neutral-200 bg-white shadow-2xs space-y-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${
            isSubscribed ? 'bg-emerald-100 text-emerald-700' : 'bg-neutral-100 text-neutral-600'
          }`}>
            {isSubscribed ? <BellRing className="w-5 h-5 animate-pulse" /> : <BellOff className="w-5 h-5" />}
          </div>
          <div>
            <h4 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
              Thông báo Bưu phẩm qua Web Push
              {isSubscribed && (
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-2xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                  Đang Bật
                </span>
              )}
            </h4>
            <p className="text-xs text-neutral-500">
              Nhận thông báo nổi ngay trên màn hình khi Lễ tân tiếp nhận bưu kiện mới của căn hộ bạn.
            </p>
          </div>
        </div>

        <div>
          {isSubscribed ? (
            <button
              type="button"
              onClick={handleUnsubscribe}
              disabled={isLoading}
              className="px-3.5 py-1.5 rounded-xl border border-neutral-300 hover:border-neutral-400 text-neutral-700 text-xs font-semibold hover:bg-neutral-50 transition-colors disabled:opacity-50"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : 'Tắt thông báo'}
            </button>
          ) : (
            <button
              type="button"
              onClick={handleSubscribe}
              disabled={isLoading}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs hover:shadow transition-all disabled:opacity-50"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Bell className="w-3.5 h-3.5" />}
              <span>Bật nhận thông báo</span>
            </button>
          )}
        </div>
      </div>

      {message && (
        <div className={`p-3 rounded-xl text-xs flex items-start gap-2 ${
          message.type === 'success'
            ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
            : message.type === 'warning'
            ? 'bg-amber-50 border border-amber-200 text-amber-800'
            : message.type === 'error'
            ? 'bg-rose-50 border border-rose-200 text-rose-800'
            : 'bg-neutral-50 border border-neutral-200 text-neutral-700'
        }`}>
          {message.type === 'success' && <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />}
          {message.type === 'warning' && <AlertCircle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />}
          {message.type === 'error' && <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />}
          <span>{message.text}</span>
        </div>
      )}

      {permission === 'denied' && !message && (
        <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2">
          <AlertCircle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
          <span>
            Quyền nhận thông báo hiện đang bị chặn trên trình duyệt này. Vui lòng cho phép quyền thông báo trong biểu tượng ổ khóa bên cạnh thanh địa chỉ.
          </span>
        </div>
      )}
    </div>
  );
};
