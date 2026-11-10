<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Services\Tickets\TicketPdfGenerator;
use App\Services\Tickets\TicketSessionAccess;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicTicketController extends Controller
{
    public function show(Request $request, Registration $registration, TicketSessionAccess $ticketAccess): Response
    {
        $this->requireSecureRequest($request);

        $registration->load(['event.venue', 'ticketCategory', 'ticket', 'user']);
        abort_unless($registration->ticket && $ticketAccess->canView($request, $registration, $registration->ticket), 404);

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

    public function download(
        Request $request,
        Registration $registration,
        TicketSessionAccess $ticketAccess,
        TicketPdfGenerator $pdfGenerator,
    ): Response {
        $this->requireSecureRequest($request);

        $registration->load(['event.venue', 'ticketCategory', 'ticket', 'user']);
        abort_unless($registration->ticket
            && $ticketAccess->canDownloadPdf($request, $registration, $registration->ticket), 404);

        $filenameId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $registration->ticket->public_id) ?: 'ticket';
        $pdf = $pdfGenerator->generate($registration);

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Ingress-ticket-'.$filenameId.'.pdf"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    private function requireSecureRequest(Request $request): void
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_unless($request->secure(), 403, 'HTTPS is required.');
        }
    }
}
