<?php

namespace App\Filament\Resources\ProductModels\Pages;

use App\Filament\Resources\ProductModels\ProductModelResource;
use App\Models\Color;
use App\Models\ProductModel;
use App\Models\Size;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewProductModel extends ViewRecord
{
    protected static string $resource = ProductModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateVariants')
                ->label('Buat Varian Otomatis')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->modalDescription('Membuat varian untuk setiap kombinasi warna x ukuran. SKU = kode model + kode warna + kode ukuran. Kombinasi yang sudah ada dilewati.')
                ->schema([
                    CheckboxList::make('colors')
                        ->label('Warna')
                        ->options(Color::query()->orderBy('name')->pluck('name', 'id'))
                        ->columns(3)
                        ->bulkToggleable(),
                    CheckboxList::make('sizes')
                        ->label('Ukuran')
                        ->options(Size::query()->orderBy('sort_order')->pluck('code', 'id'))
                        ->columns(4)
                        ->bulkToggleable(),
                ])
                ->action(function (array $data, ProductModel $record): void {
                    $colors = Color::query()->whereKey($data['colors'] ?: [])->get();
                    $sizes = Size::query()->whereKey($data['sizes'] ?: [])->orderBy('sort_order')->get();
                    $colors = $colors->isEmpty() ? collect([null]) : $colors;
                    $sizes = $sizes->isEmpty() ? collect([null]) : $sizes;
                    $created = 0;

                    foreach ($colors as $color) {
                        foreach ($sizes as $size) {
                            $variant = $record->variants()->withTrashed()->firstOrCreate(
                                ['color_id' => $color?->id, 'size_id' => $size?->id],
                                [
                                    'sku' => $record->code.$color?->code.$size?->code,
                                    'name' => trim("{$record->name} {$color?->name} {$size?->code}"),
                                ],
                            );

                            $created += (int) $variant->wasRecentlyCreated;
                        }
                    }

                    Notification::make()
                        ->title("{$created} varian baru dibuat")
                        ->success()
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
