<?php

namespace App\Filament\Support;

use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notifikasi ringkasan hasil impor (CatalogImporter / RowImporter):
 * jumlah baru / diperbarui / dilewati per data, daftar "perlu dicek", dan daftar error per baris.
 */
class ImportResultNotification
{
    private const MAX_LINES = 15;

    /**
     * @param  array{stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings?: list<string>}  $result
     */
    public static function send(string $subject, array $result): void
    {
        $summary = collect($result['stats'])
            ->map(fn (array $counts, string $label): string => $label.': '.collect([
                'baru' => $counts['created'],
                'diperbarui' => $counts['updated'],
                'tidak berubah / sudah ada' => $counts['skipped'],
            ])->filter()->map(fn (int $count, string $kind): string => "{$count} {$kind}")->implode(', '))
            ->filter(fn (string $line): bool => ! str_ends_with($line, ': '))
            ->values();

        $saved = collect($result['stats'])->sum(fn (array $counts): int => $counts['created'] + $counts['updated']);
        $body = $summary->isEmpty() ? collect(['Tidak ada data yang berubah.']) : $summary->map(fn (string $line): string => e($line));
        $warnings = collect($result['warnings'] ?? []);
        $errors = collect($result['errors']);

        self::section($body, $warnings, 'Dibuat otomatis, perlu dicek ('.$warnings->count().'):');
        self::section($body, $errors, $errors->count().' error:');

        $notification = Notification::make()
            ->title(match (true) {
                $errors->isEmpty() => "Import {$subject} selesai",
                $saved > 0 => "Import {$subject} selesai sebagian",
                default => "Import {$subject} gagal",
            })
            ->body($body->implode('<br>'));

        if ($errors->isNotEmpty()) {
            $notification->actions([
                Action::make('openIssues')
                    ->label('Perbaiki di Import Perlu Dicek')
                    ->button()
                    ->url(SalesImportFailureResource::getUrl('index')),
            ]);
        }

        match (true) {
            $errors->isEmpty() && $warnings->isNotEmpty() => $notification->success()->persistent(),
            $errors->isEmpty() => $notification->success(),
            $saved > 0 => $notification->warning()->persistent(),
            default => $notification->danger()->persistent(),
        };

        $notification->send();
    }

    /**
     * @param  Collection<int, string>  $body
     * @param  Collection<int, string>  $lines
     */
    private static function section($body, $lines, string $heading): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        $body->push('', '<b>'.e($heading).'</b>');
        $body->push(...$lines->take(self::MAX_LINES)->map(fn (string $line): string => e($line))->all());

        if ($lines->count() > self::MAX_LINES) {
            $body->push('… dan '.($lines->count() - self::MAX_LINES).' lainnya.');
        }
    }
}
