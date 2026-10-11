<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Authentication\Models\User;

class WebSetting extends Model
{
    protected $table = 'web_settings';

    protected $fillable = [
        'contact_recipient_email',
        'contact_form_enabled',
        'captcha_enabled',
        'captcha_ttl_seconds',
        'enquiry_retention_days',
        'updated_by',
    ];

    protected $casts = [
        'contact_form_enabled' => 'boolean',
        'captcha_enabled' => 'boolean',
        'captcha_ttl_seconds' => 'integer',
        'enquiry_retention_days' => 'integer',
    ];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
