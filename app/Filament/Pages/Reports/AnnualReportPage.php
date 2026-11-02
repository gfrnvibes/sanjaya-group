<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Actions\ResourceIo;
use App\Services\AnnualReport;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * LAPORAN TAHUNAN konveksi: ringkasan, laba rugi bulanan, laporan keuangan tahunan, analisis & rasio.
 */
class AnnualReportPage extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Laporan Tahunan';

    protected static ?string $title = 'Laporan Tahunan';

    protected static ?string $slug = 'laporan-keuangan/tahunan';

    protected string $view = 'filament.pages.annual-report';

    public ?int $year = null;

    /** @var array<string, mixed>|null */
    private ?array $annual = null;

    public function mount(): void
    {
        $this->year = today()->year;
    }

    public function updatedYear(): void
    {
        $this->annual = null;
    }

    public function getSubheading(): ?string
    {
        return 'Konveksi. Tahun berjalan dihitung sampai hari ini; dibandingkan dengan periode yang sama tahun lalu.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->compact()
                ->schema([
                    Select::make('year')
                        ->label('Tahun')
                        ->options(fn (): array => AnnualReport::years())
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function annual(): array
    {
        return $this->annual ??= app(AnnualReport::class)->build((int) ($this->year ?: today()->year));
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('print')
                    ->label('Cetak / PDF (lengkap dengan sampul)')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->action(fn () => ResourceIo::openPrint($this, view('print.financial-report', [
                        'reports' => [],
                        'documentTitle' => 'Laporan Tahunan '.$this->annual()['year'],
                        'printedBy' => auth()->user()?->name,
                        'prepend' => view('filament.reports.annual', ['annual' => $this->annual(), 'print' => true])->render(),
                    ])->render())),
                Action::make('excel')
                    ->label('Excel (.xlsx, 1 sheet per laporan)')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->action(fn (): BinaryFileResponse => response()
                        ->download(FinancialReportPage::excel([$this->monthlyReport(), ...$this->annual()['reports']]), 'laporan-tahunan-'.$this->annual()['year'].'.xlsx')
                        ->deleteFileAfterSend()),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * Laba rugi bulanan dalam format laporan untuk sheet Excel.
     *
     * @return array<string, mixed>
     */
    private function monthlyReport(): array
    {
        $monthly = $this->annual()['monthly'];

        return [
            'title' => 'Laba Rugi per Bulan '.$this->annual()['year'],
            'sheet' => 'Laba Rugi Bulanan',
            'entity' => 'Konveksi',
            'columns' => [...$monthly['months'], 'Total'],
            'rows' => array_map(fn (array $row): array => [
                'label' => $row['label'], 'indent' => 0, 'amounts' => [...$row['values'], $row['total']],
            ], $monthly['rows']),
        ];
    }
}
