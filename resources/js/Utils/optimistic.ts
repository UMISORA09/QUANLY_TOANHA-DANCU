/**
 * Utilities for Optimistic UI updates
 * Allows client-side state to update immediately (<20ms perceived latency)
 * and safely roll back if the server mutation fails.
 */

export interface OptimisticOptions<T> {
    getCurrent: () => T;
    setOptimistic: (state: T) => void;
    transform: (prev: T) => T;
    mutate: () => Promise<unknown>;
    onError?: (error: unknown, previousState: T) => void;
    onSuccess?: (result: unknown) => void;
}

/**
 * Executes a state mutation with optimistic speculative update and automatic rollback.
 */
export async function executeOptimistic<T>(options: OptimisticOptions<T>): Promise<boolean> {
    const { getCurrent, setOptimistic, transform, mutate, onError, onSuccess } = options;
    const previous = getCurrent();

    try {
        const nextState = transform(previous);
        setOptimistic(nextState);

        const result = await mutate();
        if (onSuccess) {
            onSuccess(result);
        }
        return true;
    } catch (err) {
        // Rollback state on error
        setOptimistic(previous);
        if (onError) {
            onError(err, previous);
        }
        return false;
    }
}

/**
 * In-memory client cache with TTL and stale-while-revalidate support
 */
export class ClientMemoryCache<T> {
    private cache = new Map<string, { data: T; expiresAt: number; staleAt: number }>();

    constructor(private defaultTtlMs: number = 60_000, private staleGraceMs: number = 300_000) {}

    get(key: string): { data: T | null; isStale: boolean } {
        const entry = this.cache.get(key);
        if (!entry) {
            return { data: null, isStale: false };
        }

        const now = Date.now();
        if (now > entry.staleAt) {
            this.cache.delete(key);
            return { data: null, isStale: false };
        }

        return {
            data: entry.data,
            isStale: now > entry.expiresAt,
        };
    }

    set(key: string, data: T, ttlMs?: number): void {
        const ttl = ttlMs ?? this.defaultTtlMs;
        const now = Date.now();
        this.cache.set(key, {
            data,
            expiresAt: now + ttl,
            staleAt: now + ttl + this.staleGraceMs,
        });
    }

    invalidate(prefix?: string): void {
        if (!prefix) {
            this.cache.clear();
            return;
        }

        for (const key of this.cache.keys()) {
            if (key.startsWith(prefix)) {
                this.cache.delete(key);
            }
        }
    }
}
