<x-mail::message>
# New demo request

**{{ $demo->name }}** ({{ $demo->role }}) from **{{ $demo->church_parish }}** requested a demo.

**Country:** {{ $demo->country }}

@if ($demo->diocese_organization)
**Diocese / organization:** {{ $demo->diocese_organization }}
@endif

**Email:** {{ $demo->email }}

@if ($demo->phone)
**Phone:** {{ $demo->phone }}
@endif

**Message**

{{ $demo->message }}

@if ($demo->correlation_id)
Reference: {{ $demo->correlation_id }}
@endif

</x-mail::message>
