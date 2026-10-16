<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MassIntentionRequest extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_requests';

    protected $fillable = [
        'tenant_id',
        'status',
        'beneficiary_person_id',
        'beneficiary_name',
        'beneficiary_bcc_id',
        'beneficiary_bcc_name',
        'beneficiary_place',
        'mass_intention_category_id',
        'intention_text',
        'intention_description',
        'priest_text',
        'notes',
        'announce_name',
        'requester_name',
        'requester_phone',
        'requested_date',
        'date_must_be_kept',
        'prohibit_transfer',
        'is_collective',
        'mass_count_requested',
        'mass_count_accepted',
        'offering_policy_snapshot',
        'duplicate_warning_acknowledged_at',
        'accepted_at',
        'accepted_by_user_id',
        'closed_at',
        'closed_by_user_id',
        'close_source',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'announce_name' => 'boolean',
            'date_must_be_kept' => 'boolean',
            'prohibit_transfer' => 'boolean',
            'is_collective' => 'boolean',
            'requested_date' => 'date',
            'offering_policy_snapshot' => 'array',
            'duplicate_warning_acknowledged_at' => 'datetime',
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(MassIntentionObligation::class, 'request_id');
    }

    public function offering(): HasOne
    {
        return $this->hasOne(MassIntentionOffering::class, 'request_id');
    }
}
