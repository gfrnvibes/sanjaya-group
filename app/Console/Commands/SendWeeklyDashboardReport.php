<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDashboardReport;
use App\Models\Setting;
use App\Models\User;
use App\Services\SalesDashboardExporter;
use App\Services\SalesDashboardService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim laporan Dashboard Produk 7 hari terakhir (s.d. kemarin) ke email penerima.
 * Penerima: --to, atau pengaturan report_emails, atau semua staf konveksi aktif.
 */
#[Signature('report:weekly-dashboard {--to=* : Email penerima (bisa lebih dari satu)} {--days=7 : Jumlah hari periode}')]
#[Description('Kirim laporan mingguan Dashboard Produk (Excel terlampir)')]
class SendWeeklyDashboardReport extends Command
{
    public function handle(SalesDashboardService $dashboard, SalesDashboardExporter $exporter): int
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->warn('Tidak ada penerima. Isi pengaturan report_emails atau gunakan --to.');

            return self::FAILURE;
        }

        $params = ['days' => max(1, (int) $this->option('days')), 'ref_date' => today()->subDay()->toDateString()];
        $data = $dashboard->build($params);
        $path = $exporter->export($params, 'xlsx');

        try {
            Mail::to($recipients)->send(new WeeklyDashboardReport(
                $data,
                $path,
                'laporan-produk-'.$data['params']['end']->format('Ymd').'.xlsx',
            ));
        } finally {
            @unlink($path);
        }

        $this->info('Laporan terkirim ke: '.implode(', ', $recipients));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $emails = $this->option('to') ?: preg_split('/[\s,;]+/', (string) Setting::valueOf('report_emails', ''), -1, PREG_SPLIT_NO_EMPTY);

        if ($emails === []) {
            $emails = User::query()->whereNull('owner_id')->where('is_active', true)->pluck('email')->all();
        }

        return array_values(array_unique(array_filter($emails, fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
