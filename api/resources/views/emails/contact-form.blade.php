<x-mail::message>
# New Contact Form Submission

**From:** {{ $data['first_name'] }} {{ $data['last_name'] }}
**Email:** {{ $data['email'] }}
@if(!empty($data['phone']))
**Phone:** {{ $data['phone'] }}
@endif
@if(!empty($data['company']))
**Company:** {{ $data['company'] }}
@endif
@if(!empty($data['source']))
**How did they hear about us:** {{ $data['source'] }}
@endif
**Newsletter:** {{ !empty($data['newsletter']) ? 'Yes' : 'No' }}

---

## Message

{{ $data['message'] }}

</x-mail::message>
