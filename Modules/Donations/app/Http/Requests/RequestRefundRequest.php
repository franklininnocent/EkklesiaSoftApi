<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Donations\Support\FinancialAmount;

class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => FinancialAmount::required(),
            'refund_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
