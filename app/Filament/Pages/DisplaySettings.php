<?php

namespace App\Filament\Pages;

use App\Enums\LayoutWidth;
use App\Enums\TableDensity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * PENGATURAN TAMPILAN per user: lebar konten panel & kepadatan tabel. Dibuka dari menu user (pojok kanan atas).
 *
 * @property-read Schema $form
 */
class DisplaySettings extends Page
{
    protected static ?string $slug = 'tampilan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Pengaturan Tampilan';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getSubheading(): ?string
    {
        return 'Berlaku untuk akun Anda saja, di semua perangkat.';
    }

    public function mount(): void
    {
        $user = $this->user();

        $this->form->fill([
            'layout_width' => ($user->layout_width ?? LayoutWidth::Standard)->value,
            'table_density' => ($user->table_density ?? TableDensity::Comfortable)->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make('Lebar')
                        ->icon(Heroicon::OutlinedArrowsPointingOut)
                        ->schema([
                            Radio::make('layout_width')
                                ->hiddenLabel()
                                ->options(LayoutWidth::class)
                                ->required(),
                        ]),
                    Section::make('Kepadatan tabel')
                        ->icon(Heroicon::OutlinedTableCells)
                        ->schema([
                            Radio::make('table_density')
                                ->hiddenLabel()
                                ->options(TableDensity::class)
                                ->required(),
                        ]),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Simpan')->submit('save'),
                        Action::make('reset')
                            ->label('Kembalikan default')
                            ->color('gray')
                            ->action(function (): void {
                                $this->form->fill([
                                    'layout_width' => LayoutWidth::Standard->value,
                                    'table_density' => TableDensity::Comfortable->value,
                                ]);
                                $this->save();
                            }),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->user()->update([
            'layout_width' => $data['layout_width'],
            'table_density' => $data['table_density'],
        ]);

        Notification::make()->title('Tampilan disimpan')->success()->send();

        // Lebar & gaya tabel dipasang saat halaman dimuat: muat ulang agar langsung terlihat.
        $this->redirect(static::getUrl());
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
