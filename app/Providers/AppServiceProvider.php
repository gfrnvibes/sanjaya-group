<?php

namespace App\Providers;

use App\Models\GoodsReceiptItem;
use App\Models\MaterialPurchaseItem;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\StockAdjustmentItem;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Observers\BusinessRecordObserver;
use App\Policies\ActivityPolicy;
use App\Services\SalesDashboardService;
use App\Services\SkuMatcher;
use App\Support\ApplicationAccess;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SalesDashboardService::class);
        $this->app->scoped(SkuMatcher::class); // indeks varian dibangun sekali per request (saran SKU per baris tabel)
        // Semua TextInput::make() memakai versi aplikasi: input angka otomatis berpemisah ribuan titik.
        $this->app->bind(TextInput::class, \App\Filament\Forms\Components\TextInput::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('local')) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        // Semua tabel panel: 25 baris per halaman (tabel yang mengatur sendiri tetap memakai pengaturannya).
        Table::configureUsing(fn (Table $table): Table => $table
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25));

        // Model vendor (log aktivitas) tidak ikut auto-discovery policy: daftarkan policy Shield-nya.
        Gate::policy(Activity::class, ActivityPolicy::class);

        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! $user->is_active || ! $user->isKonveksiStaff()) {
                return false;
            }

            $model = $arguments[0] ?? null;

            if ($model instanceof Model || (is_string($model) && is_subclass_of($model, Model::class))) {
                $decision = ApplicationAccess::model($ability, $model, $user);

                if ($decision !== null) {
                    return $decision;
                }
            }

            // Super admin: semua akses. Selain itu policy & permission Filament Shield yang memutuskan.
            return $user->hasRole(ApplicationAccess::superAdminRole()) ? true : null;
        });

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $class::observe(BusinessRecordObserver::class);
            }
        }

        foreach ([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class] as $eventClass) {
            Event::listen($eventClass, function ($event): void {
                $values = $event->rolesOrIds ?? $event->permissionsOrIds ?? [];
                $ids = collect($values instanceof Model ? [$values] : $values)->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)->values()->all();
                activity('access')->performedOn($event->model)->causedBy(auth()->user())->event(class_basename($event))
                    ->withProperties(['ids' => $ids, 'origin' => app()->runningInConsole() ? 'console' : 'web'])->log(class_basename($event));
            });
        }

        // Alias pendek & stabil untuk kolom source_type di ledger stok/bahan.
        Relation::morphMap([
            'goods_receipt_item' => GoodsReceiptItem::class,
            'material_purchase_item' => MaterialPurchaseItem::class,
            'sales_order_item' => SalesOrderItem::class,
            'sales_return' => SalesReturn::class,
            'stock_adjustment_item' => StockAdjustmentItem::class,
            'stock_opname_item' => StockOpnameItem::class,
            'work_order_item' => WorkOrderItem::class,
        ]);
    }
}
