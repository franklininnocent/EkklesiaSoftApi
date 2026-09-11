<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportRequestType extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'sort_order',
        'active',
        'requires_bug_fields',
    ];

    protected $casts = [
        'active' => 'boolean',
        'requires_bug_fields' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(SupportCategory::class, 'request_type_id')
            ->whereNull('parent_id')
            ->where('active', true)
            ->orderBy('sort_order');
    }

    public function catalogCategories(): HasMany
    {
        return $this->hasMany(SupportCategory::class, 'request_type_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'request_type_id');
    }
}
