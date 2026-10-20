<?php

namespace App\Http\Controllers;

use App\Enums\ImportFailureStatus;
use App\Models\SalesImportFailure;
use App\Services\SalesOrderSpreadsheet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduh file baris gagal (belum diperbaiki) untuk batch tertentu — dipakai tombol di notifikasi hasil import.
 */
class DownloadSalesImportFailures extends Controller
{
    public function __invoke(Request $request, SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse
    {
        $batchIds = array_filter(array_map('intval', explode(',', (string) $request->query('batches'))));

        $failures = SalesImportFailure::query()
            ->where('status', ImportFailureStatus::Open)
            ->when($batchIds !== [], fn ($query) => $query->whereIn('batch_id', $batchIds));

        return response()
            ->download($spreadsheet->failures($failures), $spreadsheet->fileName('baris-gagal-pesanan', 'xlsx'))
            ->deleteFileAfterSend();
    }
}
