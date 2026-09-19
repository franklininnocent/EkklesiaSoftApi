<?php

namespace Modules\Authentication\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Authentication\Models\PasswordRecoveryChallenge;
use Modules\Authentication\Models\PasswordRecoveryRequest;

class CleanupExpiredPasswordRecoveriesCommand extends Command
{
    protected $signature = 'auth:cleanup-password-recoveries {--batch=500}';

    protected $description = 'Expire stale password recovery requests and remove old OTP challenges';

    public function handle(): int
    {
        $retentionDays = (int) config('authentication.recovery.cleanup_retention_days', 7);
        $cutoff = Carbon::now()->subDays($retentionDays);
        $batch = max(100, (int) $this->option('batch'));
        $staleMinutes = (int) config('authentication.recovery.processing_stale_minutes', 5);
        $staleCutoff = now()->subMinutes($staleMinutes);

        $expired = PasswordRecoveryRequest::query()
            ->where('status', PasswordRecoveryRequest::STATUS_PENDING_APPROVAL)
            ->where('expires_at', '<', now())
            ->update([
                'status' => PasswordRecoveryRequest::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);

        $stuckProcessing = PasswordRecoveryRequest::query()
            ->where('status', PasswordRecoveryRequest::STATUS_PROCESSING)
            ->where('updated_at', '<', $staleCutoff)
            ->get();

        foreach ($stuckProcessing as $request) {
            if ($request->password_committed_at !== null) {
                $request->status = PasswordRecoveryRequest::STATUS_DELIVERY_FAILED;
                $request->failure_reason = 'MAIL_TRANSPORT';
                $request->failed_at = now();
            } else {
                $request->status = PasswordRecoveryRequest::STATUS_FAILED;
                $request->failure_reason = 'PROCESSING_STALE';
                $request->failed_at = now();
            }
            $request->save();
        }

        $deleted = 0;

        do {
            $ids = PasswordRecoveryChallenge::query()
                ->where(function ($query) use ($cutoff) {
                    $query->where('created_at', '<', $cutoff)
                        ->orWhere(function ($inner) {
                            $inner->whereIn('status', [
                                PasswordRecoveryChallenge::STATUS_CONSUMED,
                                PasswordRecoveryChallenge::STATUS_INVALIDATED,
                            ])->where('updated_at', '<', now()->subDay());
                        });
                })
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += PasswordRecoveryChallenge::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $this->info("Expired {$expired} pending recovery request(s).");
        $this->info('Reconciled '.$stuckProcessing->count().' stale processing request(s).');
        $this->info("Deleted {$deleted} legacy recovery challenge(s).");

        return self::SUCCESS;
    }
}
