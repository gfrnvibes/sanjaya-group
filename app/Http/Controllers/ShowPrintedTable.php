<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Halaman cetak tabel yang disiapkan ResourceIo::printAction() (sekali pakai per user, berlaku 15 menit).
 */
class ShowPrintedTable extends Controller
{
    public function __invoke(string $token): Response
    {
        $page = Cache::get("print:{$token}");

        abort_if(! is_array($page) || $page['user'] !== auth()->id(), 404, 'Halaman cetak sudah kedaluwarsa. Klik Cetak / PDF lagi.');

        return response($page['html'])->header('Content-Type', 'text/html; charset=utf-8');
    }
}
