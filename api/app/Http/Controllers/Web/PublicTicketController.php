<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicTicketController extends Controller
{
    public function show(Request $request, Registration $registration): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_unless($request->secure(), 403, 'HTTPS is required.');
        }

        $authorizedTickets = $request->session()->get('public_ticket_access', []);
        $grantedVersion = is_array($authorizedTickets) ? ($authorizedTickets[(string) $registration->id] ?? null) : null;

        $registration->load(['event.venue', 'ticketCategory', 'ticket']);
        abort_unless((is_int($grantedVersion) || (is_string($grantedVersion) && ctype_digit($grantedVersion)))
            && (int) $grantedVersion === $registration->ticket?->credential_version
            && $registration->status === Registration::STATUS_CONFIRMED
            && $registration->ticket?->isActive(), 404);

        $payload = $registration->ticket->getQrPayload();
        abort_unless(is_string($payload), 404);

        $writer = new Writer(new ImageRenderer(new RendererStyle(400), new SvgImageBackEnd));
        $qr = preg_replace('/<\?xml[^>]*\?>/', '', $writer->writeString($payload));

        return response()->view('public.tickets.show', compact('registration', 'qr'))
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
            ]);
    }
}
