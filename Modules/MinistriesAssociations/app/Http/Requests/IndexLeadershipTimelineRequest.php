<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\LeadershipTerm;

class IndexLeadershipTimelineRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'position_id' => ['sometimes', 'uuid', $this->tenantExists('ma_positions')],
            'status' => ['sometimes', 'string', Rule::in([
                LeadershipTerm::STATUS_ACTIVE,
                LeadershipTerm::STATUS_COMPLETED,
                LeadershipTerm::STATUS_VACATED,
                LeadershipTerm::STATUS_TERMINATED,
            ])],
        ]);
    }
}
