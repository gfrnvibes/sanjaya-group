<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * LOG AKTIVITAS: siapa mengubah data apa dan kapan (dari BusinessRecordObserver & perubahan hak akses).
 * Hanya baca; hanya administrator.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Log Aktivitas';

    protected static ?string $modelLabel = 'Aktivitas';

    protected static ?string $pluralModelLabel = 'Log Aktivitas';

    protected static ?string $slug = 'log-aktivitas';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Label event dalam bahasa Indonesia. */
    public const EVENTS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'RoleAttachedEvent' => 'Peran diberikan',
        'RoleDetachedEvent' => 'Peran dicabut',
        'PermissionAttachedEvent' => 'Izin diberikan',
        'PermissionDetachedEvent' => 'Izin dicabut',
    ];

    /**
     * Nama data dari kelas model: label resource Filament bila ada (mis. "Pesanan"), selain itu nama kelas.
     */
    public static function subjectLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '-';
        }

        $resource = class_exists($type) ? Filament::getModelResource($type) : null;

        return $resource ? Str::ucfirst($resource::getModelLabel()) : Str::headline(class_basename($type));
    }

    public static function subjectText(Activity $record): string
    {
        $key = $record->subject_id ?? Str::after((string) $record->properties?->get('subject'), ':');
        $type = $record->subject_type ?? ($record->properties?->has('subject') ? 'App\\Models\\'.Str::before((string) $record->properties->get('subject'), ':') : null);
        $title = $record->subject?->getAttribute('name') ?? $record->subject?->getAttribute('code') ?? $record->subject?->getAttribute('sku')
            ?? $record->subject?->getAttribute('receipt_no') ?? $record->subject?->getAttribute('order_no');

        return self::subjectLabel($type).($key !== '' && $key !== null ? " #{$key}" : '').($title ? " — {$title}" : '');
    }

    /**
     * Ringkasan perubahan: "status: open → fixed; qty: 2 → 3".
     */
    public static function changes(Activity $record, int $limit = 4): ?string
    {
        $new = collect($record->properties?->get('attributes', []));
        $old = collect($record->properties?->get('old', []));

        if ($record->event === 'deleted') {
            return $old->keys()->take($limit)->implode(', ') ?: null;
        }

        $lines = $new->map(fn ($value, string $key): string => $record->event === 'updated'
            ? "{$key}: ".self::scalar($old->get($key)).' → '.self::scalar($value)
            : "{$key}: ".self::scalar($value));

        if ($record->properties?->has('ids')) {
            $lines->push('ID: '.collect($record->properties->get('ids'))->implode(', '));
        }

        return $lines->isEmpty() ? null : $lines->take($limit)->implode('; ').($lines->count() > $limit ? '; …' : '');
    }

    /**
     * Baris rincian: kolom, nilai lama, nilai baru.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changeRows(Activity $record): array
    {
        $new = collect($record->properties?->get('attributes', []));
        $old = collect($record->properties?->get('old', []));

        return $new->keys()->merge($old->keys())->unique()->values()
            ->map(fn (string $field): array => [
                'field' => $field,
                'old' => $old->has($field) ? self::scalar($old->get($field)) : '',
                'new' => $new->has($field) ? self::scalar($new->get($field)) : '',
            ])
            ->all();
    }

    public static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '∅',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE), 60),
            default => Str::limit((string) $value, 60),
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('causer.name')->label('Pengguna')->placeholder('Sistem'),
                    TextEntry::make('event')->label('Aksi')->formatStateUsing(fn (?string $state): string => self::EVENTS[$state] ?? (string) $state)->badge(),
                    TextEntry::make('subject')->label('Data')->state(fn (Activity $record): string => self::subjectText($record))->columnSpan(2),
                    TextEntry::make('origin')->label('Asal')->state(fn (Activity $record): string => $record->properties?->get('origin') === 'console' ? 'Sistem / perintah' : 'Aplikasi web'),
                ]),
            Section::make('Rincian perubahan')
                ->schema([
                    View::make('filament.activity-log.changes')
                        ->viewData(fn (Activity $record): array => ['rows' => self::changeRows($record)]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Pengguna')
                    ->placeholder('Sistem')
                    ->searchable(),
                TextColumn::make('event')
                    ->label('Aksi')
                    ->formatStateUsing(fn (?string $state): string => self::EVENTS[$state] ?? (string) $state)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('subject_type')
                    ->label('Data')
                    ->state(fn (Activity $record): string => self::subjectText($record))
                    ->wrap(),
                TextColumn::make('properties')
                    ->label('Perubahan')
                    ->state(fn (Activity $record): ?string => self::changes($record))
                    ->placeholder('-')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw('properties::text ilike ?', ['%'.$search.'%'])),
                TextColumn::make('origin')
                    ->label('Asal')
                    ->state(fn (Activity $record): string => $record->properties?->get('origin') === 'console' ? 'Sistem' : 'Web')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('log_name')
                    ->label('Jenis log')
                    ->formatStateUsing(fn (?string $state): string => $state === 'access' ? 'Hak akses' : 'Data bisnis')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Aksi')
                    ->options(self::EVENTS)
                    ->multiple(),
                SelectFilter::make('causer_id')
                    ->label('Pengguna')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))),
                SelectFilter::make('subject_type')
                    ->label('Jenis data')
                    ->options(fn (): array => Activity::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => self::subjectLabel($type)])->sort()->all())
                    ->multiple()
                    ->searchable(),
                SelectFilter::make('log_name')
                    ->label('Jenis log')
                    ->options(['business' => 'Data bisnis', 'access' => 'Hak akses']),
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Periode: '.($data['from'] ?? '…').' s/d '.($data['until'] ?? '…')
                        : null),
            ])
            ->recordActions([
                ViewAction::make()->label('Rincian'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
