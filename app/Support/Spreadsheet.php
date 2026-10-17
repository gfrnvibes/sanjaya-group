<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Helper baca/tulis file impor (xlsx, csv, ods) memakai OpenSpout.
 */
class Spreadsheet
{
    private const HEADER_SCAN_ROWS = 15;

    /** Warna header kolom otomatis (hijau muda, seperti sheet manual). */
    public const AUTO_FILL = 'C6EFCE';

    /**
     * Baca sheet pertama. Baris header dicari di 15 baris teratas (boleh ada judul di atasnya
     * dan kolom lain di sampingnya). Baris kosong dilewati; nomor baris = nomor baris di Excel.
     *
     * @param  array<string, string>  $headers  LABEL HEADER => kunci
     * @param  list<string|list<string>>  $required  kunci wajib; list bersarang = salah satu wajib ada
     * @return list<array{sheet: string, line: int, values: array<string, mixed>}>
     */
    public static function read(string $path, string $fileName, array $headers, array $required, string $missingHeaderMessage): array
    {
        $sheets = self::readSheets($path, $fileName, ['data' => ['headers' => $headers, 'required' => $required]], firstSheetOnly: true);

        if (! isset($sheets['data'])) {
            throw new BusinessRuleException($missingHeaderMessage);
        }

        return $sheets['data'];
    }

    /**
     * Baca workbook multi-sheet. Jenis tiap sheet dikenali dari header-nya (nama sheet bebas),
     * jadi file CSV satu sheet juga bisa dipakai. Sheet tanpa header yang dikenali diabaikan.
     *
     * @param  array<string, array{headers: array<string, string>, required: list<string|list<string>>}>  $definitions  jenis => definisi; dicoba berurutan
     * @return array<string, list<array{sheet: string, line: int, values: array<string, mixed>}>> hanya jenis yang ditemukan
     */
    public static function readSheets(string $path, string $fileName, array $definitions, bool $firstSheetOnly = false): array
    {
        $reader = self::reader($path, strtolower(pathinfo($fileName, PATHINFO_EXTENSION)));
        $reader->open($path);

        $result = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                $type = null;
                $columns = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = $row->toArray();

                    if ($columns === null) {
                        foreach ($definitions as $key => $definition) {
                            $columns = self::detectHeader($values, $definition['headers'], $definition['required']);

                            if ($columns !== null) {
                                $type = $key;
                                $result[$type] ??= [];
                                break;
                            }
                        }

                        if ($columns === null && $line >= self::HEADER_SCAN_ROWS) {
                            break;
                        }

                        continue;
                    }

                    $input = [];
                    foreach ($columns as $key => $index) {
                        $value = $values[$index] ?? null;
                        $input[$key] = is_string($value) ? trim($value) : $value;
                    }

                    if (collect($input)->every(fn ($value): bool => $value === null || $value === '')) {
                        continue;
                    }

                    $result[$type][] = ['sheet' => $sheet->getName(), 'line' => $line, 'values' => $input];
                }

                if ($firstSheetOnly) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /**
     * Persentase dari sel: 0.3 / "30" / "30%" => 0.3. Angka > 1 dianggap persen.
     */
    public static function parsePercent(mixed $value): ?float
    {
        $number = self::parseNumber($value);

        if ($number === null) {
            return null;
        }

        return (is_string($value) && str_contains($value, '%')) || abs($number) > 1 ? $number / 100 : $number;
    }

    public static function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_int($value) || is_float($value)) {
            // Nomor seri tanggal Excel.
            return $value > 0 ? Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString() : null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d H:i:s', 'd/m/Y H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Angka dari sel Excel atau teks seperti "Rp 52.492" / "52,492.50".
     */
    public static function parseNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = preg_replace('/[^\d.,\-]/', '', (string) $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            // Pemisah desimal = yang muncul paling akhir.
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        } elseif ($hasComma) {
            $value = preg_match('/^-?\d{1,3}(,\d{3})+$/', $value) ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Ya/Tidak dari sel; null bila kosong atau tidak dikenali.
     */
    public static function parseBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (Str::lower(trim((string) $value))) {
            '1', 'ya', 'y', 'yes', 'true', 'aktif' => true,
            '0', 'tidak', 't', 'no', 'n', 'false', 'nonaktif', 'tidak aktif' => false,
            default => null,
        };
    }

    public static function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // Angka bulat dari Excel (mis. no HP / kode) jangan jadi "812345.0".
        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, bool>  $columns  header => true bila kolom input (merah)
     */
    public static function headerRow(array $columns): Row
    {
        $cells = [];

        foreach ($columns as $label => $isInput) {
            $cells[] = Cell::fromValue($label, (new Style)
                ->setFontBold()
                ->setCellAlignment(CellAlignment::CENTER)
                ->setFontColor($isInput ? Color::WHITE : Color::BLACK)
                ->setBackgroundColor($isInput ? Color::RED : self::AUTO_FILL));
        }

        return new Row($cells);
    }

    /**
     * Sheet referensi/petunjuk di workbook template.
     *
     * @param  list<float>  $widths
     * @param  list<list<mixed>>  $rows  baris pertama = header
     */
    public static function addSheet(XlsxWriter $writer, string $name, array $widths, array $rows): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $header = array_shift($rows);
        $writer->addRow(Row::fromValues($header, (new Style)->setFontBold()->setBackgroundColor('D9D9D9')));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }

    public static function tempPath(string $extension): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
    }

    /**
     * @param  list<mixed>  $values
     * @param  array<string, string>  $headers
     * @param  list<string|list<string>>  $required
     * @return array<string, int>|null kunci => indeks kolom
     */
    private static function detectHeader(array $values, array $headers, array $required): ?array
    {
        $columns = [];

        foreach ($values as $index => $value) {
            $label = Str::of((string) (is_scalar($value) ? $value : ''))->upper()->squish()->toString();

            if (isset($headers[$label]) && ! isset($columns[$headers[$label]])) {
                $columns[$headers[$label]] = $index;
            }
        }

        foreach ($required as $keys) {
            if (collect((array) $keys)->every(fn (string $key): bool => ! isset($columns[$key]))) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * Baris kosong dipertahankan agar nomor baris di pesan error sama dengan nomor baris di Excel.
     */
    private static function reader(string $path, string $extension): ReaderInterface
    {
        switch ($extension) {
            case 'xlsx':
                $options = new XlsxOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

                return new XlsxReader($options);
            case 'ods':
                $options = new OdsOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

                return new OdsReader($options);
            case 'csv':
            case 'txt':
                $options = new CsvOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $firstLine = (string) fgets($handle = fopen($path, 'r'));
                fclose($handle);
                $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

                return new CsvReader($options);
            default:
                throw new BusinessRuleException('Format file tidak didukung. Gunakan .xlsx, .csv, atau .ods.');
        }
    }
}
