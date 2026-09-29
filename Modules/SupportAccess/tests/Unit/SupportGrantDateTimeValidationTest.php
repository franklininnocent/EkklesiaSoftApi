<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportGrantDateTimeValidationTest extends TestCase
{
    /**
     * Mirrors TenantSupportGrantController::store validation rules.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload): array
    {
        return Validator::make($payload, [
            'allowed_mode' => ['required', 'in:readonly,standard,emergency,any'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:2000'],
            'max_sessions' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ])->validate();
    }

    #[Test]
    public function valid_datetime_local_payload_passes(): void
    {
        $validated = $this->validate([
            'allowed_mode' => 'readonly',
            'starts_at' => '2026-09-10T10:00',
            'ends_at' => '2026-09-10T11:00',
        ]);

        $this->assertSame('2026-09-10T10:00', $validated['starts_at']);
        $this->assertSame('2026-09-10T11:00', $validated['ends_at']);
    }

    #[Test]
    public function missing_starts_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->validate([
            'allowed_mode' => 'readonly',
            'ends_at' => '2026-09-10T11:00',
        ]);
    }

    #[Test]
    public function missing_ends_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->validate([
            'allowed_mode' => 'readonly',
            'starts_at' => '2026-09-10T10:00',
        ]);
    }

    #[Test]
    public function malformed_datetime_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->validate([
            'allowed_mode' => 'readonly',
            'starts_at' => 'not-a-date',
            'ends_at' => '2026-09-10T11:00',
        ]);
    }

    #[Test]
    public function ends_before_starts_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->validate([
            'allowed_mode' => 'readonly',
            'starts_at' => '2026-09-10T10:00',
            'ends_at' => '2026-09-10T09:00',
        ]);
    }

    #[Test]
    public function equal_start_and_end_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->validate([
            'allowed_mode' => 'readonly',
            'starts_at' => '2026-09-10T10:00',
            'ends_at' => '2026-09-10T10:00',
        ]);
    }
}
