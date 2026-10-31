<?php

use App\Filament\Forms\Components\TextInput as AppTextInput;
use App\Filament\Resources\Materials\Pages\EditMaterial;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
});

it('uses the application text input with thousands separators for numeric fields only', function () {
    expect(TextInput::make('amount'))->toBeInstanceOf(AppTextInput::class)
        ->and(TextInput::make('amount')->numeric()->getMask()?->toHtml())->toContain('$money($input')
        ->and(TextInput::make('qty')->integer()->getMask()?->toHtml())->toContain(', 0)')
        ->and(TextInput::make('year')->integer()->plainNumber()->getMask())->toBeNull()
        ->and(TextInput::make('name')->getMask())->toBeNull();
});

it('shows stored numbers with dots and saves typed dotted numbers as plain numbers', function () {
    $f = businessFixture();
    $f['material']->update(['qty_per_pack' => 1.5, 'min_stock' => 12500]);

    Livewire::test(EditMaterial::class, ['record' => $f['material']->getRouteKey()])
        // Isi form (yang tampil di browser) bertitik; state yang dibaca server tetap angka.
        ->assertSet('data.qty_per_pack', '1,5')
        ->assertSet('data.min_stock', '12.500')
        ->assertSchemaStateSet(['qty_per_pack' => 1.5, 'min_stock' => 12500.0])
        ->fillForm(['qty_per_pack' => '10.000', 'min_stock' => '1.250,75'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $f['material']->fresh()->qty_per_pack)->toBe(10000.0)
        ->and((float) $f['material']->fresh()->min_stock)->toBe(1250.75);
});

it('still rejects text that is not a number', function () {
    $f = businessFixture();

    Livewire::test(EditMaterial::class, ['record' => $f['material']->getRouteKey()])
        ->fillForm(['qty_per_pack' => 'sepuluh'])
        ->call('save')
        ->assertHasFormErrors(['qty_per_pack']);
});
