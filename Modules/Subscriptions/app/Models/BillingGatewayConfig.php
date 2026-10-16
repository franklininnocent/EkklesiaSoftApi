<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class BillingGatewayConfig extends Model
{
    protected $table = 'billing_gateway_configs';

    protected $fillable = [
        'environment',
        'public_key_id',
        'api_secret_encrypted',
        'webhook_secret_encrypted',
        'webhook_secret_previous_encrypted',
        'enabled',
        'live_checkout_enabled',
        'last_validated_at',
        'last_validation_status',
    ];

    protected $hidden = [
        'api_secret_encrypted',
        'webhook_secret_encrypted',
        'webhook_secret_previous_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'live_checkout_enabled' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    protected function apiSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $this->decryptSecret($attributes['api_secret_encrypted'] ?? null),
            set: fn (?string $value) => ['api_secret_encrypted' => $value ? Crypt::encryptString($value) : null],
        );
    }

    protected function webhookSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $this->decryptSecret($attributes['webhook_secret_encrypted'] ?? null),
            set: fn (?string $value) => ['webhook_secret_encrypted' => $value ? Crypt::encryptString($value) : null],
        );
    }

    private function decryptSecret(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setApiSecretValue(?string $plain): void
    {
        $this->api_secret_encrypted = $plain ? Crypt::encryptString($plain) : null;
    }

    public function getApiSecretValue(): ?string
    {
        return $this->decryptSecret($this->api_secret_encrypted);
    }

    public function setWebhookSecretValue(?string $plain, bool $keepPrevious = true): void
    {
        if ($keepPrevious && $this->webhook_secret_encrypted) {
            $this->webhook_secret_previous_encrypted = $this->webhook_secret_encrypted;
        }
        $this->webhook_secret_encrypted = $plain ? Crypt::encryptString($plain) : null;
    }

    public function getWebhookSecretValue(): ?string
    {
        return $this->decryptSecret($this->webhook_secret_encrypted);
    }

    public function getWebhookSecretPreviousValue(): ?string
    {
        return $this->decryptSecret($this->webhook_secret_previous_encrypted);
    }
}
