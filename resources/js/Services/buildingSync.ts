/**
 * Cross-Tab Realtime Synchronization Engine for Building, Floor & Apartment Management
 * 
 * Provides instantaneous 2-way cross-tab synchronization:
 * - Uses BroadcastChannel API for O(1) latency between duplicated browser tabs/windows
 * - Uses window 'storage' event as fallback for cross-window communications
 * - Automatically notifies all open tabs when an apartment status changes, or when CRUD occurs
 */

export type BuildingSyncAction =
  | 'APARTMENT_STATUS_UPDATED'
  | 'APARTMENT_CREATED'
  | 'APARTMENT_UPDATED'
  | 'APARTMENT_DELETED'
  | 'BATCH_APARTMENTS_GENERATED'
  | 'FLOOR_CREATED'
  | 'BUILDING_DATA_CHANGED';

export interface BuildingSyncEvent {
  id: string;
  type: BuildingSyncAction;
  apartmentId?: string;
  status?: string;
  oldStatus?: string;
  blockId?: string;
  floorId?: string;
  payload?: any;
  timestamp: number;
}

const BROADCAST_CHANNEL_NAME = 'smart_building_sync_channel';
const STORAGE_SYNC_KEY = 'smart_building_sync_event';

class BuildingSyncManager {
  private channel: BroadcastChannel | null = null;
  private listeners = new Set<(event: BuildingSyncEvent) => void>();
  private processedEventIds = new Set<string>();

  constructor() {
    if (typeof window !== 'undefined') {
      // 1. Initialize BroadcastChannel if supported
      if ('BroadcastChannel' in window) {
        try {
          this.channel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
          this.channel.onmessage = (event: MessageEvent<BuildingSyncEvent>) => {
            if (event?.data?.type) {
              this.handleIncomingEvent(event.data);
            }
          };
        } catch {
          this.channel = null;
        }
      }

      // 2. Storage event fallback for cross-tab communications
      window.addEventListener('storage', (e: StorageEvent) => {
        if (e.key === STORAGE_SYNC_KEY && e.newValue) {
          try {
            const eventData = JSON.parse(e.newValue) as BuildingSyncEvent;
            if (eventData?.type) {
              this.handleIncomingEvent(eventData);
            }
          } catch {
            // Ignore parse errors
          }
        }
      });
    }
  }

  private handleIncomingEvent(event: BuildingSyncEvent): void {
    if (!event.id || this.processedEventIds.has(event.id)) {
      return;
    }
    this.recordEventId(event.id);
    this.notifyListeners(event);
  }

  private recordEventId(id: string): void {
    this.processedEventIds.add(id);
    if (this.processedEventIds.size > 150) {
      const firstEntry = this.processedEventIds.values().next().value;
      if (firstEntry) {
        this.processedEventIds.delete(firstEntry);
      }
    }
  }

  /**
   * Broadcast an event to all other open tabs/windows
   */
  public broadcast(event: Omit<BuildingSyncEvent, 'id' | 'timestamp'>): void {
    const eventId = `${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
    const fullEvent: BuildingSyncEvent = {
      ...event,
      id: eventId,
      timestamp: Date.now(),
    };

    // Mark as processed locally on sender tab so it never re-processes its own event
    this.recordEventId(eventId);

    // 1. Post to BroadcastChannel (for other tabs)
    if (this.channel) {
      try {
        this.channel.postMessage(fullEvent);
      } catch {
        // Fallback
      }
    }

    // 2. Write to localStorage to trigger storage event in other tabs
    try {
      localStorage.setItem(STORAGE_SYNC_KEY, JSON.stringify(fullEvent));
    } catch {
      // Ignore quota/access errors
    }
  }

  /**
   * Subscribe a component or handler to cross-tab events
   */
  public subscribe(listener: (event: BuildingSyncEvent) => void): () => void {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }

  private notifyListeners(event: BuildingSyncEvent): void {
    this.listeners.forEach((listener) => {
      try {
        listener(event);
      } catch (err) {
        console.error('[BuildingSyncManager] Error in listener:', err);
      }
    });
  }
}

export const buildingSyncManager = new BuildingSyncManager();

