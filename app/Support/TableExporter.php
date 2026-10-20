<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
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
            $writer->addRow(Row::fromValues(collect($columns)->map(fn (Column $column) => $this->value($column, $record))->all()));
            $count++;
        }

        if ($writer instanceof XlsxWriter && $count > 0) {
            $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $count + 1));
        }

        $writer->close();

        return $path;
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

        return $this->normalize($column->getState());
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
            is_numeric($state) => (float) $state,
            is_array($state), $state instanceof Collection => collect($state)->map(fn ($item) => $this->normalize($item))->implode(', '),
            $state instanceof Model => (string) ($state->getAttribute('name') ?? $state->getKey()),
            $state instanceof Htmlable => trim(strip_tags($state->toHtml())),
            default => (string) $state,
        };
    }
}
