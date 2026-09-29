<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MassIntentionCategory extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'mass_intention_categories';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'active',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MassIntentionRequest::class, 'mass_intention_category_id');
    }
}
