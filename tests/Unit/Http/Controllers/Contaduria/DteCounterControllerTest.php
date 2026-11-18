<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use App\Services\Dte\DteCounterService;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/../../../../Fixtures/AccessDatabase.php';

uses(TestCase::class);

class TemporaryDteCounters extends DteCounterService
{
    protected function sequence(string $type): string
    {
        self::sequenceName($type);

        return 'pg_temp.counter_test_'.config('services.factec.environment').'_'.$type;
    }
}

beforeEach(function () {
    config(['services.factec.environment' => '00']);
    $this->withoutVite();
});

function authenticateCounterEditor(TestCase $test): void
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $connection = DB::getDefaultConnection();
    config(['database.connections.access_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('access_testing');
    DB::setDefaultConnection('access_testing');
    $user = User::factory()->make(['id' => 700, 'name' => 'Contaduría', 'email' => 'contador@example.test']);
    $user->setConnection('access_testing');
    grantTestRoles($user, ['contador']);
    DB::setDefaultConnection($connection);
    $test->actingAs($user);
}

function prepareTemporaryDteCounters(TestCase $test): DteCounterService
{
    $database = getenv('COUNTER_PG_TEST_DATABASE');
    if (! is_string($database) || $database === '') {
        $test->markTestSkipped('Configura COUNTER_PG_TEST_DATABASE para probar secuencias temporales reales de PostgreSQL.');
    }
    config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $database, 'database.connections.pgsql.url' => null]);
    DB::purge('pgsql');
    foreach (['00_01' => 40, '00_03' => 50, '01_01' => 60, '01_03' => 70] as $suffix => $start) {
        DB::statement('CREATE TEMP SEQUENCE counter_test_'.$suffix.' START WITH '.$start);
    }
    authenticateCounterEditor($test);
    $counters = new TemporaryDteCounters;
    app()->instance(DteCounterService::class, $counters);

    return $counters;
}

afterEach(function () {
    DB::disconnect('pgsql');
});

test('counter administration requires authentication', function () {
    $this->get(route('contaduria.contadores.index'))->assertRedirect(route('login'));
    $this->put(route('contaduria.contadores.update', '01'), [])->assertRedirect(route('login'));
});

test('only supported document types can reach counter updates', function () {
    authenticateCounterEditor($this);

    $this->put('/contaduria/contadores-dte/05', [])->assertNotFound();
});

test('counter forms reject invalid values before accessing PostgreSQL', function (array $changes, array $fields) {
    authenticateCounterEditor($this);
    $payload = array_replace(['environment' => '00', 'next' => '100', 'expected' => '40', 'confirmed' => '1'], $changes);

    $this->putJson(route('contaduria.contadores.update', '01'), $payload)->assertUnprocessable()->assertJsonValidationErrors($fields);
})->with([
    'missing values' => [['environment' => null, 'next' => null, 'expected' => null, 'confirmed' => null], ['environment', 'next', 'expected', 'confirmed']],
    'zero' => [['next' => '0'], ['next']],
    'negative' => [['next' => '-1'], ['next']],
    'fraction' => [['next' => '1.5'], ['next']],
    'too many digits' => [['next' => '1000000000000000'], ['next']],
    'text' => [['next' => 'DROP SEQUENCE'], ['next']],
    'different environment' => [['environment' => '01'], ['environment']],
    'missing confirmation' => [['confirmed' => null], ['confirmed']],
    'invalid snapshot' => [['expected' => 'not-a-number'], ['expected']],
]);

test('viewing the counters shows the next numbers without consuming them', function () {
    prepareTemporaryDteCounters($this);

    $this->get(route('contaduria.contadores.index'))->assertOk()->assertSee('Pruebas · 00')
        ->assertSee('000000000000040')->assertSee('000000000000050')->assertSee('counter-help');

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(40);
});

test('an update changes exactly the next number and leaves other documents and environments untouched', function (string $next) {
    prepareTemporaryDteCounters($this);

    $this->put(route('contaduria.contadores.update', '01'), ['environment' => '00', 'expected' => '40', 'next' => $next, 'confirmed' => '1'])
        ->assertRedirect(route('contaduria.contadores.index'))->assertSessionHasNoErrors()->assertSessionHas('status');
    $this->get(route('contaduria.contadores.index'))->assertOk()->assertSee('counter-success')->assertSee('000000000000075');

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(75);
    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(76);
    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_03') AS value")->value)->toBe(50);
    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_01_01') AS value")->value)->toBe(60);
})->with(['75', '000000000000075']);

test('a lower counter is rejected and the user receives a popup', function () {
    prepareTemporaryDteCounters($this);

    $this->from(route('contaduria.contadores.index'))->followingRedirects()->put(route('contaduria.contadores.update', '01'), ['environment' => '00', 'expected' => '40', 'next' => '39', 'confirmed' => '1'])
        ->assertOk()->assertSee('data-modal="counter-errors"', false)->assertSee('No puedes retroceder el contador. El siguiente correlativo disponible es 40.');

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(40);
});

test('a number consumed after opening the form prevents an outdated update', function () {
    prepareTemporaryDteCounters($this);
    DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01')");

    $this->putJson(route('contaduria.contadores.update', '01'), ['environment' => '00', 'expected' => '40', 'next' => '75', 'confirmed' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['next' => 'El contador cambió mientras editabas. Recarga la página y revisa el siguiente correlativo.']);

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(41);
});

test('the maximum fifteen digit counter can be assigned', function () {
    prepareTemporaryDteCounters($this);

    $this->putJson(route('contaduria.contadores.update', '01'), ['environment' => '00', 'expected' => '40', 'next' => '999999999999999', 'confirmed' => '1'])
        ->assertRedirect(route('contaduria.contadores.index'))->assertSessionHasNoErrors();

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(999999999999999);
});

test('the active production environment edits only its own counter', function () {
    prepareTemporaryDteCounters($this);
    config(['services.factec.environment' => '01']);

    $this->put(route('contaduria.contadores.update', '03'), ['environment' => '01', 'expected' => '70', 'next' => '90', 'confirmed' => '1'])
        ->assertRedirect(route('contaduria.contadores.index'))->assertSessionHasNoErrors();

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_01_03') AS value")->value)->toBe(90);
    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_03') AS value")->value)->toBe(50);
});

test('a rollback also rolls back a counter modification', function () {
    $counters = prepareTemporaryDteCounters($this);
    DB::beginTransaction();
    try {
        $counters->update('01', 75, 40);
    } finally {
        DB::rollBack();
    }

    expect(DB::selectOne("SELECT nextval('pg_temp.counter_test_00_01') AS value")->value)->toBe(40);
});

test('a counter with cached numbers is not editable', function () {
    prepareTemporaryDteCounters($this);
    DB::statement('ALTER SEQUENCE pg_temp.counter_test_00_01 CACHE 2');

    $this->putJson(route('contaduria.contadores.update', '01'), ['environment' => '00', 'expected' => '40', 'next' => '75', 'confirmed' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['next' => 'El contador debe incrementar de uno en uno, sin caché adicional y sin ciclos. Revisa su configuración.']);
});
