<?php

namespace Modules\Marketing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Services\ContactCaptchaService;
use Tests\TestCase;

class ContactCaptchaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_accepts_case_insensitive_answer_once(): void
    {
        $service = app(ContactCaptchaService::class);
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $service->seedForTesting($id, 'Ab3Dk');

        $this->assertTrue($service->verify($id, 'ab3dk'));
        $this->assertFalse($service->verify($id, 'ab3dk'));
    }

    public function test_expired_or_unknown_challenge_fails(): void
    {
        $service = app(ContactCaptchaService::class);
        $this->assertFalse($service->verify('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'AAAAA'));
        $this->assertFalse($service->verify('', 'AAAAA'));
        $this->assertFalse($service->verify('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ''));
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $service = app(ContactCaptchaService::class);
        $id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $service->seedForTesting($id, 'AB3DK', 1);
        $this->travel(2)->seconds();
        $this->assertFalse($service->verify($id, 'AB3DK'));
    }

    public function test_too_many_guesses_invalidate_challenge(): void
    {
        $service = app(ContactCaptchaService::class);
        $id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $service->seedForTesting($id, 'AB3DK');
        config(['marketing.web.captcha.max_attempts' => 3]);

        $this->assertFalse($service->verify($id, 'XXXXX'));
        $this->assertFalse($service->verify($id, 'YYYYY'));
        $this->assertFalse($service->verify($id, 'ZZZZZ'));
        $this->assertFalse($service->verify($id, 'AB3DK'));
    }
}
