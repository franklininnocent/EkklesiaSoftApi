<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_settings', function (Blueprint $table) {
            $table->id();
            $table->string('contact_recipient_email', 255);
            $table->boolean('contact_form_enabled')->default(true);
            $table->boolean('captcha_enabled')->default(true);
            $table->unsignedSmallInteger('captcha_ttl_seconds')->default(300);
            $table->unsignedInteger('enquiry_retention_days')->default(730);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('web_settings_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_number', 32)->unique();
            $table->string('full_name', 120);
            $table->string('email', 255);
            $table->string('phone', 40)->nullable();
            $table->string('organisation', 200)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->string('enquiry_status', 32)->default('unread');
            $table->string('delivery_status', 32)->default('received');
            $table->unsignedSmallInteger('delivery_attempts')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('last_delivery_error', 500)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('source_ip_hash', 64)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index('enquiry_status');
            $table->index('delivery_status');
            $table->index('email');
        });

        Schema::create('contact_enquiry_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('contact_enquiry_id');
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->index(['contact_enquiry_id', 'created_at']);
        });

        $exists = DB::table('web_settings')->exists();
        if (! $exists) {
            DB::table('web_settings')->insert([
                'contact_recipient_email' => 'contact@ekklesianexus.com',
                'contact_form_enabled' => true,
                'captcha_enabled' => true,
                'captcha_ttl_seconds' => 300,
                'enquiry_retention_days' => 730,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiry_delivery_events');
        Schema::dropIfExists('contact_enquiries');
        Schema::dropIfExists('web_settings_audit_logs');
        Schema::dropIfExists('web_settings');
    }
};
