<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_intention_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 64);
            $table->string('name', 128);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->uuid('mass_intention_category_id')->nullable()->after('beneficiary_name');
            $table->text('intention_description')->nullable()->after('intention_text');

            $table->foreign('mass_intention_category_id')
                ->references('id')
                ->on('mass_intention_categories')
                ->nullOnDelete();
        });

        $this->backfillCategoriesFromSettingsJson();
    }

    public function down(): void
    {
        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->dropForeign(['mass_intention_category_id']);
            $table->dropColumn(['mass_intention_category_id', 'intention_description']);
        });

        Schema::dropIfExists('mass_intention_categories');
    }

    private function backfillCategoriesFromSettingsJson(): void
    {
        if (! Schema::hasTable('mass_intention_settings')) {
            return;
        }

        $rows = DB::table('mass_intention_settings')->whereNotNull('categories')->get(['tenant_id', 'categories']);
        $now = now();

        foreach ($rows as $row) {
            $categories = json_decode($row->categories, true);
            if (! is_array($categories)) {
                continue;
            }
            $order = 0;
            foreach ($categories as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                $code = $this->uniqueCodeForTenant((int) $row->tenant_id, $this->slugCode($name));
                $exists = DB::table('mass_intention_categories')
                    ->where('tenant_id', $row->tenant_id)
                    ->where(function ($q) use ($name, $code) {
                        $q->where('code', $code)
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                    })
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('mass_intention_categories')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $row->tenant_id,
                    'code' => $code,
                    'name' => $name,
                    'active' => true,
                    'sort_order' => $order++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function slugCode(string $name): string
    {
        $slug = Str::upper(Str::slug($name, '_'));
        if ($slug === '') {
            $slug = 'CUSTOM';
        }

        return Str::limit($slug, 64, '');
    }

    private function uniqueCodeForTenant(int $tenantId, string $base): string
    {
        $code = $base;
        $suffix = 2;
        while (DB::table('mass_intention_categories')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            $code = Str::limit($base, 60, '').'_'.$suffix;
            $suffix++;
        }

        return $code;
    }
};
