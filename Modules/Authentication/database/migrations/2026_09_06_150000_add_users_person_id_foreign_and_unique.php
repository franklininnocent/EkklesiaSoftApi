<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'person_id')) {
                $table->uuid('person_id')->nullable()->after('tenant_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! $this->foreignKeyExists('users', 'users_person_id_foreign')) {
                $table->foreign('person_id')
                    ->references('id')
                    ->on('persons')
                    ->restrictOnDelete();
            }

            if (! $this->indexExists('users', 'users_tenant_person_unique')) {
                $table->unique(['tenant_id', 'person_id'], 'users_tenant_person_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'person_id')) {
                if ($this->indexExists('users', 'users_tenant_person_unique')) {
                    $table->dropUnique('users_tenant_person_unique');
                }
                if ($this->foreignKeyExists('users', 'users_person_id_foreign')) {
                    $table->dropForeign(['person_id']);
                }
            }
        });
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return (bool) $connection->selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ?',
                [$name]
            );
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA foreign_key_list({$table})");

            return collect($rows)->contains(fn ($row) => ($row->from ?? null) === 'person_id');
        }

        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$database, $table, $name]
        );
    }

    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return (bool) $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $name]
            );
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list({$table})");

            return collect($rows)->contains(fn ($row) => ($row->name ?? null) === $name);
        }

        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $table, $name]
        );
    }
};
