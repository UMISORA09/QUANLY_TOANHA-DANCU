/**
 * Smart Caching & Stale-While-Revalidate (SWR) Engine for Amenity Management
 * 
 * Features:
 * - Multi-tier: L1 Memory (0ms) + L2 Persistent Storage (localStorage for cross-tab sharing)
 * - Cross-Tab Synchronization: BroadcastChannel API + storage event fallback
 * - Stale-While-Revalidate (SWR) with HTTP ETag / 304 Not Modified
 * - In-flight Request Deduplication (prevents duplicate simultaneous requests)
 * - Deterministic, context-scoped Cache Keys (building, user, filter, page)
 * - Granular Invalidation after CRUD operations with cross-tab broadcast
 */

export interface CacheEntry<T> {
  data: T;
  etag?: string | null;
  savedAt: number;
  freshUntil: number;
  expiresAt: number;
}

export interface CacheLookup<T> {
  data: T | null;
  exists: boolean;
  isFresh: boolean;
  isStale: boolean;
  etag?: string | null;
}

export type AmenitySyncAction =
  | 'AMENITY_CREATED'
  | 'AMENITY_UPDATED'
  | 'AMENITY_DELETED'
  | 'AMENITY_STATUS_CHANGED'
  | 'AMENITY_SLOT_UPDATED'
  | 'AMENITY_BLACKOUT_CHANGED'
  | 'AMENITY_BOOKING_CHANGED'
  | 'CATEGORY_CHANGED'
  | 'CACHE_INVALIDATED';

export interface AmenitySyncEvent {
  type: AmenitySyncAction;
  amenityId?: string;
  subId?: string;
  timestamp: number;
}

const DEFAULT_FRESH_MS = 60 * 1000; // 1 phút (Fresh)
const DEFAULT_STALE_MS = 15 * 60 * 1000; // 15 phút (Stale)
const MAX_EXPIRY_MS = 24 * 60 * 60 * 1000; // 24 giờ (Hết hạn hoàn toàn)

const STORAGE_PREFIX = 'smart_amenity_cache:';
const BROADCAST_CHANNEL_NAME = 'smart_amenity_channel';
const STORAGE_SYNC_KEY = 'smart_amenity_sync_event';

class AmenityCacheManager {
  // L1 In-Memory Cache for 0ms access within the current window lifecycle
  private memoryCache = new Map<string, CacheEntry<any>>();

  // In-flight promise tracker for request deduplication
  private inFlightRequests = new Map<string, Promise<any>>();

  // Cross-tab broadcast channel
  private channel: BroadcastChannel | null = null;

  // Registered UI subscribers (e.g. React components)
  private listeners = new Set<(event: AmenitySyncEvent) => void>();

  constructor() {
    if (typeof window !== 'undefined') {
      // 1. Initialize BroadcastChannel API if supported
      if ('BroadcastChannel' in window) {
        try {
          this.channel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
          this.channel.onmessage = (event: MessageEvent<AmenitySyncEvent>) => {
            if (event?.data?.type) {
              this.handleRemoteEvent(event.data);
            }
          };
        } catch {
          this.channel = null;
        }
      }

      // 2. Storage event fallback for cross-tab communication
      window.addEventListener('storage', (e: StorageEvent) => {
        if (e.key === STORAGE_SYNC_KEY && e.newValue) {
          try {
            const eventData = JSON.parse(e.newValue) as AmenitySyncEvent;
            if (eventData?.type) {
              this.handleRemoteEvent(eventData);
            }
          } catch {
            // Ignore parse errors
          }
        }
      });
    }
  }

  /**
   * Handle sync events originating from other tabs
   */
  private handleRemoteEvent(event: AmenitySyncEvent): void {
    // Invalidate local in-memory and persistent cache appropriately
    if (event.type === 'AMENITY_DELETED' && event.amenityId) {
      this.invalidateAmenity(event.amenityId);
    } else if (event.type === 'AMENITY_SLOT_UPDATED' && event.amenityId) {
      this.invalidateSlots(event.amenityId);
      this.invalidateAmenities();
    } else if (event.type === 'AMENITY_BLACKOUT_CHANGED' && event.amenityId) {
      this.invalidateBlackouts(event.amenityId);
    } else if (event.type === 'AMENITY_BOOKING_CHANGED' && event.amenityId) {
      this.invalidateBookings(event.amenityId);
      this.invalidateAmenities();
    } else if (event.type === 'CATEGORY_CHANGED') {
      this.delete('amenities:categories');
      this.invalidateAmenities();
    } else if (event.type.startsWith('AMENITY_') || event.type === 'CACHE_INVALIDATED') {
      this.invalidateAmenities();
      if (event.amenityId) {
        this.invalidateDetail(event.amenityId);
      }
    }

    // Notify all active React subscribers in this tab (without re-broadcasting, preventing loops)
    this.listeners.forEach((listener) => {
      try {
        listener(event);
      } catch (err) {
        console.error('[AmenityCache] Error in cross-tab sync listener:', err);
      }
    });
  }

  /**
   * Subscribe to cross-tab cache mutation events
   */
  public subscribe(listener: (event: AmenitySyncEvent) => void): () => void {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }

  /**
   * Broadcast a mutation event to all other open tabs
   */
  public broadcastMutation(action: AmenitySyncAction, amenityId?: string, subId?: string): void {
    const event: AmenitySyncEvent = {
      type: action,
      amenityId,
      subId,
      timestamp: Date.now(),
    };

    // 1. Post to BroadcastChannel
    if (this.channel) {
      try {
        this.channel.postMessage(event);
      } catch {
        // Ignore channel error
      }
    }

    // 2. Trigger storage event for tabs/browsers without BroadcastChannel
    try {
      localStorage.setItem(STORAGE_SYNC_KEY, JSON.stringify(event));
    } catch {
      // Ignore storage errors
    }
  }

  /**
   * Deterministically build a normalized cache key for Amenity list
   */
  public buildAmenityListKey(params: {
    search?: string;
    category_id?: string;
    block_id?: string;
    is_active?: boolean;
    page?: number;
    limit?: number;
    sort?: string;
  } = {}, userId: string = 'anon'): string {
    const s = (params.search || '').trim().toLowerCase();
    const cat = params.category_id || 'all';
    const blk = params.block_id || 'all';
    const act = params.is_active !== undefined ? String(params.is_active) : 'all';
    const p = params.page || 1;
    const lim = params.limit || 10;
    const sort = params.sort || 'created_at_desc';

    return `amenities:list:u_${userId}:b_${blk}:cat_${cat}:act_${act}:s_${s}:srt_${sort}:p_${p}:l_${lim}`;
  }

  /**
   * Retrieve cached data with freshness inspection
   */
  public get<T>(key: string): CacheLookup<T> {
    const now = Date.now();

    // 1. Check L1 Memory Cache
    let entry = this.memoryCache.get(key) as CacheEntry<T> | undefined;

    // 2. Check L2 Persistent Storage (localStorage shared across tabs)
    if (!entry) {
      try {
        const raw = localStorage.getItem(STORAGE_PREFIX + key);
        if (raw) {
          entry = JSON.parse(raw) as CacheEntry<T>;
          // Hydrate back to L1
          if (entry && now < entry.expiresAt) {
            this.memoryCache.set(key, entry);
          }
        }
      } catch {
        // Storage unavailable or parse error
      }
    }

    if (!entry) {
      return { data: null, exists: false, isFresh: false, isStale: false };
    }

    // Expired completely?
    if (now > entry.expiresAt) {
      this.delete(key);
      return { data: null, exists: false, isFresh: false, isStale: false };
    }

    const isFresh = now <= entry.freshUntil;
    const isStale = now > entry.freshUntil && now <= entry.expiresAt;

    return {
      data: entry.data,
      exists: true,
      isFresh,
      isStale,
      etag: entry.etag,
    };
  }

  /**
   * Store data in both L1 Memory and L2 Storage (localStorage)
   */
  public set<T>(
    key: string,
    data: T,
    etag?: string | null,
    freshMs: number = DEFAULT_FRESH_MS,
    staleMs: number = DEFAULT_STALE_MS
  ): void {
    const now = Date.now();
    const entry: CacheEntry<T> = {
      data,
      etag: etag || null,
      savedAt: now,
      freshUntil: now + freshMs,
      expiresAt: now + staleMs,
    };

    // Store in L1
    this.memoryCache.set(key, entry);

    // Store in L2 localStorage for cross-tab persistence
    try {
      localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(entry));
    } catch {
      // Ignore quota exceeded errors
    }
  }

  /**
   * Touch/renew a cache entry when server responds with 304 Not Modified
   */
  public touch(key: string, freshMs: number = DEFAULT_FRESH_MS): void {
    const lookup = this.get<any>(key);
    if (lookup.exists && lookup.data !== null) {
      this.set(key, lookup.data, lookup.etag, freshMs);
    }
  }

  /**
   * Delete a specific cache key
   */
  public delete(key: string): void {
    this.memoryCache.delete(key);
    try {
      localStorage.removeItem(STORAGE_PREFIX + key);
    } catch {
      // Ignore
    }
  }

  /**
   * In-flight Request Deduplication:
   * If an identical fetch operation is already executing, reuse the same Promise.
   */
  public async dedupe<T>(key: string, fetcher: () => Promise<T>): Promise<T> {
    if (this.inFlightRequests.has(key)) {
      return this.inFlightRequests.get(key) as Promise<T>;
    }

    const promise = fetcher().finally(() => {
      this.inFlightRequests.delete(key);
    });

    this.inFlightRequests.set(key, promise);
    return promise;
  }

  /**
   * Invalidate all keys matching a substring or RegExp
   */
  public invalidatePattern(pattern: string | RegExp): void {
    const matches = (key: string) =>
      typeof pattern === 'string' ? key.includes(pattern) : pattern.test(key);

    // Clean L1
    for (const key of Array.from(this.memoryCache.keys())) {
      if (matches(key)) {
        this.memoryCache.delete(key);
      }
    }

    // Clean L2 (localStorage)
    try {
      const keysToRemove: string[] = [];
      for (let i = 0; i < localStorage.length; i++) {
        const fullKey = localStorage.key(i);
        if (fullKey && fullKey.startsWith(STORAGE_PREFIX)) {
          const stripped = fullKey.substring(STORAGE_PREFIX.length);
          if (matches(stripped)) {
            keysToRemove.push(fullKey);
          }
        }
      }
      for (const k of keysToRemove) {
        localStorage.removeItem(k);
      }
    } catch {
      // Ignore
    }
  }

  /**
   * Invalidate specific amenity detail cache
   */
  public invalidateDetail(amenityId: string): void {
    this.delete(`amenity:${amenityId}`);
    this.delete(`amenity:detail:${amenityId}`);
  }

  /**
   * Invalidate time slots cache for an amenity
   */
  public invalidateSlots(amenityId: string): void {
    this.delete(`amenity:${amenityId}:slots`);
    this.invalidateDetail(amenityId);
  }

  /**
   * Invalidate blackout dates cache for an amenity
   */
  public invalidateBlackouts(amenityId: string): void {
    this.delete(`amenity:${amenityId}:blackouts`);
  }

  /**
   * Invalidate bookings cache for an amenity
   */
  public invalidateBookings(amenityId: string): void {
    this.delete(`amenity:${amenityId}:bookings`);
    this.invalidateDetail(amenityId);
  }

  /**
   * Invalidate all Amenity listing caches (called after any CRUD / status change)
   */
  public invalidateAmenities(): void {
    this.invalidatePattern('amenities:list');
  }

  /**
   * Invalidate single amenity and all its related sub-resources completely
   */
  public invalidateAmenity(amenityId: string): void {
    this.invalidateDetail(amenityId);
    this.invalidateSlots(amenityId);
    this.invalidateBlackouts(amenityId);
    this.invalidateBookings(amenityId);
    this.invalidateAmenities();
    this.delete('amenities:categories');
  }

  /**
   * Invalidate Categories cache
   */
  public invalidateCategories(): void {
    this.delete('amenities:categories');
    this.invalidateAmenities();
  }

  /**
   * Invalidate Blocks cache
   */
  public invalidateBlocks(): void {
    this.delete('amenities:blocks');
  }

  /**
   * Clear all cache on logout or user switch
   */
  public clearAll(): void {
    this.memoryCache.clear();
    try {
      const keysToRemove: string[] = [];
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && k.startsWith(STORAGE_PREFIX)) {
          keysToRemove.push(k);
        }
      }
      for (const k of keysToRemove) {
        localStorage.removeItem(k);
      }
    } catch {
      // Ignore
    }
  }
}

export const amenityCache = new AmenityCacheManager();
export default amenityCache;
