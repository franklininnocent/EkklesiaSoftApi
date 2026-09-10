<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\Tenants\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class BishopUpdateRequest extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'bishop_update_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'diocese_id',
        'target_bishop_id',
        'request_type',
        'proposed_bishop_data',
        'proposed_appointment_data',
        'supporting_information',
        'source_reference',
        'submission_notes',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewer_id',
        'reviewer_comments',
        'internal_reviewer_notes',
        'submitter_feedback',
        'reviewed_at',
        'applied_at',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'proposed_bishop_data' => 'array',
        'proposed_appointment_data' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'request_type' => BishopUpdateRequestType::class,
        'status' => BishopUpdateRequestStatus::class,
        'version' => 'integer',
    ];

    public function diocese(): BelongsTo
    {
        return $this->belongsTo(DioceseManagement::class, 'diocese_id');
    }

    public function targetBishop(): BelongsTo
    {
        return $this->belongsTo(BishopManagement::class, 'target_bishop_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isEditableBySubmitter(): bool
    {
        return in_array($this->status, [
            BishopUpdateRequestStatus::Draft,
            BishopUpdateRequestStatus::ChangesRequested,
        ], true);
    }

    public function isReviewable(): bool
    {
        return in_array($this->status, [
            BishopUpdateRequestStatus::Submitted,
            BishopUpdateRequestStatus::UnderReview,
        ], true);
    }
}
