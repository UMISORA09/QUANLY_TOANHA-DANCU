import { createClient, SupabaseClient } from '@supabase/supabase-js';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL || '';
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY || '';

let supabaseInstance: SupabaseClient | null = null;

/**
 * Returns the singleton Supabase client instance.
 *
 * Lazy-init để tránh crash toàn bộ bundle khi chưa cấu hình
 * VITE_SUPABASE_URL / VITE_SUPABASE_ANON_KEY. Backend ticket hiện tại
 * vẫn là nguồn duy nhất cho ảnh sự cố (local|s3 qua API riêng tư),
 * Supabase chỉ là scaffolding cho giai đoạn sau.
 */
export function getSupabase(): SupabaseClient | null {
  if (!supabaseInstance) {
    if (!supabaseUrl || !supabaseAnonKey) {
      console.warn('Supabase credentials (VITE_SUPABASE_URL, VITE_SUPABASE_ANON_KEY) are not configured. Supabase storage is disabled.');
      return null;
    }
    supabaseInstance = createClient(supabaseUrl, supabaseAnonKey, {
      auth: {
        persistSession: true,
        autoRefreshToken: true,
      },
    });
  }
  return supabaseInstance;
}

export function isSupabaseConfigured(): boolean {
  return Boolean(supabaseUrl && supabaseAnonKey);
}

export const supabase: SupabaseClient | null = supabaseUrl && supabaseAnonKey ? getSupabase() : null;

/**
 * Uploads a file to a designated Supabase Storage bucket.
 *
 * @param bucket - Name of the bucket (e.g., 'ticket-attachments' | 'amenity-images')
 * @param path - Destination path/filename inside the bucket
 * @param file - File or Blob object to upload
 */
export async function uploadToStorage(
  bucket: 'ticket-attachments' | 'amenity-images' | string,
  path: string,
  file: File | Blob,
  options?: { contentType?: string; upsert?: boolean }
): Promise<{ path: string; publicUrl: string }> {
  const client = getSupabase();
  if (!client) {
    throw new Error('Supabase storage is not configured (missing VITE_SUPABASE_URL / VITE_SUPABASE_ANON_KEY).');
  }
  const { data, error } = await client.storage.from(bucket).upload(path, file, {
    contentType: options?.contentType || (file instanceof File ? file.type : undefined),
    upsert: options?.upsert ?? false,
  });

  if (error) {
    throw new Error(`Upload to Supabase Storage failed: ${error.message}`);
  }

  const { data: urlData } = client.storage.from(bucket).getPublicUrl(data.path);
  return {
    path: data.path,
    publicUrl: urlData.publicUrl,
  };
}

/**
 * Gets the public URL of a file in Supabase Storage.
 */
export function getStoragePublicUrl(
  bucket: 'ticket-attachments' | 'amenity-images' | string,
  path: string
): string {
  const client = getSupabase();
  if (!client) {
    throw new Error('Supabase storage is not configured (missing VITE_SUPABASE_URL / VITE_SUPABASE_ANON_KEY).');
  }
  return client.storage.from(bucket).getPublicUrl(path).data.publicUrl;
}
