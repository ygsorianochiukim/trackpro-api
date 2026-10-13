<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** With MEDIA_DISK=r2, uploads, URLs and clean-up all go through the R2 disk. */
class R2MediaTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'r2']);
        Storage::fake('public');
        Storage::fake('r2');
        $this->token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
    }

    public function test_upload_attach_and_cleanup_use_r2(): void
    {
        $product = Product::create([
            'slug' => 'test-unit', 'name' => 'Test Unit', 'model' => 'GPS Tracker',
            'category' => 'Vehicle', 'price' => 3000, 'subscription' => 300, 'tagline' => 'Tracker',
            'highlights' => [], 'featured' => false, 'stock' => 5, 'is_active' => true,
        ]);

        $video = $this->withToken($this->token)
            ->post('/api/admin/media', ['file' => UploadedFile::fake()->create('demo.mp4', 500, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'video')
            ->json('data');

        Storage::disk('r2')->assertExists($video['path']);
        Storage::disk('public')->assertMissing($video['path']);
        $this->assertSame(Storage::disk('r2')->url($video['path']), $video['url']);

        $this->withToken($this->token)->patchJson("/api/admin/products/{$product->id}", [
            'price' => 3000, 'subscription' => 300, 'stock' => 5, 'is_active' => true, 'featured' => false,
            'media' => [['type' => 'video', 'path' => $video['path']]],
        ])->assertOk();
        $this->getJson('/api/products/test-unit')->assertJsonPath('data.media.0.url', Storage::disk('r2')->url($video['path']));

        // Removing it deletes it from R2.
        $this->withToken($this->token)->patchJson("/api/admin/products/{$product->id}", [
            'price' => 3000, 'subscription' => 300, 'stock' => 5, 'is_active' => true, 'featured' => false, 'media' => [],
        ])->assertOk();
        Storage::disk('r2')->assertMissing($video['path']);
    }

    public function test_media_sync_copies_local_uploads_to_r2_once(): void
    {
        Storage::disk('public')->put('media/a.jpg', 'A');
        Storage::disk('public')->put('media/b.mp4', 'B');
        Storage::disk('r2')->put('media/b.mp4', 'B'); // already moved

        $this->artisan('media:sync')
            ->expectsOutputToContain('1 copied, 1 already there, 0 failed')
            ->assertSuccessful();

        $this->assertSame('A', Storage::disk('r2')->get('media/a.jpg'));
        Storage::disk('public')->assertExists('media/a.jpg'); // source is never deleted

        $this->artisan('media:sync --dry-run')->expectsOutputToContain('0 copied, 2 already there')->assertSuccessful();
    }

    public function test_media_sync_refuses_same_disk(): void
    {
        $this->artisan('media:sync --to=public')->assertFailed();
    }
}
