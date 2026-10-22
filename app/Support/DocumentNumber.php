<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentNumber
{
    public static function next(string $prefix, string $table, string $column, int $width = 4): string
    {
        if (! in_array("{$table}.{$column}", ['reseller_invoices.invoice_no', 'work_orders.code', 'goods_receipts.receipt_no', 'sales_orders.order_no'], true)) {
            throw new InvalidArgumentException('Unsupported document sequence.');
        }

        $row = DB::selectOne("INSERT INTO document_sequences (prefix, last_number)
            SELECT ?, COALESCE(MAX(CASE WHEN substring({$column} from ?::int) ~ '^[0-9]+$'
                THEN substring({$column} from ?::int)::bigint END), 0) + 1 FROM {$table} WHERE {$column} LIKE ?
            ON CONFLICT (prefix) DO UPDATE SET last_number = GREATEST(document_sequences.last_number + 1, EXCLUDED.last_number)
            RETURNING last_number", [$prefix, strlen($prefix) + 1, strlen($prefix) + 1, $prefix.'%']);

        return $prefix.str_pad((string) $row->last_number, $width, '0', STR_PAD_LEFT);
    }
}
