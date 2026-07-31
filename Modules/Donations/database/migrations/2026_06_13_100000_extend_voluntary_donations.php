<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_settings', function (Blueprint $table): void {
            $table->text('tax_acknowledgement_note')->nullable()->after('tax_registration_number');
        });

        Schema::table('donation_categories', function (Blueprint $table): void {
            $table->boolean('is_tax_deductible')->default(false)->after('description');
        });

        Schema::table('donors', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false)->after('donor_type');
        });

        Schema::table('donations', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false)->after('notes');
        });

        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->uuid('donor_id')->nullable()->after('family_id');
            $table->boolean('is_anonymous')->default(false)->after('source_type');
            $table->index(['tenant_id', 'donor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'donor_id']);
            $table->dropColumn(['donor_id', 'is_anonymous']);
        });

        Schema::table('donations', function (Blueprint $table): void {
            $table->dropColumn('is_anonymous');
        });

        Schema::table('donors', function (Blueprint $table): void {
            $table->dropColumn('is_anonymous');
        });

        Schema::table('donation_categories', function (Blueprint $table): void {
            $table->dropColumn('is_tax_deductible');
        });

        Schema::table('donation_settings', function (Blueprint $table): void {
            $table->dropColumn('tax_acknowledgement_note');
        });
    }
};
