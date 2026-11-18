<?php

namespace App\Services\Contaduria;

use App\Enum\OrigenVenta;
use App\Models\Contaduria\CatalogoRecibo;
use App\Models\Contaduria\Cuenta;
use App\Models\Contaduria\Producto;
use App\Models\Contaduria\Proveedor;
use App\Models\Libreria\Cliente;
use App\TamanoEmpresa;
use App\TipoCliente;

/** @phpstan-import-type CatalogField from CatalogDefinition */
class CatalogRegistry
{
    /** @return array<string, CatalogDefinition> */
    public function all(): array
    {
        return [
            'cuentas' => new CatalogDefinition(
                'cuentas', 'Catálogo contable', 'Cuentas y clasificación contable.', 'calculator', Cuenta::class,
                'descripcion', ['cuenta', 'descripcion', 'cuenta2'],
                ['cuenta' => 'Cuenta', 'descripcion' => 'Descripción', 'nivel' => 'Nivel', 'terminal' => 'Terminal', 'tipo_saldo' => 'Tipo de saldo'],
                [
                    'cuenta' => $this->text('Cuenta', 32, true) + ['unique' => true],
                    'descripcion' => $this->text('Descripción', 100, true),
                    'nivel' => $this->integer('Nivel') + ['group' => 'Clasificación'],
                    'terminal' => $this->choice('Terminal', ['T' => 'Sí (T)', 'F' => 'No (F)']) + ['group' => 'Clasificación'],
                    'tipo_saldo' => $this->choice('Tipo de saldo', ['D' => 'Deudor', 'A' => 'Acreedor']) + ['group' => 'Clasificación'],
                    'cuenta2' => $this->text('Cuenta alternativa', 255),
                ],
            ),
            'productos' => new CatalogDefinition(
                'productos', 'Catálogo de productos', 'Productos, servicios, precios y cuentas asociadas.', 'cube', Producto::class,
                'descrip', ['codigo', 'descrip', 'abreviado', 'grupo'],
                ['codigo' => 'Código', 'descrip' => 'Descripción', 'p_venta' => 'Precio de venta', 'tipo_prod' => 'Tipo'],
                [
                    'codigo' => $this->text('Código', 64, true) + ['unique' => true],
                    'descrip' => $this->text('Descripción', 1000, true),
                    'abreviado' => $this->text('Nombre abreviado', 64),
                    'grupo' => $this->choice('Origen', array_column(OrigenVenta::cases(), 'value', 'value'), 32) + ['group' => 'Clasificación'],
                    'linea' => $this->text('Línea', 32) + ['group' => 'Clasificación'],
                    'unidad' => $this->text('Unidad', 32) + ['group' => 'Clasificación'],
                    'tipo_prod' => $this->choice('Tipo de producto', ['S' => 'Servicio', 'B' => 'Bien']) + ['group' => 'Clasificación'],
                    'tipo_prec' => $this->choice('Tipo de precio', ['F' => 'F', 'V' => 'V']) + ['group' => 'Precios y descuentos'],
                    'procedencia' => $this->choice('Procedencia', ['propio' => 'Propio', 'consigna' => 'Consignación']) + ['group' => 'Clasificación'],
                    'p_costo' => $this->decimal('Precio de costo'),
                    'p_venta' => $this->decimal('Precio de venta sin IVA', 5),
                    'cos_consig' => $this->decimal('Costo en consignación'),
                    'iva2' => $this->choice('Clasificación de IVA', ['A' => 'Afecta', 'E' => 'Exenta']) + ['group' => 'Precios y descuentos'],
                    'descuento' => [...$this->boolean('Permite descuento'), 'group' => 'Precios y descuentos'],
                    'yellow_w' => [...$this->boolean('Yellow W'), 'group' => 'Precios y descuentos'],
                    'yellow_w_descuento' => $this->text('Descuento Yellow W', 255) + ['group' => 'Precios y descuentos'],
                    'c_inv' => $this->account('Cuenta de inventario'),
                    'c_gas' => $this->account('Cuenta de gasto'),
                    'c_ing' => $this->account('Cuenta de ingreso'),
                    'ex_minima' => $this->integer('Existencia mínima') + ['group' => 'Inventario y ubicación'],
                    'ex_maxima' => $this->integer('Existencia máxima') + ['group' => 'Inventario y ubicación'],
                    'ubicacion' => $this->text('Ubicación', 32) + ['group' => 'Inventario y ubicación'],
                    'ubica2' => $this->text('Ubicación alternativa', 32) + ['group' => 'Inventario y ubicación'],
                    'cod_prov' => $this->text('Cuenta contable del proveedor', 64) + ['lookup' => 'supplierAccounts', 'group' => 'Contabilidad'],
                    'libreria' => $this->choice('Disponible en librería', ['S' => 'Sí', 'N' => 'No']) + ['group' => 'Clasificación'],
                ],
            ),
            'recibos' => new CatalogDefinition(
                'recibos', 'Catálogo de recibos', 'Conceptos de recibo, precios y cuentas de abono.', 'receipt-percent', CatalogoRecibo::class,
                'nombre_recibo', ['nombre_recibo', 'cuenta_abono'],
                ['nombre_recibo' => 'Concepto', 'cuenta_abono' => 'Cuenta de abono', 'precio' => 'Precio'],
                ['nombre_recibo' => $this->text('Nombre del recibo', 255, true), 'cuenta_abono' => $this->account('Cuenta de abono', 255), 'precio' => $this->integer('Precio')],
            ),
            'clientes' => new CatalogDefinition(
                'clientes', 'Catálogo de clientes', 'Identificación, contacto y datos de facturación.', 'users', Cliente::class,
                'nombre', ['nombre', 'dui', 'nit', 'email', 'cuenta_contable'],
                ['nombre' => 'Nombre', 'dui' => 'DUI', 'nit' => 'NIT', 'email' => 'Correo', 'cuenta_contable' => 'Cuenta contable'],
                [
                    'nombre' => $this->text('Nombre', 255, true),
                    'dui' => $this->text('DUI', 255),
                    'nit' => $this->text('NIT', 255),
                    'registro_fiscal' => $this->text('Registro fiscal', 255),
                    'email' => $this->email('Correo electrónico', 255, true) + ['unique' => true, 'group' => 'Contacto'],
                    'telefono_1' => $this->text('Teléfono principal', 255, true) + ['group' => 'Contacto'],
                    'telefono_2' => $this->text('Teléfono secundario', 255) + ['group' => 'Contacto'],
                    'direccion' => $this->text('Dirección', 255, true) + ['group' => 'Ubicación'],
                    'nacionalidad' => $this->text('Nacionalidad', 255, true) + ['lookup' => 'nationalityNames', 'group' => 'Ubicación'],
                    'departamento' => $this->text('Departamento', 255, true) + ['lookup' => 'departments', 'group' => 'Ubicación'],
                    'distrito' => $this->text('Distrito', 255, true) + ['lookup' => 'districts', 'group' => 'Ubicación'],
                    'municipio' => $this->text('Municipio', 255, true) + ['lookup' => 'municipalities', 'group' => 'Ubicación'],
                    'actividad_economica' => $this->text('Actividad económica', 255) + ['lookup' => 'economicActivities'],
                    'cuenta_contable' => $this->account('Cuenta contable', 255, true),
                    'domiciliado' => $this->boolean('Domiciliado', true),
                    'credito' => $this->boolean('Permite crédito', true),
                    'tipo_cliente' => $this->choice('Tipo de cliente', [
                        TipoCliente::NORMAL->value => 'Normal', TipoCliente::SUJETO_E->value => 'Sujeto excluido', TipoCliente::EMPRESA->value => 'Empresa',
                    ], 255, true),
                    'tamano' => $this->choice('Tamaño de empresa', [
                        TamanoEmpresa::PEQUE->value => 'Pequeña', TamanoEmpresa::MEDIANA->value => 'Mediana', TamanoEmpresa::GRANDE->value => 'Grande',
                    ], 255),
                ],
            ),
            'proveedores' => new CatalogDefinition(
                'proveedores', 'Catálogo de proveedores', 'Proveedores, contactos e información fiscal.', 'truck', Proveedor::class,
                'nombre_proveedor', ['nombre_proveedor', 'n_comercial', 'nit', 'dui', 'correo_electronico', 'cuenta'],
                ['nombre_proveedor' => 'Nombre', 'n_comercial' => 'Nombre comercial', 'telefono' => 'Teléfono', 'correo_electronico' => 'Correo', 'cuenta' => 'Cuenta contable'],
                [
                    'nombre_proveedor' => $this->text('Nombre del proveedor', 64, true),
                    'n_comercial' => $this->text('Nombre comercial', 64),
                    'contacto' => $this->text('Contacto', 64) + ['group' => 'Contacto'],
                    'id_nacionalidad' => $this->lookup('Nacionalidad', 'nationalities', true),
                    'domiciliado' => $this->choice('Domiciliado', ['S' => 'Sí', 'N' => 'No']),
                    'direccion' => $this->text('Dirección', 100) + ['group' => 'Ubicación'],
                    'id_departamento' => $this->text('Departamento', 6) + ['lookup' => 'departments', 'group' => 'Ubicación'],
                    'codigo_distrito' => $this->integer('Distrito') + ['lookup' => 'districts', 'group' => 'Ubicación'],
                    'municipio' => $this->text('Municipio', 32) + ['lookup' => 'municipalities', 'group' => 'Ubicación'],
                    'codigo_act_economica' => $this->integer('Actividad económica') + ['lookup' => 'economicActivities'],
                    'telefono' => $this->text('Teléfono principal', 32) + ['group' => 'Contacto'],
                    'telefono2' => $this->text('Teléfono secundario', 32) + ['group' => 'Contacto'],
                    'nit' => $this->text('NIT', 32),
                    'dui' => $this->text('DUI', 32),
                    'r_fiscal' => $this->text('Registro fiscal', 64),
                    'correo_electronico' => $this->email('Correo electrónico', 64) + ['unique' => true, 'group' => 'Contacto'],
                    'giro' => $this->text('Giro', 64),
                    'cuenta' => $this->account('Cuenta contable', 32),
                    'tamaño' => $this->choice('Tamaño de empresa', ['P' => 'Pequeña', 'M' => 'Mediana', 'G' => 'Grande']),
                ],
            ),
        ];
    }

    public function get(string $slug): CatalogDefinition
    {
        $catalog = $this->all()[$slug] ?? null;
        abort_if($catalog === null, 404);

        return $catalog;
    }

    /** @return CatalogField */
    private function text(string $label, int $max, bool $required = false): array
    {
        return ['label' => $label, 'type' => 'text', 'max' => $max, 'required' => $required];
    }

    /** @return CatalogField */
    private function integer(string $label): array
    {
        return ['label' => $label, 'type' => 'integer'];
    }

    /** @return CatalogField */
    private function decimal(string $label, int $digits = 20): array
    {
        return ['label' => $label, 'type' => 'decimal', 'digits' => $digits, 'group' => 'Precios y descuentos'];
    }

    /** @return CatalogField */
    private function boolean(string $label, bool $required = false): array
    {
        return ['label' => $label, 'type' => 'boolean', 'group' => 'Clasificación', 'required' => $required];
    }

    /** @param array<string|int, string> $choices
     * @return CatalogField
     */
    private function choice(string $label, array $choices, int $max = 1, bool $required = false): array
    {
        return ['label' => $label, 'type' => 'select', 'choices' => $choices, 'max' => $max, 'required' => $required];
    }

    /** @return CatalogField */
    private function account(string $label, int $max = 64, bool $required = false): array
    {
        return $this->text($label, $max, $required) + ['lookup' => 'accounts', 'suggestions' => 'accounts', 'group' => 'Contabilidad'];
    }

    /** @return CatalogField */
    private function lookup(string $label, string $source, bool $required = false): array
    {
        return ['label' => $label, 'type' => 'integer', 'lookup' => $source, 'required' => $required];
    }

    /** @return CatalogField */
    private function email(string $label, int $max, bool $required = false): array
    {
        return ['label' => $label, 'type' => 'email', 'max' => $max, 'required' => $required];
    }
}
