<?php

namespace App\Http\Controllers\Web;

use App\Actions\IssueGuestTicket;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class GuestTicketController extends Controller
{
    public function store(Request $request, Event $event, IssueGuestTicket $action): RedirectResponse
    {
        Gate::authorize('update', $event);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['required', 'email', 'max:255'],
            'attendee_type' => ['required', 'in:guest,alumni'],
        ]);
        $registration = $action->execute($event->id, $data['guest_name'], $data['guest_email'], $data['attendee_type']);

        return redirect()->route('organizer.events.guest-pass', [$event, $registration]);
    }

    public function show(Event $event, Registration $registration): Response
    {
        Gate::authorize('update', $event);
        $registration->load('ticket');
        abort_unless((int) $registration->event_id === (int) $event->id && $registration->user_id === null
            && in_array($registration->attendee_type, ['guest', 'alumni'], true)
            && $registration->status === Registration::STATUS_CONFIRMED && ! $event->isCancelled()
            && $registration->ticket?->isActive(), 404);
        $payload = $registration->ticket->getQrPayload();
        abort_unless(is_string($payload), 404);
        $writer = new Writer(new ImageRenderer(new RendererStyle(400), new SvgImageBackEnd));
        $qr = preg_replace('/<\?xml[^>]*\?>/', '', $writer->writeString($payload));

        return response()->view('organizer.guest-pass', compact('event', 'registration', 'qr'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }
}
