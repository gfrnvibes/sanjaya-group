<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

it('renders all administrator resource index and create pages for an administrator', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($user);
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName() ?? '';
        if (str_starts_with($name, 'filament.administrator.resources.') && (str_ends_with($name, '.index') || str_ends_with($name, '.create')) && ! str_contains($route->uri(), '{')) {
            $response = $this->get('/'.$route->uri());
            $this->assertSame(200, $response->status(), $route->uri());
        }
    }
    $this->get('/administrator/dashboard-produk')->assertOk();
    $this->get('/administrator/data-exchange')->assertOk();
});
