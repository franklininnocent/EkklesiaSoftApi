<?php

namespace Modules\Marketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Marketing\Models\ContactEnquiry;

class ContactEnquiryFactory extends Factory
{
    protected $model = ContactEnquiry::class;

    public function definition(): array
    {
        return [
            'reference_number' => 'ENQ-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'full_name' => 'Jane Visitor',
            'email' => 'visitor@example.com',
            'phone' => '+91 94424 66036',
            'organisation' => 'St Mary Parish',
            'subject' => null,
            'message' => 'We would like to learn more about EkklesiaNexus.',
            'enquiry_status' => ContactEnquiry::STATUS_UNREAD,
            'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
        ];
    }
}
