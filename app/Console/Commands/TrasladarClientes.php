<?php

namespace App\Console\Commands;

use App\Models\Libreria\Cliente;
use App\TamanoEmpresa;
use App\TipoCliente;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use stdClass;
use Throwable;

#[Signature('clientes:trasladar
    {--simular : Validar y generar el listado sin guardar clientes}
    {--formatear-existentes : Reformatear únicamente los clientes de destino, sin importar ni borrar registros}
    {--lote=200 : Registros a leer por lote, entre 1 y 1000}')]
#[Description('Normaliza y copia public.clientes de puntoventa a libreria.clientes de la conexión principal')]
class TrasladarClientes extends Command
{
    public function handle(): int
    {
        $lote = filter_var($this->option('lote'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);

        if ($lote === false) {
            $this->error('--lote debe ser un entero entre 1 y 1000.');

            return self::FAILURE;
        }

        if ($this->option('formatear-existentes')) {
            return $this->formatearExistentes($lote);
        }

        $simular = (bool) $this->option('simular');
        $destino = null;
        $transaccionAbierta = false;
        $archivo = null;
        $ruta = null;
        $aprobados = 0;
        $omitidos = 0;

        try {
            $origen = DB::connection('puntoventa');
            $destino = DB::connection();
            $tabla = (new Cliente)->getTable();

            foreach (['public.clientes', 'public.nacionalidades', 'public.distritos'] as $tablaOrigen) {
                if (! $origen->getSchemaBuilder()->hasTable($tablaOrigen)) {
                    throw new RuntimeException("No existe la tabla de origen {$tablaOrigen}.");
                }
            }

            if (! $destino->getSchemaBuilder()->hasTable($tabla)) {
                throw new RuntimeException("No existe la tabla de destino {$tabla}.");
            }

            [$ruta, $archivo] = $this->crearListadoOmitidos();

            $this->line('Origen: puntoventa / '.$origen->getDatabaseName().' / public.clientes');
            $this->line('Destino: '.config('database.default').' / '.$destino->getDatabaseName().' / '.$tabla);

            if (! $simular) {
                $destino->beginTransaction();
                $transaccionAbierta = true;

                if ($destino->getDriverName() === 'pgsql') {
                    $destino->statement('LOCK TABLE '.$destino->getQueryGrammar()->wrapTable($tabla).' IN SHARE ROW EXCLUSIVE MODE');
                }
            }

            $nacionalidades = $origen->table('public.nacionalidades')->pluck('nacionalidad', 'id_nacionalidad');
            $distritos = $origen->table('public.distritos')
                ->get(['distrito_id', 'departamento_codigo', 'municipio_codigo'])
                ->keyBy('distrito_id');

            /** @var array<string, array<string, int>> $identificadores */
            $identificadores = [];

            foreach ($destino->table($tabla)->select(['id', 'email', 'nit', 'dui', 'registro_fiscal'])->lazyById($lote) as $existente) {
                $this->recordarIdentificadores((array) $existente, $identificadores);
            }

            $ultimoId = $origen->table('public.clientes')->max('id');
            $fecha = now()->toDateTimeString();

            if ($ultimoId !== null) {
                $origen->table('public.clientes')->where('id', '<=', $ultimoId)
                    ->chunkById($lote, function (Collection $clientes) use ($destino, $tabla, $nacionalidades, $distritos, $fecha, $simular, $archivo, &$identificadores, &$aprobados, &$omitidos): void {
                        foreach ($clientes as $cliente) {
                            $datos = $this->normalizar($cliente, $nacionalidades->get($cliente->id_nacionalidad), $distritos->get($cliente->id_distrito));
                            $errores = $this->validar($datos);
                            $duplicado = $this->buscarDuplicado($datos, $identificadores);

                            if ($duplicado !== null) {
                                $errores[] = $duplicado;
                            }

                            if ($errores !== []) {
                                $this->registrarOmitido($archivo, $cliente, $errores);
                                $omitidos++;

                                continue;
                            }

                            if (! $simular) {
                                try {
                                    $destino->transaction(fn () => $destino->table($tabla)->insert([
                                        ...$datos,
                                        'created_at' => $fecha,
                                        'updated_at' => $fecha,
                                    ]));
                                } catch (UniqueConstraintViolationException) {
                                    $this->registrarOmitido($archivo, $cliente, ['Conflicto con una restricción de unicidad del destino.']);
                                    $omitidos++;

                                    continue;
                                }
                            }

                            $this->recordarIdentificadores($datos, $identificadores);
                            $aprobados++;
                        }
                    });
            }

            if (! $simular) {
                if ($aprobados > 0 && $destino->getDriverName() === 'pgsql') {
                    $this->ajustarSecuencia($destino, $tabla);
                }

                $destino->commit();
                $transaccionAbierta = false;
            }
        } catch (Throwable $exception) {
            if ($transaccionAbierta && $destino !== null) {
                $destino->rollBack();
            }

            report($exception);
            $this->error('No se completó el traslado. Se revirtieron las inserciones de este intento; revisa el registro de errores.');

            if ($ruta !== null) {
                $this->line('Listado parcial de omitidos: '.$ruta);
            }

            return self::FAILURE;
        } finally {
            if (is_resource($archivo)) {
                fclose($archivo);
            }
        }

        $this->info($simular ? 'Simulación completada. No se guardaron clientes.' : 'Traslado completado.');
        $this->line('Válidos: '.$aprobados);
        $this->line('Omitidos: '.$omitidos);
        $this->line('Listado de omitidos: '.$ruta);

        return self::SUCCESS;
    }

    private function formatearExistentes(int $lote): int
    {
        $simular = (bool) $this->option('simular');
        $archivo = null;
        $ruta = null;
        $actualizados = 0;
        $sinCambios = 0;
        $omitidos = 0;

        try {
            $destino = DB::connection();
            $tabla = (new Cliente)->getTable();

            if (! $destino->getSchemaBuilder()->hasTable($tabla)) {
                throw new RuntimeException("No existe la tabla de destino {$tabla}.");
            }

            [$ruta, $archivo] = $this->crearListadoOmitidos('formato_');
            $this->line('Destino: '.config('database.default').' / '.$destino->getDatabaseName().' / '.$tabla);

            $procesar = function () use ($destino, $tabla, $lote, $simular, $archivo, &$actualizados, &$sinCambios, &$omitidos): void {
                $fecha = now()->toDateTimeString();

                $destino->table($tabla)->chunkById($lote, function (Collection $clientes) use ($destino, $tabla, $simular, $archivo, $fecha, &$actualizados, &$sinCambios, &$omitidos): void {
                    foreach ($clientes as $cliente) {
                        $datos = [
                            'telefono_1' => $this->telefono($cliente->telefono_1),
                            'telefono_2' => $this->telefono($cliente->telefono_2, opcional: true),
                            'nit' => $this->nit($cliente->nit),
                            'dui' => $this->dui($cliente->dui),
                            'registro_fiscal' => $this->documento($cliente->registro_fiscal),
                        ];
                        $errores = array_values(Validator::make($datos, $this->reglasFormato(), $this->mensajesValidacion())->errors()->all());

                        if ($errores !== []) {
                            $this->registrarOmitido($archivo, $cliente, $errores, destino: true);
                            $omitidos++;

                            continue;
                        }

                        $cambios = array_filter($datos, fn (mixed $valor, string $campo): bool => $valor !== $cliente->{$campo}, ARRAY_FILTER_USE_BOTH);

                        if ($cambios === []) {
                            $sinCambios++;

                            continue;
                        }

                        if (! $simular) {
                            try {
                                $destino->transaction(fn () => $destino->table($tabla)->where('id', $cliente->id)->update([
                                    ...$cambios,
                                    'updated_at' => $fecha,
                                ]));
                            } catch (UniqueConstraintViolationException) {
                                $this->registrarOmitido($archivo, $cliente, ['Conflicto con una restricción de unicidad del destino.'], destino: true);
                                $omitidos++;

                                continue;
                            }
                        }

                        $actualizados++;
                    }
                });
            };

            if ($simular) {
                $procesar();
            } else {
                $destino->transaction(function () use ($destino, $tabla, $procesar): void {
                    if ($destino->getDriverName() === 'pgsql') {
                        $destino->statement('LOCK TABLE '.$destino->getQueryGrammar()->wrapTable($tabla).' IN SHARE ROW EXCLUSIVE MODE');
                    }

                    $procesar();
                });
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se completó el formato. Se revirtieron las actualizaciones de este intento; revisa el registro de errores.');

            if ($ruta !== null) {
                $this->line('Listado parcial de omitidos: '.$ruta);
            }

            return self::FAILURE;
        } finally {
            if (is_resource($archivo)) {
                fclose($archivo);
            }
        }

        $this->info($simular ? 'Simulación de formato completada. No se modificaron clientes.' : 'Formato de clientes existentes completado.');
        $this->line(($simular ? 'Por actualizar: ' : 'Actualizados: ').$actualizados);
        $this->line('Sin cambios: '.$sinCambios);
        $this->line('Omitidos: '.$omitidos);
        $this->line('Listado de omitidos: '.$ruta);

        return self::SUCCESS;
    }

    /** @return array{string, resource} */
    private function crearListadoOmitidos(string $prefijo = ''): array
    {
        $directorio = storage_path('app/private/traslados/clientes');
        File::ensureDirectoryExists($directorio);
        $ruta = $directorio.'/'.$prefijo.now()->format('Ymd_His').'_'.Str::uuid().'.jsonl';
        $archivo = fopen($ruta, 'xb');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el listado de omitidos.');
        }

        return [$ruta, $archivo];
    }

    /** @return array<string, mixed> */
    private function normalizar(stdClass $cliente, ?string $nacionalidad, ?stdClass $distrito): array
    {
        $actividad = $this->texto($cliente->actividad);
        $registro = $this->documento($cliente->r_fiscal);

        return [
            'id' => $cliente->id,
            'nombre' => $this->texto($cliente->nombre),
            'direccion' => $this->texto($cliente->direccion),
            'telefono_1' => $this->telefono($cliente->telefono1),
            'telefono_2' => $this->telefono($cliente->telefono2, opcional: true),
            'email' => $this->correo($cliente->email),
            'registro_fiscal' => $registro,
            'nit' => $this->nit($cliente->nit),
            'dui' => $this->dui($cliente->dui),
            'nacionalidad' => $this->texto($nacionalidad) ?? 'El Salvador',
            'departamento' => $this->texto($distrito?->departamento_codigo),
            'municipio' => $this->texto($distrito?->municipio_codigo),
            'distrito' => $distrito === null ? null : (string) $distrito->distrito_id,
            'domiciliado' => true,
            'credito' => Str::upper($this->texto($cliente->cliente) ?? 'N') === 'S',
            'tamano' => ($tamano = $this->texto($cliente->{'tamaño'})) === null ? null : Str::upper($tamano),
            'tipo_cliente' => $actividad === null && $registro === null ? TipoCliente::SUJETO_E->value : TipoCliente::NORMAL->value,
            'actividad_economica' => $actividad,
            'cuenta_contable' => $this->texto($cliente->cta_cte),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    private function validar(array $datos): array
    {
        return array_values(Validator::make($datos, [
            ...$this->reglasFormato(),
            'id' => ['required', 'integer', 'min:1'],
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'nacionalidad' => ['required', 'string', 'max:255'],
            'departamento' => ['required', 'string', 'max:255'],
            'municipio' => ['required', 'string', 'max:255'],
            'distrito' => ['required', 'string', 'max:255'],
            'domiciliado' => ['required', 'boolean'],
            'credito' => ['required', 'boolean'],
            'tamano' => ['nullable', Rule::enum(TamanoEmpresa::class)],
            'tipo_cliente' => ['required', Rule::enum(TipoCliente::class)],
            'actividad_economica' => ['nullable', 'string', 'max:255'],
            'cuenta_contable' => ['required', 'string', 'max:255'],
        ], $this->mensajesValidacion())->errors()->all());
    }

    /** @return array<string, list<string>> */
    private function reglasFormato(): array
    {
        $telefono = ['string', 'max:255', 'regex:/^[0-9]{4}-[0-9]{4}$/'];
        $documentoFormateado = ['nullable', 'string', 'max:255', 'not_regex:/^[0-]+$/'];

        return [
            'telefono_1' => ['required', ...$telefono],
            'telefono_2' => ['nullable', ...$telefono],
            'registro_fiscal' => ['nullable', 'string', 'max:255', 'regex:/^[0-9]+$/', 'not_regex:/^0+$/'],
            'nit' => [...$documentoFormateado, 'regex:/^(?:[0-9]{4}-[0-9]{6}-[0-9]{3}-[0-9]|[0-9]{8}-[0-9])$/'],
            'dui' => [...$documentoFormateado, 'regex:/^[0-9]{8}-[0-9]$/'],
        ];
    }

    /** @return array<string, string> */
    private function mensajesValidacion(): array
    {
        return [
            'required' => 'Falta :attribute o no se encontró en su catálogo.',
            'email' => ':attribute no tiene un formato de correo válido.',
            'regex' => ':attribute tiene un formato no admitido.',
            'not_regex' => ':attribute no puede contener únicamente ceros.',
        ];
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = preg_replace('/\s+/u', ' ', trim((string) $valor));

        return $texto === '' ? null : $texto;
    }

    private function correo(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === null ? null : Str::lower($texto);
    }

    private function documento(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        if ($texto === null) {
            return null;
        }

        $documento = Str::upper(preg_replace('/[\s.-]+/u', '', $texto) ?? $texto);

        return $documento === '' ? $texto : $documento;
    }

    private function telefono(mixed $valor, bool $opcional = false): ?string
    {
        $texto = $this->texto($valor);

        if ($texto === null || ($opcional && $texto === '0')) {
            return null;
        }

        $numero = preg_replace('/[\s().-]+/u', '', $texto) ?? $texto;

        return preg_replace('/^(?:\+?503)?([0-9]{4})([0-9]{4})$/', '$1-$2', $numero);
    }

    private function dui(mixed $valor): ?string
    {
        $documento = $this->documento($valor);

        return $documento === null ? null : preg_replace('/^([0-9]{8})([0-9])$/', '$1-$2', $documento);
    }

    private function nit(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        if ($texto === null || preg_match('/^[.\s]+$/u', $texto) === 1) {
            return null;
        }

        $documento = $this->documento($texto);

        if ($documento !== null && preg_match('/^([0-9]{4})([0-9]{6})([0-9]{3})([0-9])$/', $documento, $partes) === 1) {
            return $partes[1].'-'.$partes[2].'-'.$partes[3].'-'.$partes[4];
        }

        return $this->dui($documento);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, string>
     */
    private function claves(array $datos): array
    {
        return array_filter([
            'id' => (string) $datos['id'],
            'email' => $this->correo($datos['email']),
            'nit' => $this->documento($datos['nit']),
            'dui' => $this->documento($datos['dui']),
            'registro_fiscal' => $this->documento($datos['registro_fiscal']),
        ], fn (?string $valor): bool => $valor !== null && $valor !== '');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, array<string, int>>  $identificadores
     */
    private function buscarDuplicado(array $datos, array $identificadores): ?string
    {
        foreach ($this->claves($datos) as $campo => $valor) {
            if (isset($identificadores[$campo][$valor])) {
                return "Duplicado por {$campo}; ya corresponde al cliente {$identificadores[$campo][$valor]}.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, array<string, int>>  $identificadores
     */
    private function recordarIdentificadores(array $datos, array &$identificadores): void
    {
        foreach ($this->claves($datos) as $campo => $valor) {
            $identificadores[$campo][$valor] ??= (int) $datos['id'];
        }
    }

    /**
     * @param  resource  $archivo
     * @param  list<string>  $errores
     */
    private function registrarOmitido($archivo, stdClass $cliente, array $errores, bool $destino = false): void
    {
        $linea = json_encode([
            $destino ? 'id_destino' : 'id_origen' => $cliente->id,
            'motivos' => $errores,
            $destino ? 'datos_destino' : 'datos_origen' => $cliente,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

        if (fwrite($archivo, $linea) !== strlen($linea)) {
            throw new RuntimeException('No se pudo escribir el listado de omitidos.');
        }
    }

    private function ajustarSecuencia(Connection $destino, string $tabla): void
    {
        $secuencia = $destino->selectOne('SELECT pg_get_serial_sequence(?, ?) AS nombre', [$tabla, 'id'])->nombre;

        if ($secuencia === null) {
            throw new RuntimeException('No se encontró la secuencia del identificador de clientes.');
        }

        $ultimoValor = (int) $destino->table($secuencia)->value('last_value');
        $maximoId = (int) $destino->table($tabla)->max('id');
        $destino->selectOne('SELECT setval(CAST(? AS regclass), ?, true)', [$secuencia, max($ultimoValor, $maximoId)]);
    }
}
