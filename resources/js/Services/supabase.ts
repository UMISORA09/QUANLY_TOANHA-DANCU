import { createClient, SupabaseClient } from '@supabase/supabase-js';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL || '';
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY || '';

let supabaseInstance: SupabaseClient | null = null;

/**
 * Returns the singleton Supabase client instance.
 */
export function getSupabase(): SupabaseClient {
  if (!supabaseInstance) {
    if (!supabaseUrl || !supabaseAnonKey) {
      console.warn('Supabase credentials (VITE_SUPABASE_URL, VITE_SUPABASE_ANON_KEY) are not configured.');
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

export const supabase = getSupabase();

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
  return client.storage.from(bucket).getPublicUrl(path).data.publicUrl;
}
