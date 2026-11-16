import { useEffect } from 'react';

export function useNotificationDetailOpened(category: 'TICKET' | 'AMENITY_BOOKING' | 'AMENITY' | 'SUGGESTION', id: string | null | undefined, loadedDetail?: object | null) {
  useEffect(() => {
    if (id) window.dispatchEvent(new CustomEvent('NOTIFICATION_DETAIL_OPENED', { detail: { category, id } }));
  }, [category, id, loadedDetail]);
}
