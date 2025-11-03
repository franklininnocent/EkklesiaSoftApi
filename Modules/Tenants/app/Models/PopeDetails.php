<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PopeDetails Model
 * 
 * Global Pope information - not tenant-specific.
 * There is only one active Pope globally at any time.
 */
class PopeDetails extends Model
{
    use HasFactory;

    protected $table = 'pope_details';

    protected $fillable = [
        'pope_name',
        'pope_image_path',
        'pope_title',
        'pope_effective_from',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'pope_effective_from' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the current active pope details
     * Since there's only one global pope, we'll always get the first/latest record
     */
    public static function getCurrent(): ?self
    {
        return self::orderBy('updated_at', 'desc')->first();
    }
}


