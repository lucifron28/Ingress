<x-mail::message>
# Event manager invitation

You have been invited to manage **{{ $eventTitle }}** in Ingress. The assignment gives access to this event only.

<x-mail::button :url="$invitationUrl">
Review invitation
</x-mail::button>

The invitation expires in 48 hours. If you were not expecting it, you can ignore this email.

Thanks,<br />
{{ config('app.name') }}
</x-mail::message>
