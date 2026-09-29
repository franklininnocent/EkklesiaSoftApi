<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageMediaServiceTest extends TestCase
{
    private ImageMediaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->service = new ImageMediaService(new ImageMediaPolicy);
    }

    #[Test]
    public function it_stores_webp_display_and_thumb_on_private_disk(): void
    {
        $result = $this->service->store(
            MediaSecurityFixtures::validJpeg(400, 300),
            42,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $this->assertStringStartsWith('tenants/42/users/', $result->storageKey);
        $this->assertStringEndsWith('.webp', $result->storageKey);
        $this->assertStringContainsString('-thumb.webp', $result->thumbStorageKey);
        Storage::disk('local')->assertExists($result->storageKey);
        Storage::disk('local')->assertExists($result->thumbStorageKey);
        $this->assertSame(64, strlen($result->checksum));
    }

    #[Test]
    public function it_rejects_text_files(): void
    {
        $this->expectException(ImageMediaException::class);

        $this->service->store(
            MediaSecurityFixtures::textPlain(),
            42,
            ImageMediaPolicy::CATEGORY_USERS
        );
    }

    #[Test]
    public function it_rejects_php_disguised_as_jpeg(): void
    {
        $this->expectException(ImageMediaException::class);

        $this->service->store(
            MediaSecurityFixtures::phpAsJpeg(),
            42,
            ImageMediaPolicy::CATEGORY_USERS
        );
    }

    #[Test]
    public function it_rejects_huge_dimensions(): void
    {
        $this->expectException(ImageMediaException::class);

        $this->service->store(
            MediaSecurityFixtures::hugeDimensions(),
            42,
            ImageMediaPolicy::CATEGORY_USERS
        );
    }

    #[Test]
    public function it_deletes_display_and_thumb_pairs(): void
    {
        $result = $this->service->store(
            MediaSecurityFixtures::validPng(),
            7,
            ImageMediaPolicy::CATEGORY_FAMILIES
        );

        $this->service->deletePair($result->storageKey, 7);

        Storage::disk('local')->assertMissing($result->storageKey);
        Storage::disk('local')->assertMissing($result->thumbStorageKey);
    }

    #[Test]
    public function replace_keeps_old_files_when_commit_fails(): void
    {
        $first = $this->service->store(
            MediaSecurityFixtures::validJpeg(),
            9,
            ImageMediaPolicy::CATEGORY_USERS
        );

        try {
            $this->service->replace(
                MediaSecurityFixtures::validPng(),
                9,
                ImageMediaPolicy::CATEGORY_USERS,
                $first->storageKey,
                static function (): void {
                    throw new \RuntimeException('commit failed');
                }
            );
        } catch (\RuntimeException) {
            // expected
        }

        Storage::disk('local')->assertExists($first->storageKey);
        Storage::disk('local')->assertExists($first->thumbStorageKey);
    }

    #[Test]
    public function replace_deletes_old_files_after_successful_commit(): void
    {
        $first = $this->service->store(
            MediaSecurityFixtures::validJpeg(),
            11,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $second = $this->service->replace(
            MediaSecurityFixtures::validPng(),
            11,
            ImageMediaPolicy::CATEGORY_USERS,
            $first->storageKey,
            static function (): void {
                // simulate successful commit
            }
        );

        Storage::disk('local')->assertMissing($first->storageKey);
        Storage::disk('local')->assertMissing($first->thumbStorageKey);
        Storage::disk('local')->assertExists($second->storageKey);
    }
}
