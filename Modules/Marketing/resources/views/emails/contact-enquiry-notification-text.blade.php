EkklesiaNexus — {{ $productTagline ?? 'The Next Generation of Church Management System' }}

New contact enquiry received

Reference: {{ $enquiry->reference_number }}
Submitted: {{ $submittedAtLabel }}
Name: {{ $enquiry->full_name }}
Email: {{ $enquiry->email }}
@if ($enquiry->phone)
Phone: {{ $enquiry->phone }}
@endif
@if ($enquiry->organisation)
Organisation: {{ $enquiry->organisation }}
@endif
@if ($enquiry->subject)
Subject: {{ $enquiry->subject }}
@endif

Details
{{ $enquiry->message }}

@if (!empty($adminEnquiriesUrl))
View enquiries in the application (sign in required):
{{ $adminEnquiriesUrl }}
@endif

Reply to this email to respond to the visitor.
