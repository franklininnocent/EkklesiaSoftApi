<?php

namespace App\Console\Commands;

use App\Mail\TransactionalNotificationMail;
use App\Support\Email\EmailTheme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Authentication\Mail\PasswordRecoveryRequestNotificationMail;
use Modules\Authentication\Mail\PasswordRecoveryTemporaryPasswordMail;

class EmailPreviewCommand extends Command
{
    protected $signature = 'email:preview
                            {type=temporary-password : temporary-password|approval-request|generic}
                            {--open : Print the output path}';

    protected $description = 'Render a representative EkklesiaSoft email HTML preview (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('email:preview is only available in local and testing environments.');

            return self::FAILURE;
        }

        $type = (string) $this->argument('type');
        $mailable = match ($type) {
            'temporary-password' => new PasswordRecoveryTemporaryPasswordMail(
                recipientName: 'Jane Parish Secretary',
                temporaryPassword: 'Preview-Temp-Pass-Only',
            ),
            'approval-request' => new PasswordRecoveryRequestNotificationMail(
                requesterName: 'John <script>alert(1)</script> Doe',
                requesterEmail: 'john.verylong.email.address@example.parish.church',
                tenantName: 'St. Mary of the Annunciation Parish',
                requesterRole: 'Secretary',
                requestedAt: now()->toIso8601String(),
                requestId: 'preview-request-id-0001',
            ),
            'generic' => new TransactionalNotificationMail(
                mailSubject: 'EkklesiaSoft — Sample notification',
                heading: 'Sample notification',
                body: "Hello,\n\nThis is a sample EkklesiaSoft notification body used for layout preview.\n\n— ".EmailTheme::productName(),
                preheader: 'A sample notification for email layout preview.',
                ctaUrl: 'https://example.com/preview-only',
                ctaLabel: 'Open EkklesiaSoft',
            ),
            default => null,
        };

        if ($mailable === null) {
            $this->error('Unknown type. Use: temporary-password, approval-request, or generic.');

            return self::FAILURE;
        }

        $dir = storage_path('framework/email-previews');
        File::ensureDirectoryExists($dir);

        $path = $dir.'/'.$type.'.html';
        File::put($path, $mailable->render());

        $this->info('Wrote '.$path);
        if ($this->option('open')) {
            $this->line($path);
        }

        return self::SUCCESS;
    }
}
