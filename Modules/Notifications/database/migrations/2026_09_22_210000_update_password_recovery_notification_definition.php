<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateDefinition(
            title: 'New Forgot Password Request',
            body: '{requester_name} ({requester_email}) · {requester_role}',
            channels: ['in_app' => true, 'email' => false, 'push' => false],
            strategy: 'explicit',
        );
    }

    public function down(): void
    {
        $this->updateDefinition(
            title: 'Password recovery needs approval',
            body: '{requester_name} requested a password reset.',
            channels: ['in_app' => true, 'email' => true, 'push' => false],
            strategy: 'permission:password.recovery.requests.process',
        );
    }

    /**
     * @param  array{in_app: bool, email: bool, push: bool}  $channels
     */
    private function updateDefinition(string $title, string $body, array $channels, string $strategy): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            return;
        }

        $payload = [
            'title_template' => $title,
            'body_template' => $body,
            'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
            'recipient_strategy' => $strategy,
            'updated_at' => now(),
        ];

        if (DB::getDriverName() === 'pgsql') {
            $payload['channels'] = DB::raw("'".$payload['channels']."'::jsonb");
        }

        DB::table('notification_definitions')
            ->where('code', 'auth.password_recovery.requested')
            ->update($payload);
    }
};
