<?php

use App\Filament\Pages\Backups;
use App\Models\User;
use Brimham\BackupMonitor\Models\BackupRun;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('shows the backup history and destination health to super admins', function () {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    BackupRun::query()->create(['type' => 'backup', 'status' => 'success', 'backup_name' => 'sanjaya-backup', 'disk' => 'local', 'size_in_bytes' => 2_000_000]);
    BackupRun::query()->create(['type' => 'backup', 'status' => 'failed', 'backup_name' => 'sanjaya-backup', 'disk' => 'r2', 'message' => 'Kredensial R2 salah']);

    $this->actingAs($admin)->get('/administrator/backup')->assertOk()->assertSee('Backup & Monitoring');
    Livewire::test(Backups::class)
        ->assertCanSeeTableRecords(BackupRun::query()->get())
        ->assertActionVisible('runBackup')
        ->assertActionVisible('downloadLatest');
});

it('limits the backup page to staff granted its Shield page permission', function () {
    $staff = User::factory()->create(['is_active' => true]);
    $this->actingAs($staff)->get('/administrator/backup')->assertForbidden();

    $staff->givePermissionTo(Permission::findOrCreate('View:Backups', 'web'));
    $staff->unsetRelation('permissions');
    $this->get('/administrator/backup')->assertOk();
});

it('finds the newest local backup archive to download', function () {
    Storage::fake('local');
    $folder = config('backup.backup.name');
    Storage::disk('local')->put("{$folder}/2026-10-30-02-00-00.zip", 'lama');
    touch(Storage::disk('local')->path("{$folder}/2026-10-30-02-00-00.zip"), now()->subDay()->getTimestamp());
    Storage::disk('local')->put("{$folder}/2026-10-31-02-00-00.zip", 'baru');
    Storage::disk('local')->put("{$folder}/catatan.txt", 'bukan arsip');

    expect(Backups::latestLocalBackup())->toBe("{$folder}/2026-10-31-02-00-00.zip");
});
