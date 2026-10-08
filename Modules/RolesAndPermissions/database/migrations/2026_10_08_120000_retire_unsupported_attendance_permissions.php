<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attendance is not an implemented product module. Retire catalog permissions and
 * detach them from roles/users without deleting rows (audit/history safe).
 */
return new class extends Migration
{
    private const ATTENDANCE_PERMISSION_NAMES = [
        'attendance.view',
        'attendance.create',
        'attendance.edit',
        'attendance.delete',
    ];

    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where(function ($query) {
                $query->whereIn('name', self::ATTENDANCE_PERMISSION_NAMES)
                    ->orWhere('module', 'Attendance')
                    ->orWhere('category', 'attendance');
            })
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('permission_role')) {
            DB::table('permission_role')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('permission_user')) {
            DB::table('permission_user')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->update([
                'active' => 0,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name', self::ATTENDANCE_PERMISSION_NAMES)
            ->update([
                'active' => 1,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
    }
};
