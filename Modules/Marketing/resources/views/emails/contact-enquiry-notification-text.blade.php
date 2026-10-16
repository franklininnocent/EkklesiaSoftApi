EkklesiaNexus website contact enquiry

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

Message
{{ $enquiry->message }}
