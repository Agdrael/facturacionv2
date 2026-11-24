<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\AccessControlService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptControlSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(AccessControlService $access): void
    {
        DB::transaction(function () use ($access): void {
            $access->synchronize();
            $first = DB::table('libreria.receipt_settings')->insertOrIgnore(['key' => 'initialized-v1', 'value' => json_encode(true)]);
            if (! $first) {
                return;
            }
            foreach (config('receipts.role_permissions') as $code => $actions) {
                $role = Role::query()->where('code', $code)->sole();
                $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('code', array_map(fn ($action) => 'libreria.recibos.'.$action, $actions))->pluck('id')->all());
            }
            foreach (DB::table('public.catalogo_recibos')->get(['id', 'nombre_recibo', 'cuenta_abono']) as $concept) {
                $name = Str::upper(Str::ascii(trim($concept->nombre_recibo)));
                $kind = trim((string) $concept->cuenta_abono) === '00' ? 'deuda' : (str_starts_with($name, 'FOTO') ? 'fotografia' : (str_starts_with($name, 'PAGO EN ANTICIPO') ? 'anticipo' : (str_starts_with($name, 'PAGO CXC') ? 'deuda' : 'normal')));
                DB::table('libreria.receipt_concept_types')->insertOrIgnore(['concept_id' => $concept->id, 'kind' => $kind]);
            }
        });
    }
}
