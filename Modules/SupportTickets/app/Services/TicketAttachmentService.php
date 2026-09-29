<?php

namespace Modules\SupportTickets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketAttachment;
use Modules\SupportTickets\Support\TicketEventType;
use Modules\Tenants\Support\TenantPrivateStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentService
{
    public function __construct(
        private readonly TicketEventRecorder $events,
    ) {}

    public function upload(SupportTicket $ticket, User $actor, UploadedFile $file): SupportTicketAttachment
    {
        $maxPerTicket = (int) config('supporttickets.attachments.max_per_ticket', 10);
        if ($ticket->attachments()->count() >= $maxPerTicket) {
            throw new SupportTicketException('Maximum attachments reached for this ticket.', 422);
        }

        $maxKb = (int) config('supporttickets.attachments.max_size_kb', 10240);
        if ($file->getSize() > $maxKb * 1024) {
            throw new SupportTicketException('File exceeds the maximum allowed size.', 422);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('supporttickets.attachments.allowed_extensions', []);
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new SupportTicketException('File type is not allowed.', 422);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $allowedMimes = config('supporttickets.attachments.allowed_mimes', []);
        if (! in_array($mime, $allowedMimes, true)) {
            throw new SupportTicketException('File type is not allowed.', 422);
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $relativePath = TenantPrivateStorage::relativePath(
            (int) $ticket->tenant_id,
            'support-tickets/'.$ticket->id,
            $filename
        );

        $stored = TenantPrivateStorage::disk()->put($relativePath, $file->get());
        if (! $stored) {
            throw new SupportTicketException('Failed to store attachment.', 500);
        }

        $attachment = SupportTicketAttachment::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'uploaded_by_user_id' => $actor->id,
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $relativePath,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ]);

        $this->events->record($ticket, (int) $actor->id, TicketEventType::ATTACHMENT_UPLOADED);

        return $attachment;
    }

    public function streamDownload(SupportTicketAttachment $attachment, int $effectiveTenantId): StreamedResponse
    {
        if ((int) $attachment->tenant_id !== $effectiveTenantId) {
            throw new SupportTicketException('Attachment not found.', 404);
        }

        $disk = TenantPrivateStorage::disk();
        if (! $disk->exists($attachment->storage_path)) {
            throw new SupportTicketException('Attachment not found.', 404);
        }

        return response()->streamDownload(
            function () use ($disk, $attachment): void {
                echo $disk->get($attachment->storage_path);
            },
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function findForTicket(SupportTicket $ticket, int $attachmentId): SupportTicketAttachment
    {
        $attachment = SupportTicketAttachment::query()
            ->where('ticket_id', $ticket->id)
            ->whereKey($attachmentId)
            ->first();

        if (! $attachment) {
            throw new SupportTicketException('Attachment not found.', 404);
        }

        return $attachment;
    }
}
