<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('LOCK TABLE public.paquetes_productos IN ACCESS EXCLUSIVE MODE');
        $owners = $this->owners(true);
        Schema::table('public.paquetes_productos', function (Blueprint $table) {
            $table->dropForeign('paquetes_productos_id_usuario_fkey');
            $table->bigInteger('id_usuario')->change();
        });
        foreach ($owners as $packageId => $userId) {
            DB::table('public.paquetes_productos')->where('id_paquete', $packageId)->update(['id_usuario' => $userId]);
        }
        Schema::table('public.paquetes_productos', function (Blueprint $table) {
            $table->foreign('id_usuario', 'paquetes_productos_laravel_usuario_fkey')->references('id')->on('public.users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('LOCK TABLE public.paquetes_productos IN ACCESS EXCLUSIVE MODE');
        $owners = $this->owners(false);
        Schema::table('public.paquetes_productos', function (Blueprint $table) {
            $table->dropForeign('paquetes_productos_laravel_usuario_fkey');
        });
        foreach ($owners as $packageId => $userId) {
            DB::table('public.paquetes_productos')->where('id_paquete', $packageId)->update(['id_usuario' => $userId]);
        }
        Schema::table('public.paquetes_productos', function (Blueprint $table) {
            $table->integer('id_usuario')->change();
            $table->foreign('id_usuario', 'paquetes_productos_id_usuario_fkey')->references('id_usuario')->on('public.usuarios');
        });
    }

    /** @return array<int, int> */
    private function owners(bool $toLaravel): array
    {
        $source = $toLaravel ? 'public.usuarios' : 'public.users';
        $target = $toLaravel ? 'public.users' : 'public.usuarios';
        $sourceId = $toLaravel ? 'id_usuario' : 'id';
        $targetId = $toLaravel ? 'id' : 'id_usuario';
        $sourceEmail = $toLaravel ? 'correo' : 'email';
        $targetEmail = $toLaravel ? 'email' : 'correo';
        $result = [];
        $mapped = [];
        foreach (DB::table('public.paquetes_productos')->get(['id_paquete', 'id_usuario']) as $package) {
            $id = (int) $package->id_usuario;
            if (! isset($mapped[$id])) {
                $email = trim((string) DB::table($source)->where($sourceId, $id)->value($sourceEmail));
                $matches = $email === '' ? collect() : DB::table($target)->whereRaw('LOWER(TRIM('.$targetEmail.')) = ?', [Str::lower($email)])->limit(2)->pluck($targetId);
                if ($matches->count() !== 1) {
                    throw new RuntimeException('No se puede cambiar la relación de paquetes: el autor '.$id.' no tiene una correspondencia única por correo. No se modificaron sus registros.');
                }
                $mapped[$id] = (int) $matches->first();
            }
            $result[(int) $package->id_paquete] = $mapped[$id];
        }

        return $result;
    }
};
