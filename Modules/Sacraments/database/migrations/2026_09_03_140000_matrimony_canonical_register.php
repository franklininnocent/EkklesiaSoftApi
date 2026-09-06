<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canon 1121–1123 matrimony register: structured classifications, dispensations, annotations.
 * Witnesses remain sacrament_participants (role=witness). Certificates remain sacrament_certificates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->string('baptismal_status', 40)->nullable()->after('affiliation_diocese_country');
            $table->string('ecclesial_affiliation_code', 40)->nullable()->after('baptismal_status');
            $table->string('ecclesial_affiliation_label')->nullable()->after('ecclesial_affiliation_code');
            $table->string('canonical_delegation_status', 40)->nullable()->after('external_minister_role');
        });

        Schema::table('sacraments', function (Blueprint $table) {
            $table->string('marriage_canonical_classification', 40)->nullable()->after('marriage_groom_diocese_country');
        });

        Schema::create('sacrament_dispensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sacrament_id')->constrained('sacraments')->cascadeOnDelete();
            $table->string('dispensation_type', 40);
            $table->string('granting_authority')->nullable();
            $table->string('protocol_number', 80)->nullable();
            $table->date('date_granted')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'sacrament_id']);
        });

        Schema::create('sacrament_canonical_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sacrament_id')->constrained('sacraments')->cascadeOnDelete();
            $table->string('annotation_type', 40);
            $table->date('effective_date')->nullable();
            $table->string('granting_authority')->nullable();
            $table->string('protocol_number', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'sacrament_id']);
            $table->index(['tenant_id', 'annotation_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_baptismal_status_check
                CHECK (
                    baptismal_status IS NULL
                    OR baptismal_status IN ('baptized_catholic','baptized_non_catholic','unbaptized')
                )
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_delegation_status_check
                CHECK (
                    canonical_delegation_status IS NULL
                    OR canonical_delegation_status IN ('proper_pastor','delegated','other')
                )
            ");
            DB::statement("
                ALTER TABLE sacraments
                ADD CONSTRAINT sacraments_marriage_classification_check
                CHECK (
                    marriage_canonical_classification IS NULL
                    OR marriage_canonical_classification IN (
                        'both_catholic','mixed_marriage','disparity_of_cult','other'
                    )
                )
            ");
            DB::statement("
                ALTER TABLE sacrament_dispensations
                ADD CONSTRAINT sacrament_dispensations_type_check
                CHECK (dispensation_type IN (
                    'mixed_marriage_permission','disparity_of_cult','other'
                ))
            ");
            DB::statement("
                ALTER TABLE sacrament_canonical_annotations
                ADD CONSTRAINT sacrament_canonical_annotations_type_check
                CHECK (annotation_type IN (
                    'baptismal_register_notation','convalidation',
                    'declaration_of_nullity','legitimate_dissolution'
                ))
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_canonical_annotations DROP CONSTRAINT IF EXISTS sacrament_canonical_annotations_type_check');
            DB::statement('ALTER TABLE sacrament_dispensations DROP CONSTRAINT IF EXISTS sacrament_dispensations_type_check');
            DB::statement('ALTER TABLE sacraments DROP CONSTRAINT IF EXISTS sacraments_marriage_classification_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_delegation_status_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_baptismal_status_check');
        }

        Schema::dropIfExists('sacrament_canonical_annotations');
        Schema::dropIfExists('sacrament_dispensations');

        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropColumn('marriage_canonical_classification');
        });

        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->dropColumn([
                'baptismal_status',
                'ecclesial_affiliation_code',
                'ecclesial_affiliation_label',
                'canonical_delegation_status',
            ]);
        });
    }
};
