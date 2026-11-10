<?php

namespace App\Services\Tickets;

use App\Models\Registration;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketSessionAccess
{
    public const PDF_HANDOFF_MINUTES = 15;

    public function grantGuest(Request $request, Registration $registration, Ticket $ticket): void
    {
        if ($registration->user_id !== null) {
            return;
        }

        $key = (string) $registration->getKey();
        $version = (int) $ticket->credential_version;

        $passGrants = $request->session()->get('public_ticket_access', []);
        $passGrants = is_array($passGrants) ? $passGrants : [];
        $passGrants[$key] = $version;
        $request->session()->put('public_ticket_access', array_slice($passGrants, -20, null, true));

        $now = now();
        $pdfGrants = $request->session()->get('public_ticket_pdf_access', []);
        $pdfGrants = is_array($pdfGrants) ? $pdfGrants : [];
        $pdfGrants = array_filter($pdfGrants, fn (mixed $grant): bool => is_array($grant)
            && is_numeric($grant['expires_at'] ?? null)
            && (int) $grant['expires_at'] > $now->timestamp);
        $pdfGrants[$key] = [
            'credential_version' => $version,
            'expires_at' => $now->copy()->addMinutes(self::PDF_HANDOFF_MINUTES)->timestamp,
        ];
        $request->session()->put('public_ticket_pdf_access', array_slice($pdfGrants, -20, null, true));
    }

    public function canView(Request $request, Registration $registration, Ticket $ticket): bool
    {
        if (! $this->isActiveRegistration($registration, $ticket)) {
            return false;
        }

        if ($this->isCurrentAccountOwner($registration)) {
            return true;
        }

        if ($registration->user_id !== null) {
            return false;
        }

        $grants = $request->session()->get('public_ticket_access', []);
        $version = is_array($grants) ? ($grants[(string) $registration->getKey()] ?? null) : null;

        return $this->versionMatches($version, $ticket);
    }

    public function canDownloadPdf(Request $request, Registration $registration, Ticket $ticket): bool
    {
        if (! $this->isActiveRegistration($registration, $ticket)) {
            return false;
        }

        if ($this->isCurrentAccountOwner($registration)) {
            return true;
        }

        if ($registration->user_id !== null) {
            return false;
        }

        $grants = $request->session()->get('public_ticket_pdf_access', []);
        $grant = is_array($grants) ? ($grants[(string) $registration->getKey()] ?? null) : null;

        return is_array($grant)
            && $this->versionMatches($grant['credential_version'] ?? null, $ticket)
            && is_numeric($grant['expires_at'] ?? null)
            && (int) $grant['expires_at'] > now()->timestamp;
    }

    private function isCurrentAccountOwner(Registration $registration): bool
    {
        $userId = Auth::guard('web')->id();

        return $userId !== null && (int) $registration->user_id === (int) $userId;
    }

    private function isActiveRegistration(Registration $registration, Ticket $ticket): bool
    {
        return $registration->status === Registration::STATUS_CONFIRMED
            && $ticket->isActive()
            && (int) $ticket->credential_version > 0;
    }

    private function versionMatches(mixed $version, Ticket $ticket): bool
    {
        return (is_int($version) || (is_string($version) && ctype_digit($version)))
            && (int) $version === (int) $ticket->credential_version;
    }
}
