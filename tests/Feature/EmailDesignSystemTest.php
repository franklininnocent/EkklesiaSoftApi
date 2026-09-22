<?php

namespace Tests\Feature;

use App\Mail\TransactionalNotificationMail;
use App\Support\Email\EmailSubject;
use Modules\Authentication\Mail\PasswordRecoveryCompletedMail;
use Modules\Authentication\Mail\PasswordRecoveryDailyLimitMail;
use Modules\Authentication\Mail\PasswordRecoveryOtpMail;
use Modules\Authentication\Mail\PasswordRecoveryRequestNotificationMail;
use Modules\Authentication\Mail\PasswordRecoveryTemporaryPasswordMail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailDesignSystemTest extends TestCase
{
    #[Test]
    public function temporary_password_mail_renders_html_and_text_with_branding(): void
    {
        $mail = new PasswordRecoveryTemporaryPasswordMail(
            recipientName: 'Jane Doe',
            temporaryPassword: 'TmpPass123!',
        );

        $html = $mail->render();
        $text = $this->renderText($mail);

        $this->assertStringContainsString('EkklesiaSoft', $html);
        $this->assertStringContainsString('Password Recovery', $html);
        $this->assertStringContainsString('TmpPass123!', $html);
        $this->assertStringContainsString('do not share this password', strtolower($html));
        $this->assertStringContainsString('TmpPass123!', $text);
        $this->assertStringNotContainsString('$2y$', $html);
        $this->assertStringNotContainsString('Bearer ', $html);
        $this->assertStringContainsString('role="presentation"', $html);
    }

    #[Test]
    public function approval_request_mail_escapes_user_content_and_keeps_details(): void
    {
        $mail = new PasswordRecoveryRequestNotificationMail(
            requesterName: 'John <script>alert(1)</script> Doe',
            requesterEmail: 'verylong.email.address.for.wrapping@example.parish.church',
            tenantName: 'St. Mary Parish',
            requesterRole: 'Secretary',
            requestedAt: '2026-09-22T10:00:00+00:00',
            requestId: 'req-abc-123',
        );

        $html = $mail->render();

        $this->assertStringContainsString('Password Recovery Request', $html);
        $this->assertStringContainsString('req-abc-123', $html);
        $this->assertStringContainsString('verylong.email.address.for.wrapping@example.parish.church', $html);
        $this->assertStringContainsString('St. Mary Parish', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function completed_and_daily_limit_mails_render(): void
    {
        $completed = new PasswordRecoveryCompletedMail(
            targetUserName: 'Alice',
            targetUserEmail: 'alice@example.com',
            tenantName: 'Parish A',
            completedAt: '2026-09-22T12:00:00+00:00',
        );
        $limit = new PasswordRecoveryDailyLimitMail(
            targetUserName: 'Bob',
            targetUserEmail: 'bob@example.com',
            tenantName: null,
            attemptCount: 3,
            detectedAt: '2026-09-22T13:00:00+00:00',
        );

        $this->assertStringContainsString('Password Reset Completed', $completed->render());
        $this->assertStringContainsString('Security Alert', $limit->render());
        $this->assertStringContainsString('Recovery attempts today', $limit->render());
    }

    #[Test]
    public function otp_mail_renders_code_but_not_challenge_id(): void
    {
        $challengeId = 'challenge-secret-should-not-appear-xyz';
        $mail = new PasswordRecoveryOtpMail(
            recipientEmail: 'user@example.com',
            otp: '123456',
            expiresInSeconds: 60,
            challengeId: $challengeId,
        );

        $html = $mail->render();
        $text = $this->renderText($mail);

        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('123456', $text);
        $this->assertStringNotContainsString($challengeId, $html);
        $this->assertStringNotContainsString($challengeId, $text);
    }

    #[Test]
    public function transactional_mail_supports_optional_cta_and_escapes_body(): void
    {
        $mail = new TransactionalNotificationMail(
            mailSubject: "Subject\r\nBcc: evil@example.com",
            heading: 'Heading',
            body: "Line one\n\n<script>x</script>",
            preheader: 'Preheader',
            ctaUrl: 'https://example.com/action',
            ctaLabel: 'Continue',
        );

        $this->assertSame('Subject Bcc: evil@example.com', $mail->envelope()->subject);

        $html = $mail->render();
        $this->assertStringContainsString('https://example.com/action', $html);
        $this->assertStringContainsString('Continue', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function email_subject_strips_crlf(): void
    {
        $this->assertSame('Hello world', EmailSubject::sanitize("Hello\r\nworld"));
        $this->assertSame('Safe subject', EmailSubject::sanitize("Safe subject\n"));
    }

    private function renderText(object $mail): string
    {
        $content = $mail->content();
        $this->assertNotNull($content->text);

        return view($content->text, $content->with)->render();
    }
}
