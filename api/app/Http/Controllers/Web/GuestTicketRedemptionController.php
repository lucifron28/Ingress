<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Tickets\GuestTicketTransfer;
use App\Services\Tickets\TicketSessionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
        $validator = Validator::make($request->only(['redemption_code', 'guest_name', 'guest_email']), [
            'redemption_code' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'guest_name' => ['required', 'string', 'max:255', 'not_regex:/\x00/'],
            'guest_email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            $request->session()->forget('_old_input');

            return response()->redirectToRoute('public.tickets.redeem')
                ->withErrors($validator)
                ->withHeaders([
                    'Cache-Control' => 'no-store, private',
                    'Referrer-Policy' => 'no-referrer',
                ]);
        }

        $validated = $validator->validated();

        try {
            $result = $guestTicketTransfer->redeem(
                strtolower($validated['redemption_code']),
                $validated['guest_name'],
                $validated['guest_email'],
                hash('sha256', $request->session()->getId()),
            );
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
