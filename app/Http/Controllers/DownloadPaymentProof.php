<?php

namespace App\Http\Controllers;

use App\Models\ResellerPayment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadPaymentProof extends Controller
{
    public function __invoke(ResellerPayment $payment): BinaryFileResponse
    {
        Gate::authorize('view', $payment);
        $path = $payment->proof_path;
        abort_unless(is_string($path) && str_starts_with($path, 'payment-proofs/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), basename($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
