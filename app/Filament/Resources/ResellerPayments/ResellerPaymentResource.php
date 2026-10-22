<?php

namespace App\Filament\Resources\ResellerPayments;

use App\Enums\PaymentMethod;
use App\Filament\Resources\ResellerPayments\Pages\ManageResellerPayments;
use App\Models\ResellerPayment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ResellerPaymentResource extends Resource
{
    protected static ?string $model = ResellerPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Pembayaran Reseller';

    protected static ?string $pluralModelLabel = 'Pembayaran Reseller';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('reseller_id')
                    ->label('Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                DateTimePicker::make('paid_at')
                    ->label('Waktu Bayar')
                    ->default(now())
                    ->required(),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                Select::make('method')
                    ->label('Metode')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Transfer)
                    ->required()
                    ->live(),
                Select::make('cash_account_id')
                    ->label('Masuk ke Rekening / Kas')
                    ->relationship('cashAccount', 'name')
                    ->preload()
                    ->required(fn (Get $get): bool => self::method($get) !== PaymentMethod::CostOffset)
                    ->visible(fn (Get $get): bool => self::method($get) !== PaymentMethod::CostOffset),
                FileUpload::make('proof_path')
                    ->preventFilePathTampering()
                    ->label('Bukti Bayar')
                    ->image()
                    ->disk('local')
                    ->directory('payment-proofs')
                    ->visibility('private')
                    ->maxSize(5120),
                TextInput::make('note')
                    ->label('Catatan')
                    ->helperText('Untuk Potongan Biaya, catat biayanya di Keuangan > Biaya dan tautkan ke pembayaran ini.')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['reseller', 'cashAccount']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reseller.name')
                    ->label('Reseller')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('method')
                    ->label('Metode')
                    ->badge(),
                TextColumn::make('cashAccount.name')
                    ->label('Rekening')
                    ->placeholder('-'),
                TextColumn::make('proof_path')
                    ->label('Bukti')
                    ->formatStateUsing(fn (?string $state): string => $state ? 'Lihat' : '-')
                    ->url(fn (ResellerPayment $record): ?string => $record->proof_path ? route('filament.administrator.payment-proofs.download', ['payment' => $record->id]) : null, shouldOpenInNewTab: true),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('method')
                    ->label('Metode')
                    ->options(PaymentMethod::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function method(Get $get): ?PaymentMethod
    {
        $method = $get('method');

        return $method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom((string) $method);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResellerPayments::route('/'),
        ];
    }
}
