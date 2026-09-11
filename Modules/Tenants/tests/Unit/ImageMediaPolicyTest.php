<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageMediaPolicyTest extends TestCase
{
    private ImageMediaPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new ImageMediaPolicy;
    }

    #[Test]
    public function it_rejects_oversized_files_for_users_category(): void
    {
        $this->expectException(ImageMediaException::class);
        $this->expectExceptionMessage('maximum allowed size');

        $this->policy->assertPreUpload(
            MediaSecurityFixtures::oversized(4000),
            ImageMediaPolicy::CATEGORY_USERS
        );
    }

    #[Test]
    public function it_rejects_unsupported_extensions(): void
    {
        $this->expectException(ImageMediaException::class);

        $this->policy->assertPreUpload(
            MediaSecurityFixtures::textPlain(),
            ImageMediaPolicy::CATEGORY_USERS
        );
    }

    #[Test]
    public function it_rejects_huge_dimensions(): void
    {
        $this->expectException(ImageMediaException::class);

        $this->policy->assertDimensions(5000, 5000);
    }

    #[Test]
    public function it_allows_valid_pre_upload_checks(): void
    {
        $this->policy->assertPreUpload(
            MediaSecurityFixtures::validJpeg(),
            ImageMediaPolicy::CATEGORY_USERS
        );

        $this->policy->assertDimensions(200, 200);
        $this->assertSame(3 * 1024 * 1024, $this->policy->maxBytesForCategory(ImageMediaPolicy::CATEGORY_USERS));
        $this->assertSame(5 * 1024 * 1024, $this->policy->maxBytesForCategory(ImageMediaPolicy::CATEGORY_LOGOS));
    }
}
