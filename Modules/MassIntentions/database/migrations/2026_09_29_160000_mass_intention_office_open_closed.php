<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Support\MassIntentionCloseSource;
use Modules\MassIntentions\Support\MassIntentionStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_intention_requests', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('accepted_by_user_id');
            $table->unsignedBigInteger('closed_by_user_id')->nullable()->after('closed_at');
            $table->string('close_source', 32)->nullable()->after('closed_by_user_id');

            $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        $legacyOpen = [
            MassIntentionStatus::DRAFT,
            MassIntentionStatus::PENDING_REVIEW,
            MassIntentionStatus::AWAITING_CLARIFICATION,
            MassIntentionStatus::ACCEPTED,
        ];

        $rows = DB::table('mass_intention_requests')
            ->select(['id', 'tenant_id', 'status', 'requested_date'])
            ->whereIn('status', array_merge($legacyOpen, [MassIntentionStatus::WITHDRAWN]))
            ->get();

        foreach ($rows as $row) {
            $newStatus = MassIntentionStatus::CLOSED;
            $closeSource = MassIntentionCloseSource::AUTOMATIC;
            $closedAt = now();

            if (in_array($row->status, $legacyOpen, true)) {
                if ($row->requested_date !== null) {
                    try {
                        $today = DonationBusinessDate::today((int) $row->tenant_id);
                        if ($row->requested_date >= $today) {
                            $newStatus = MassIntentionStatus::OPEN;
                            $closeSource = null;
                            $closedAt = null;
                        }
                    } catch (\Throwable) {
                        $newStatus = MassIntentionStatus::OPEN;
                        $closeSource = null;
                        $closedAt = null;
                    }
                } else {
                    $newStatus = MassIntentionStatus::OPEN;
                    $closeSource = null;
                    $closedAt = null;
                }
            }

            DB::table('mass_intention_requests')
                ->where('id', $row->id)
                ->update([
                    'status' => $newStatus,
                    'closed_at' => $closedAt,
                    'close_source' => $closeSource,
                ]);
        }

        DB::table('mass_intention_requests')
            ->whereNotIn('status', [MassIntentionStatus::OPEN, MassIntentionStatus::CLOSED])
            ->update(['status' => MassIntentionStatus::CLOSED]);
    }

    public function down(): void
    {
        Schema::table('mass_intention_requests', function (Blueprint $table): void {
            $table->dropForeign(['closed_by_user_id']);
            $table->dropColumn(['closed_at', 'closed_by_user_id', 'close_source']);
        });
    }
};
