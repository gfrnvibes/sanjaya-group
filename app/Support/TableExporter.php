<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Export umum tabel Filament: kolom yang sedang tampil, data sesuai filter/pencarian/urutan tabel.
 */
class TableExporter
{
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'ods' => 'OpenDocument (.ods)'];

    public function export(HasTable $livewire, string $format, string $sheetName): string
    {
        $columns = array_values($livewire->getTable()->getVisibleColumns());
        $path = Spreadsheet::tempPath($format);

        $writer = match ($format) {
            'csv' => new CsvWriter,
            'ods' => new OdsWriter,
            default => new XlsxWriter,
        };
        $writer->openToFile($path);

        if ($writer instanceof AbstractWriterMultiSheets) {
            $writer->getCurrentSheet()->setName(Spreadsheet::sheetName($sheetName));

            foreach ($columns as $index => $column) {
                $writer->getCurrentSheet()->setColumnWidth(max(10, min(40, mb_strlen($this->label($column)) + 4)), $index + 1);
            }
        }

        $writer->addRow(Spreadsheet::headerRow(collect($columns)->mapWithKeys(fn (Column $column): array => [$this->label($column) => true])->all()));

        $count = 0;

        foreach ($livewire->getFilteredSortedTableQuery()->lazy(500) as $record) {
            $writer->addRow(SpreadsheetCells::row(collect($columns)->map(fn (Column $column) => $this->value($column, $record))->all(), csv: $format === 'csv'));
            $count++;
        }

        if ($writer instanceof XlsxWriter && $count > 0) {
            $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $count + 1));
        }

        $writer->close();

        return $path;
    }

    /**
     * Halaman cetak (HTML siap print / Simpan sebagai PDF): kolom yang tampil, sesuai filter/pencarian/urutan.
     */
    public function printHtml(HasTable $livewire, string $title): string
    {
        $table = $livewire->getTable();
        $columns = array_values($table->getVisibleColumns());
        $rows = [];

        foreach ($livewire->getFilteredSortedTableQuery()->lazy(500) as $record) {
            $rows[] = collect($columns)->map(fn (Column $column): array => $this->printCell($column, $record))->all();

            if (count($rows) >= self::PRINT_LIMIT) {
                break;
            }
        }

        $filters = collect($table->getFilters())
            ->flatMap(fn ($filter): array => $filter->getIndicators())
            ->map(fn ($indicator): string => strip_tags((string) $indicator->getLabel()))
            ->filter()
            ->values()
            ->all();

        return view('print.table', [
            'title' => $title,
            'headers' => collect($columns)->map(fn (Column $column): string => $this->label($column))->all(),
            'numeric' => collect($columns)->map(fn (Column $column): bool => $column instanceof TextColumn && ($column->isNumeric() || $column->isMoney()))->all(),
            'rows' => $rows,
            'filters' => $filters,
            'search' => method_exists($livewire, 'getTableSearch') ? $livewire->getTableSearch() : null,
            'truncated' => count($rows) >= self::PRINT_LIMIT,
            'limit' => self::PRINT_LIMIT,
            'printedBy' => auth()->user()?->name,
        ])->render();
    }

    /** Batas baris halaman cetak (gunakan Excel untuk data lebih besar). */
    public const PRINT_LIMIT = 3000;

    /**
     * @return array{0: string, 1: bool} [teks tampilan, rata kanan]
     */
    private function printCell(Column $column, Model $record): array
    {
        $value = $this->value($column, $record);

        if ($column instanceof TextColumn && $column->isMoney() && is_numeric($value)) {
            return ['Rp '.number_format((float) $value, 0, ',', '.'), true];
        }

        if (is_int($value) || is_float($value)) {
            return [number_format((float) $value, floor((float) $value) === (float) $value ? 0 : 2, ',', '.'), true];
        }

        return [(string) $value, false];
    }

    private function label(Column $column): string
    {
        $label = $column->getLabel();

        return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label)) ?: $column->getName();
    }

    /**
     * Nilai mentah sel (angka tetap angka agar bisa dihitung di Excel).
     */
    private function value(Column $column, Model $record): bool|float|int|string|null
    {
        $column->record($record);
        $column->clearCachedState();

        $state = $column->getState();

        if ($column instanceof TextColumn && ($column->isNumeric() || $column->isMoney()) && is_numeric($state)) {
            return (float) $state;
        }

        return $this->normalize($state);
    }

    private function normalize(mixed $state): bool|float|int|string|null
    {
        return match (true) {
            $state === null => null,
            $state instanceof HasLabel => (string) $state->getLabel(),
            $state instanceof BackedEnum => (string) $state->value,
            $state instanceof DateTimeInterface => $state->format($state->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            is_bool($state) => $state ? 'Ya' : 'Tidak',
            is_int($state), is_float($state) => $state,
            is_string($state) => $state,
            is_array($state), $state instanceof Collection => collect($state)->map(fn ($item) => $this->normalize($item))->implode(', '),
            $state instanceof Model => (string) ($state->getAttribute('name') ?? $state->getKey()),
            $state instanceof Htmlable => trim(strip_tags($state->toHtml())),
            default => (string) $state,
        };
    }
}
