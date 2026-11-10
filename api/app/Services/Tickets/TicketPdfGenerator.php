<?php

namespace App\Services\Tickets;

use App\Models\Registration;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class TicketPdfGenerator
{
    public function generate(Registration $registration): string
    {
        $payload = $registration->ticket?->getQrPayload();
        if (! is_string($payload)) {
            throw new HttpException(404, 'Ticket is unavailable.');
        }

        try {
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setChroot(resource_path('views/pdf'));
            $options->setTempDir(sys_get_temp_dir());
            $options->setDefaultFont('DejaVu Sans');
            $options->setIsFontSubsettingEnabled(true);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->renderHtml($registration, $payload), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } catch (Throwable) {
            Log::error('Ticket PDF generation failed.');

            abort(503, 'Ticket PDF is temporarily unavailable.');
        }
    }

    public function renderHtml(Registration $registration, string $payload): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(360), new SvgImageBackEnd));
        $qrSvg = preg_replace('/<\?xml[^>]*\?>/', '', $writer->writeString($payload));
        if (! is_string($qrSvg)) {
            throw new HttpException(503, 'Ticket PDF is temporarily unavailable.');
        }

        return View::make('pdf.ticket', [
            'registration' => $registration,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
            'simulatedPayment' => (int) ($registration->ticketCategory?->price_minor ?? 0) > 0,
        ])->render();
    }
}
