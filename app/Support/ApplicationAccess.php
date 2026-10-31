<?php

namespace App\Support;

use App\Imports\ImportRegistry;
use App\Models\ReadOnlyModel;
use App\Models\SalesImportFailure;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Hak akses aplikasi (di atas Filament Shield):
 *  - role super_admin (config filament-shield.super_admin.name) = semua akses;
 *  - resource / halaman / widget: permission Shield (mis. ViewAny:SalesOrder, View:SupplierBills) lewat policy;
 *  - PERMISSIONS = izin khusus Shield untuk tombol import/export & kategori "Import Perlu Dicek" per jenis data;
 *  - "administrator" = khusus super_admin.
 */
class ApplicationAccess
{
    public const PERMISSIONS = ['kelola penjualan', 'kelola stok', 'kelola produksi', 'kelola keuangan', 'kelola katalog', 'kelola mitra', 'lihat laporan'];

    public static function superAdminRole(): string
    {
        return (string) config('filament-shield.super_admin.name', 'super_admin');
    }

    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && self::isActiveStaff($user) && $user->hasRole(self::superAdminRole());
    }

    public static function isActiveStaff(User $user): bool
    {
        return $user->is_active && $user->isKonveksiStaff();
    }

    /**
     * Izin khusus (PERMISSIONS) atau "administrator" (super admin saja).
     */
    public static function allows(string $permission, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! self::isActiveStaff($user)) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if ($permission === 'administrator') {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Aturan model di luar policy Shield (dipanggil dari Gate::before). Null = serahkan ke policy Shield.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function model(string $ability, Model|string $model, User $user): ?bool
    {
        // Data laporan (view database) hanya bisa dibaca, termasuk oleh super admin.
        if (is_a($model, ReadOnlyModel::class, true) && ! in_array($ability, ['view', 'viewAny'], true)) {
            return false;
        }

        // Import Perlu Dicek: tiap user melihat & memperbaiki kategori yang boleh ia impor (izin khusus).
        // Hapus permanen tetap lewat permission Shield (Delete:SalesImportFailure; default hanya super admin).
        if (class_basename($model) === 'SalesImportFailure' && in_array($ability, ['viewAny', 'view', 'update'], true)) {
            $categories = collect(ImportRegistry::all());

            return $model instanceof SalesImportFailure
                ? isset($categories[$model->importer]) && self::allows($categories[$model->importer]['permission'], $user)
                : $categories->pluck('permission')->unique()->contains(fn (string $permission): bool => self::allows($permission, $user));
        }

        return null;
    }
}
