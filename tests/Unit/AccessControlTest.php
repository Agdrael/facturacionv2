<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/AccessDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** @param list<string> $roles */
function prepareAccessAccount(TestCase $test, array $roles = ['admin'], bool $employee = true): User
{
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'employee-access.allowed_domain' => 'example.test',
    ]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $test->withoutMiddleware(ValidateSessionWithWorkOS::class);
    (require __DIR__.'/../../database/migrations/0001_01_01_000000_create_users_table.php')->up();
    (require __DIR__.'/../../database/migrations/2026_11_17_212602_create_access_control_tables.php')->up();
    app(AccessControlService::class)->synchronize();
    $user = User::factory()->create(['email' => 'empleado@example.test']);
    app(AccessControlService::class)->assignRoles($user, Role::query()->whereIn('code', $roles)->pluck('id')->all());
    $dte = DB::connection('dtesantacecilia');
    $dte->statement("ATTACH DATABASE ':memory:' AS public");
    $dte->statement('CREATE TABLE public.empleados (empleado_id TEXT PRIMARY KEY, codigo TEXT, correo TEXT)');
    if ($employee) {
        $dte->table('public.empleados')->insert(['empleado_id' => 'E1', 'codigo' => '001', 'correo' => $user->email]);
    }
    $test->actingAs($user);

    return $user;
}

test('a valid employee without roles sees pending access and cannot operate', function () {
    prepareAccessAccount($this, []);
    $this->get(route('dashboard'))->assertRedirect(route('access.pending'));
    $this->get(route('access.pending'))->assertOk()->assertSee('Acceso pendiente de asignación');
    $this->postJson(route('libreria.ventas.store'), [])->assertForbidden()->assertJsonPath('message', 'Acceso pendiente de asignación.');
    $this->get(route('access.pending'))->assertDontSee('Roles y permisos')->assertDontSee('Usuarios y accesos');
});

test('an employee with multiple roles reaches the dashboard and receives their combined permissions', function () {
    $user = prepareAccessAccount($this, ['libreria', 'contador']);
    $this->get(route('dashboard'))->assertOk()->assertSessionHas('codigo_empleado', '001');
    expect($user->can('libreria.ventas.crear'))->toBeTrue()
        ->and($user->can('contaduria.productos.editar'))->toBeTrue()
        ->and($user->can('libreria.ventas.invalidar'))->toBeTrue()
        ->and($user->can('administracion.roles.editar'))->toBeFalse()
        ->and($user->can('libreria.ventas.inexistente'))->toBeFalse();
});

test('roles never bypass the employee access check', function () {
    prepareAccessAccount($this, ['admin'], false);
    $this->get(route('dashboard'))->assertRedirect(route('employee-access.rejected'));
});

test('guests cannot enter access administration', function () {
    prepareAccessAccount($this);
    auth()->logout();
    $this->get(route('administracion.roles'))->assertRedirect(route('login'));
    $this->get(route('administracion.usuarios'))->assertRedirect(route('login'));
});

test('removing one role preserves overlapping permissions and changes access on the next request', function () {
    $user = prepareAccessAccount($this, ['libreria', 'contador']);
    $user->load('roles.permissions');
    $contador = Role::query()->where('code', 'contador')->sole();
    app(AccessControlService::class)->assignRoles($user, [$contador->id]);
    expect($user->can('libreria.ventas.ver'))->toBeTrue()
        ->and($user->can('libreria.ventas.crear'))->toBeFalse();
    $this->postJson(route('libreria.ventas.quote'), [])->assertForbidden();
    $this->get(route('libreria.venta'))->assertOk()->assertSee('Consultar historial')->assertDontSee('Nueva venta');
});

test('role assignment rejects duplicate roles without changing persisted access', function () {
    $user = prepareAccessAccount($this, ['libreria']);
    $id = Role::query()->where('code', 'contador')->sole()->id;
    try {
        app(AccessControlService::class)->assignRoles($user, [$id, $id]);
        $this->fail('Duplicate roles must fail validation.');
    } catch (ValidationException) {
        expect($user->roles()->pluck('code')->all())->toBe(['libreria']);
    }
});

test('synchronization preserves customizations and grants newly registered permissions only to admin', function () {
    prepareAccessAccount($this);
    $role = Role::query()->where('code', 'contador')->sole();
    $role->permissions()->detach();
    config(['permissions.catalog' => [...config('permissions.catalog'), 'libreria.ventas.nueva' => 'Nueva acción']]);
    $this->artisan('access:sync')->assertSuccessful();
    $this->artisan('access:sync')->assertSuccessful();
    expect($role->permissions()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(3)
        ->and(Permission::query()->where('code', 'libreria.ventas.nueva')->count())->toBe(1)
        ->and(Role::query()->where('code', 'admin')->sole()->permissions()->where('code', 'libreria.ventas.nueva')->exists())->toBeTrue();
});

test('only admin and contador roles can invalidate even if another role is given that database permission', function (array $roles, bool $allowed) {
    $user = prepareAccessAccount($this, $roles);
    if ($roles === ['libreria']) {
        Role::query()->where('code', 'libreria')->sole()->permissions()->attach(Permission::query()->where('code', 'libreria.ventas.invalidar')->sole());
    }
    expect(Gate::forUser($user)->allows('libreria.ventas.invalidar'))->toBe($allowed)
        ->and(in_array('libreria.ventas.invalidar', $user->permissionCodes(), true))->toBe($allowed);
})->with([
    'admin' => [['admin'], true],
    'contador' => [['contador'], true],
    'libreria' => [['libreria'], false],
    'combined' => [['libreria', 'contador'], true],
    'unassigned' => [[], false],
]);

test('a librarian cannot call protected accounting and administration actions', function (string $method, string $route, array $parameters) {
    prepareAccessAccount($this, ['libreria']);
    $this->json($method, route($route, $parameters), [])->assertForbidden();
})->with([
    ['GET', 'contaduria.catalogos', []],
    ['GET', 'contaduria.catalogos.registros.index', ['catalog' => 'productos']],
    ['GET', 'contaduria.catalogos.registros.create', ['catalog' => 'productos']],
    ['POST', 'contaduria.catalogos.registros.store', ['catalog' => 'productos']],
    ['GET', 'contaduria.catalogos.registros.edit', ['catalog' => 'productos', 'record' => 1]],
    ['PUT', 'contaduria.catalogos.registros.update', ['catalog' => 'productos', 'record' => 1]],
    ['DELETE', 'contaduria.catalogos.registros.destroy', ['catalog' => 'productos', 'record' => 1]],
    ['GET', 'contaduria.paquetes.index', []],
    ['GET', 'contaduria.paquetes.create', []],
    ['GET', 'contaduria.paquetes.productos', []],
    ['POST', 'contaduria.paquetes.store', []],
    ['GET', 'contaduria.contadores.index', []],
    ['PUT', 'contaduria.contadores.update', ['type' => '01']],
    ['GET', 'administracion.roles', []],
    ['GET', 'administracion.usuarios', []],
]);

test('a contador cannot generate sales or query creation lookups', function (string $method, string $route, array $parameters) {
    prepareAccessAccount($this, ['contador']);
    $this->json($method, route($route, $parameters), [])->assertForbidden();
})->with([
    ['POST', 'libreria.ventas.quote', []],
    ['POST', 'libreria.ventas.store', []],
    ['GET', 'libreria.clientes.index', []],
    ['GET', 'libreria.productos.index', []],
    ['GET', 'libreria.paquetes.index', []],
    ['GET', 'libreria.paquetes.show', ['package' => 1]],
    ['POST', 'libreria.venta.disponibilidad', []],
    ['GET', 'libreria.alumnos.index', []],
    ['GET', 'libreria.alumnos.show', ['student' => 1]],
    ['GET', 'libreria.alumnos.cuotas', ['student' => 1]],
]);

test('an admin creates edits and deletes an unassigned role through livewire', function () {
    prepareAccessAccount($this);
    Livewire::test('pages::administracion.roles')
        ->call('createRole')->set('code', 'supervisor')->set('name', 'Supervisor')
        ->set('permissions', ['libreria.ventas.ver'])->call('saveRole')->assertHasNoErrors();
    $role = Role::query()->where('code', 'supervisor')->sole();
    expect($role->permissions()->pluck('code')->all())->toBe(['libreria.ventas.ver']);
    Livewire::test('pages::administracion.roles')->call('editRole', $role->id)
        ->set('name', 'Supervisor actualizado')->set('permissions', [])->call('saveRole')->assertHasNoErrors();
    expect($role->fresh()->name)->toBe('Supervisor actualizado')->and($role->permissions()->count())->toBe(0);
    Livewire::test('pages::administracion.roles')->call('deleteRole', $role->id)->assertHasNoErrors();
    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

test('role editing rejects unknown codes and invalidation outside contador', function (array $permissions, string $error) {
    prepareAccessAccount($this);
    Livewire::test('pages::administracion.roles')->call('createRole')
        ->set('code', 'supervisor')->set('name', 'Supervisor')->set('permissions', $permissions)
        ->call('saveRole')->assertHasErrors($error);
    $this->assertDatabaseMissing('roles', ['code' => 'supervisor']);
})->with([
    [['libreria.ventas.inexistente'], 'permissions.0'],
    [['libreria.ventas.invalidar'], 'permissions'],
]);

test('view-only role administrators cannot invoke livewire mutations', function () {
    $user = prepareAccessAccount($this, []);
    $role = app(AccessControlService::class)->saveRole(null, 'consulta', 'Consulta', ['administracion.roles.ver', 'administracion.usuarios.ver']);
    app(AccessControlService::class)->assignRoles($user, [$role->id]);
    Livewire::test('pages::administracion.roles')->call('createRole')->assertForbidden();
    Livewire::test('pages::administracion.roles')->set('code', 'escalado')->set('name', 'Escalado')->call('saveRole')->assertForbidden();
    Livewire::test('pages::administracion.roles')->call('deleteRole', $role->id)->assertForbidden();
    Livewire::test('pages::administracion.usuarios')->call('selectUser', $user->id)
        ->set('selectedRoles', [Role::query()->where('code', 'admin')->sole()->id])->call('saveRoles')->assertForbidden();
    expect($user->hasRole('admin'))->toBeFalse();
});

test('admin permissions and initial role identifiers remain protected', function () {
    prepareAccessAccount($this);
    $admin = Role::query()->where('code', 'admin')->sole();
    Livewire::test('pages::administracion.roles')->call('editRole', $admin->id)->assertForbidden();
    Livewire::test('pages::administracion.roles')->call('deleteRole', $admin->id)->assertHasErrors('role');
    $contador = Role::query()->where('code', 'contador')->sole();
    app(AccessControlService::class)->saveRole($contador->id, 'otro_codigo', 'Contabilidad', ['libreria.ventas.ver']);
    expect($contador->fresh()->code)->toBe('contador');
});

test('assigned roles cannot be deleted', function () {
    $user = prepareAccessAccount($this);
    $role = app(AccessControlService::class)->saveRole(null, 'consulta', 'Consulta', []);
    $user->roles()->attach($role);
    Livewire::test('pages::administracion.roles')->call('deleteRole', $role->id)->assertHasErrors('role');
    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('catalog read permission does not grant create edit or delete access', function (string $method, string $route, array $parameters) {
    $user = prepareAccessAccount($this, []);
    $role = app(AccessControlService::class)->saveRole(null, 'consulta', 'Consulta', ['contaduria.productos.ver']);
    app(AccessControlService::class)->assignRoles($user, [$role->id]);

    $this->json($method, route($route, $parameters), [])->assertForbidden();
})->with([
    ['POST', 'contaduria.catalogos.registros.store', ['catalog' => 'productos']],
    ['PUT', 'contaduria.catalogos.registros.update', ['catalog' => 'productos', 'record' => 1]],
    ['DELETE', 'contaduria.catalogos.registros.destroy', ['catalog' => 'productos', 'record' => 1]],
]);

test('deleting the last admin directly through the model is also blocked', function () {
    $user = prepareAccessAccount($this);
    try {
        $user->delete();
        $this->fail('Deleting the last administrator must fail.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('roles');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
});

test('an admin assigns multiple roles and the screen shows their effective permissions', function () {
    prepareAccessAccount($this);
    $target = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $ids = Role::query()->whereIn('code', ['libreria', 'contador'])->pluck('id')->map(fn ($id): string => (string) $id)->all();
    Livewire::test('pages::administracion.usuarios')->call('selectUser', $target->id)
        ->set('selectedRoles', $ids)->call('saveRoles')->assertHasNoErrors()
        ->assertSee('libreria.ventas.crear')->assertSee('contaduria.productos.editar')
        ->assertDontSeeHtml('<script>alert(1)</script>');
    expect($target->roles()->count())->toBe(2);
});

test('the last admin cannot lose the role or delete their account', function () {
    $user = prepareAccessAccount($this);
    Livewire::test('pages::administracion.usuarios')->call('selectUser', $user->id)
        ->set('selectedRoles', [])->call('saveRoles')->assertHasErrors('roles');
    Livewire::test('pages::settings.delete-user-modal')->call('deleteUser')->assertHasErrors('roles');
    expect($user->fresh())->not->toBeNull()->and($user->hasRole('admin'))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('an admin can be removed once another admin remains', function () {
    $user = prepareAccessAccount($this);
    $other = User::factory()->create();
    $other->roles()->attach(Role::query()->where('code', 'admin')->sole());
    app(AccessControlService::class)->assignRoles($user, []);
    expect($user->hasRole('admin'))->toBeFalse()->and($other->hasRole('admin'))->toBeTrue();
    $user->delete();
    expect($user->fresh())->toBeNull();
});

test('the bootstrap command adds admin without removing existing roles and verifies the employee', function () {
    $user = prepareAccessAccount($this, ['libreria']);
    $this->artisan('access:grant-admin', ['email' => strtoupper($user->email)])->assertSuccessful();
    $this->artisan('access:grant-admin', ['email' => $user->email])->assertSuccessful();
    expect($user->roles()->count())->toBe(2)->and($user->hasRole('admin'))->toBeTrue();
    $outsider = User::factory()->create(['email' => 'externo@example.test']);
    $this->artisan('access:grant-admin', ['email' => $outsider->email])->assertFailed();
    expect($outsider->roles()->count())->toBe(0);
    $this->artisan('access:grant-admin', ['email' => 'inexistente@example.test'])->assertFailed();
});

test('administration pages render and revoked roles block the next livewire action', function () {
    $user = prepareAccessAccount($this);
    $this->get(route('administracion.roles'))->assertOk()->assertSee('Roles y permisos');
    $this->get(route('administracion.usuarios'))->assertOk()->assertSee('Usuarios y accesos');
    $component = Livewire::test('pages::administracion.roles');
    $user->roles()->detach();
    $component->call('createRole')->assertForbidden();
});
