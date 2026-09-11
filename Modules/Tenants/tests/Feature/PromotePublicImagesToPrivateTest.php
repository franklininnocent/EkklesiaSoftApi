<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantPrivateStorage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromotePublicImagesToPrivateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_promotes_referenced_public_user_profile_images_to_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $tenant = Tenant::factory()->active()->create();
        $publicKey = "tenants/{$tenant->id}/users/legacy.jpg";
        Storage::disk('public')->put($publicKey, $this->minimalJpegBytes());

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'profile_image_path' => $publicKey,
        ]);

        $exitCode = Artisan::call('media:promote-public-images-to-private');
        $this->assertSame(0, $exitCode);

        $newKey = DB::table('users')->where('id', $user->id)->value('profile_image_path');
        $this->assertNotSame($publicKey, $newKey);
        $this->assertIsString($newKey);
        $this->assertTrue(TenantPrivateStorage::exists($newKey));
        $this->assertStringContainsString("tenants/{$tenant->id}/users/", $newKey);
    }

    #[Test]
    public function dry_run_does_not_mutate_database_or_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $tenant = Tenant::factory()->active()->create();
        $publicKey = "tenants/{$tenant->id}/users/legacy-dry.jpg";
        Storage::disk('public')->put($publicKey, $this->minimalJpegBytes());

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'profile_image_path' => $publicKey,
        ]);

        Artisan::call('media:promote-public-images-to-private', ['--dry-run' => true]);

        $this->assertSame($publicKey, DB::table('users')->where('id', $user->id)->value('profile_image_path'));
        $this->assertFalse(TenantPrivateStorage::exists($publicKey));
    }

    private function minimalJpegBytes(): string
    {
        $image = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
