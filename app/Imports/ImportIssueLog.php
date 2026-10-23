<?php

namespace App\Imports;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use App\Models\SalesImportFailure;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat baris yang perlu dicek untuk 1 kali unggah (atau 1 kali coba ulang) sebuah importer.
 *  - fail(): baris gagal (nilai asli per LABEL HEADER + alasan) disimpan ke "Import Perlu Dicek";
 *  - fixed(): baris berkolom ID GAGAL yang kini berhasil, menandai baris gagal lamanya "Sudah diperbaiki";
 *  - save(): simpan semuanya + kirim notifikasi lonceng ke pengimpor bila ada baris baru yang perlu dicek.
 */
class ImportIssueLog
{
    /** Kolom ID baris gagal di file template perbaikan (header hijau). */
    public const FAILURE_ID_COLUMN = 'ID GAGAL';

    /** Kolom hijau di belakang template perbaikan (diabaikan saat impor, kecuali ID GAGAL). */
    public const FIX_COLUMNS = [self::FAILURE_ID_COLUMN, 'KATEGORI', 'ALASAN GAGAL', 'CARA MEMPERBAIKI', 'FILE ASAL', 'BARIS ASAL'];

    public readonly string $uploadId;

    /** @var list<array{line: int, values: array<string, string>, reason: ImportFailureReason, message: string, retry_of: ?int, group_key: ?string}> */
    private array $failures = [];

    /** @var list<int> */
    private array $fixed = [];

    public function __construct(public readonly string $importer, public readonly ?string $fileName = null)
    {
        $this->uploadId = (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $values  LABEL HEADER => nilai sel
     */
    public function fail(int $line, array $values, string $message, ?ImportFailureReason $reason = null, ?int $retryOf = null, ?string $groupKey = null): void
    {
        $message = rtrim(preg_replace('/^Baris [\d\-]+: /', '', trim($message)) ?? $message, '.').'.';

        $this->failures[] = [
            'line' => $line,
            'values' => array_map(fn ($value): string => is_scalar($value) || $value === null ? trim((string) $value) : (string) json_encode($value), $values),
            'reason' => $reason ?? ImportFailureReason::classify($message),
            'message' => $message,
            'retry_of' => $retryOf,
            'group_key' => $groupKey === null ? null : substr(hash('sha256', $groupKey), 0, 64),
        ];
    }

    public function fixed(?int $failureId): void
    {
        if ($failureId !== null) {
            $this->fixed[] = $failureId;
        }
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * ID baris gagal dari kolom ID GAGAL (angka) atau null.
     */
    public static function failureId(mixed $value): ?int
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        return ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Simpan baris gagal + perbarui status baris lama yang diimport ulang lewat ID GAGAL.
     *
     * @return int jumlah baris gagal lama yang kini sudah diperbaiki
     */
    public function save(?int $batchId = null, bool $notify = true): int
    {
        foreach ($this->failures as $failure) {
            SalesImportFailure::create([
                'importer' => $this->importer,
                'batch_id' => $batchId,
                'upload_id' => $this->uploadId,
                'file_name' => $this->fileName,
                'line' => $failure['line'],
                'group_key' => $failure['group_key'],
                'values' => $failure['values'],
                'reason' => $failure['reason'],
                'message' => $failure['message'],
                'retry_of_id' => $failure['retry_of'] !== null && SalesImportFailure::query()->whereKey($failure['retry_of'])->exists() ? $failure['retry_of'] : null,
                'created_by' => auth()->id(),
            ]);
        }

        // Baris lama, baris asalnya, dan percobaan ulang lain untuk baris yang sama (mis. file yang sama diimport dua kali).
        $resolve = fn (array $ids, ImportFailureStatus $status): int => $ids === [] ? 0 : SalesImportFailure::query()
            ->where(fn ($query) => $query
                ->whereKey($ids = array_values(array_unique($ids)))
                ->orWhereIn('retry_of_id', $ids)
                ->orWhereIn('id', SalesImportFailure::query()->whereKey($ids)->whereNotNull('retry_of_id')->select('retry_of_id')))
            ->where(fn ($query) => $query->whereNull('upload_id')->orWhere('upload_id', '!=', $this->uploadId))
            ->whereIn('status', [ImportFailureStatus::Open, ImportFailureStatus::Retried])
            ->update(['status' => $status, 'resolved_batch_id' => $batchId, 'resolved_at' => now()]);

        $fixed = $resolve($this->fixed, ImportFailureStatus::Fixed);
        $resolve(array_diff(array_filter(array_column($this->failures, 'retry_of')), $this->fixed), ImportFailureStatus::Retried);

        if ($notify) {
            $this->notify();
        }

        return $fixed;
    }

    /**
     * Notifikasi lonceng (database) ke pengimpor: ada baris baru yang perlu dicek di kategori ini.
     */
    private function notify(): void
    {
        $count = collect($this->failures)->where('line', '>', 0)->count();
        $user = auth()->user();

        if ($count === 0 || ! $user instanceof User) {
            return;
        }

        try {
            Notification::make()
                ->title("{$count} baris ".ImportRegistry::label($this->importer).' perlu dicek')
                ->body(($this->fileName ? e($this->fileName).': ' : '').'perbaiki langsung atau unduh template kategori ini di Import Perlu Dicek.')
                ->icon('heroicon-o-exclamation-triangle')
                ->warning()
                ->actions([
                    Action::make('open')
                        ->label('Buka Import Perlu Dicek')
                        ->button()
                        ->markAsRead()
                        ->url(SalesImportFailureResource::getUrl('index', ['tab' => ImportRegistry::tab($this->importer)])),
                ])
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception); // notifikasi tidak boleh menggagalkan impor
        }
    }
}
