<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityGalleryUploadTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        Storage::fake('local');
        Storage::fake('public');
        config(['tickets.attachment_disk' => 'local']);
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
    }

    private function payload(): array
    {
        return ['category_id' => $this->categoryId, 'block_id' => $this->blockId, 'amenity_name' => 'Bộ sưu tập QA', 'amenity_code' => 'GALLERY-'.Str::random(8), 'location_detail' => 'Tầng 1', 'max_capacity_per_slot' => 5, 'hourly_rate' => 0, 'cover_image_url' => 'https://example.test/cover.jpg', 'gallery_images' => []];
    }

    public function test_gallery_upload_is_private_and_cover_remains_a_url(): void
    {
        $created = $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->image('photo.png')]])->assertCreated()->assertJsonPath('cover_image_url', 'https://example.test/cover.jpg');
        $url = $created->json('gallery_images.0');
        $this->assertStringStartsWith('/api/v1/admin/amenity-images/local/', $url);
        Storage::disk('local')->assertExists('amenity-gallery/'.basename($url));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->flushHeaders();
        $this->getJson($url)->assertUnauthorized();
        DB::table('residents')->where('user_id', $this->secondUser->id)->update(['is_active' => false]);
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->getJson($url)->assertForbidden();
    }

    public function test_resident_cannot_upload_amenity_images(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->image('photo.png')]])->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_images_and_limits_do_not_write_files(): void
    {
        $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->createWithContent('fake.png', '<script>alert(1)</script>')]])->assertUnprocessable();
        $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->image('large.png')->size(2049)]])->assertUnprocessable();
        $files = array_map(fn (int $index) => UploadedFile::fake()->image('photo'.$index.'.png')->size(1500), [1, 2, 3]);
        $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => $files])->assertUnprocessable();
        $this->post('/api/v1/admin/amenities', ['payload' => 'true', 'gallery_files' => []])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_database_failure_removes_only_new_files(): void
    {
        Storage::disk('local')->put('amenity-gallery/existing.png', 'existing');
        $payload = $this->payload();
        $payload['amenity_code'] = DB::table('amenities')->where('id', $this->amenityId)->value('amenity_code');
        $this->post('/api/v1/admin/amenities', ['payload' => json_encode($payload), 'gallery_files' => [UploadedFile::fake()->image('new.png')]])->assertUnprocessable();
        $this->assertSame(['amenity-gallery/existing.png'], Storage::disk('local')->allFiles());
    }

    public function test_private_gallery_cannot_be_copied_to_another_amenity(): void
    {
        $first = $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->image('photo.png')]])->assertCreated();
        $payload = $this->payload();
        $payload['gallery_images'] = $first->json('gallery_images');
        $this->postJson('/api/v1/admin/amenities', $payload)->assertUnprocessable()->assertJsonValidationErrors('gallery_images');
        Storage::disk('local')->assertExists('amenity-gallery/'.basename($payload['gallery_images'][0]));
    }

    public function test_multipart_update_honors_optimistic_lock_and_preserves_existing_files(): void
    {
        $created = $this->post('/api/v1/admin/amenities', ['payload' => json_encode($this->payload()), 'gallery_files' => [UploadedFile::fake()->image('photo.png')]])->assertCreated();
        $payload = $created->json();
        $id = $payload['id'];
        $payload['updated_at'] = '2000-01-01T00:00:00Z';
        $this->post('/api/v1/admin/amenities/'.$id, ['_method' => 'PUT', 'payload' => json_encode($payload), 'gallery_files' => [UploadedFile::fake()->image('new.png')]])->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $payload['updated_at'] = $created->json('updated_at');
        $this->post('/api/v1/admin/amenities/'.$id, ['_method' => 'PUT', 'payload' => json_encode($payload), 'gallery_files' => [UploadedFile::fake()->image('new.png')]])->assertOk()->assertJsonCount(2, 'gallery_images');
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }
}
