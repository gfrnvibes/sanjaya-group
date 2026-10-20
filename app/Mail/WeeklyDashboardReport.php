<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Laporan mingguan Dashboard Produk: ringkasan di badan email + workbook lengkap terlampir.
 */
class WeeklyDashboardReport extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $dashboard  hasil SalesDashboardService::build()
     */
    public function __construct(public array $dashboard, public string $attachmentPath, public string $attachmentName) {}

    public function envelope(): Envelope
    {
        $p = $this->dashboard['params'];

        return new Envelope(
            subject: 'Laporan Mingguan Produk & Prioritas Produksi '.$p['start']->format('d/m').' – '.$p['end']->format('d/m/Y'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-dashboard-report');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->attachmentPath)->as($this->attachmentName)];
    }
}
