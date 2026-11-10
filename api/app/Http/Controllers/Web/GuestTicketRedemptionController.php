<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Tickets\GuestTicketTransfer;
use App\Services\Tickets\TicketSessionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GuestTicketRedemptionController extends Controller
{
    private const INVALID_CODE_MESSAGE = 'This code is invalid or the ticket cannot be transferred.';

    public function show(Request $request): Response
    {
        $this->requireSecureRequest($request);

        return response()->view('public.tickets.redeem')->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public function store(
        Request $request,
        GuestTicketTransfer $guestTicketTransfer,
        TicketSessionAccess $ticketAccess,
    ): Response {
        $this->requireSecureRequest($request);
        $redemptionCode = $request->input('redemption_code');

        if (! is_string($redemptionCode) || ! preg_match('/\A[a-fA-F0-9]{64}\z/', $redemptionCode)) {
            return $this->reject($request);
        }

        try {
            $result = $guestTicketTransfer->redeem(strtolower($redemptionCode));
        } catch (ValidationException) {
            return $this->reject($request);
        }

        $ticketAccess->grantGuest($request, $result['registration'], $result['ticket']);
        $ticketAccess->grantRedemptionCode($request, $result['registration'], $result['redemption_code']);
        $request->session()->regenerate();

        return response()->redirectToRoute('public.tickets.show', $result['registration'])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    private function reject(Request $request): Response
    {
        $request->session()->forget('_old_input');

        return response()->redirectToRoute('public.tickets.redeem')
            ->withErrors(['redemption_code' => self::INVALID_CODE_MESSAGE])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    private function requireSecureRequest(Request $request): void
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_unless($request->secure(), 403, 'HTTPS is required.');
        }
    }
}
