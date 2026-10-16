<?php

namespace Modules\Subscriptions\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UploadOfflineClaimEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'evidence' => ['required', 'file', 'max:5120'],
        ];
    }
}
