<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Cliente;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptClientService
{
    public function __construct(private ReceiptSourceService $sources) {}

    /** @return array<string, mixed> */
    public function prepare(int $clientId): array
    {
        $client = Cliente::query()->whereKey($clientId)->firstOrFail();
        $this->sources->require(mb_strtoupper(trim($client->nombre)) !== 'CLIENTES VARIOS', 'El anticipo requiere un cliente individual del catálogo actual.');
        $this->sources->advisory('receipt-client:'.$clientId);
        $key = 'client-copy:'.$clientId;
        DB::table('libreria.receipt_settings')->insertOrIgnore(['key' => $key, 'value' => json_encode(['operation_key' => (string) Str::uuid()])]);
        $operation = json_decode(DB::table('libreria.receipt_settings')->where('key', $key)->value('value'), true, 512, JSON_THROW_ON_ERROR);
        $data = ['client_id' => $clientId, 'operation_key' => $operation['operation_key'], 'name' => $client->nombre,
            'dui' => $client->getAttribute('dui'), 'nit' => $client->getAttribute('nit'), 'account' => $client->getAttribute('cuenta_contable')];
        $this->find($this->sources->legacy(), $data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function apply(Connection $db, array $data): int
    {
        if ($db->getDriverName() === 'pgsql') {
            $db->select("SELECT pg_advisory_xact_lock(hashtext('libreria.recibo.cliente'), hashtext(?))", [(string) $data['client_id']]);
        }
        $previous = $db->table('public.libreria_recibo_operaciones')->where('id', $data['operation_key'])->first();
        if ($previous !== null) {
            $id = json_decode($previous->result, true, 512, JSON_THROW_ON_ERROR)['legacy_client_id'];
            $this->sources->require($db->table('public.clientes')->where('id', $id)->exists(), 'La identidad contable del cliente fue eliminada.');

            return $id;
        }
        $id = $this->find($db, $data);
        if ($id === null) {
            $id = $db->table('public.clientes')->insertGetId(['nombre' => $data['name'], 'dui' => $data['dui'], 'nit' => $data['nit'], 'cta_cte' => $data['account']]);
        }
        $this->assertAvailable($data['client_id'], $id);
        $db->table('public.libreria_recibo_operaciones')->insert(['id' => $data['operation_key'],
            'payload_hash' => hash('sha256', 'receipt-client:'.$data['client_id']), 'result' => json_encode(['legacy_client_id' => $id]), 'created_at' => now()]);

        return $id;
    }

    public function remember(int $clientId, int $legacyId, int $userId): void
    {
        $this->assertAvailable($clientId, $legacyId);
        DB::table('libreria.receipt_client_links')->insertOrIgnore(['client_id' => $clientId, 'legacy_client_id' => $legacyId,
            'user_id' => $userId, 'reason' => 'Identidad contable resuelta automáticamente al emitir el recibo.', 'created_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function find(Connection $db, array $data): ?int
    {
        $linked = DB::table('libreria.receipt_client_links')->where('client_id', $data['client_id'])->value('legacy_client_id');
        if ($linked !== null) {
            $this->sources->require($db->table('public.clientes')->where('id', $linked)->exists(), 'No existe la identidad contable del cliente.');

            return (int) $linked;
        }
        $query = $db->table('public.clientes')->where(function ($query) use ($data): void {
            $query->whereRaw('1 = 0');
            foreach (['dui', 'nit'] as $field) {
                $document = str_replace(['-', ' ', '.'], '', trim((string) ($data[$field] ?? '')));
                if (ctype_digit($document) && trim($document, '0') !== '') {
                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE($field, ''), '-', ''), ' ', ''), '.', '') = ?", [$document]);
                }
            }
        });
        $ids = $query->limit(2)->pluck('id');
        $this->sources->require($ids->count() <= 1, 'Los documentos identifican distintos clientes contables. Revisa sus datos antes de emitir el anticipo.');
        if ($ids->isEmpty()) {
            return null;
        }
        $id = (int) $ids->first();
        $match = $db->table('public.clientes')->where('id', $id)->first();
        foreach (['dui', 'nit'] as $field) {
            $document = str_replace(['-', ' ', '.'], '', trim((string) ($data[$field] ?? '')));
            $existing = str_replace(['-', ' ', '.'], '', trim((string) ($match->{$field} ?? '')));
            $this->sources->require($document === '' || trim($document, '0') === '' || $existing === '' || trim($existing, '0') === '' || $document === $existing,
                'Los documentos del cliente contradicen su identidad contable. Revisa sus datos antes de emitir el anticipo.');
        }
        $this->assertAvailable($data['client_id'], $id);

        return $id;
    }

    private function assertAvailable(int $clientId, int $legacyId): void
    {
        $this->sources->require(! DB::table('libreria.receipt_client_links')->where('legacy_client_id', $legacyId)->where('client_id', '!=', $clientId)->exists(), 'La identidad contable ya pertenece a otro cliente del catálogo actual.');
        $this->sources->require(! DB::table('libreria.receipt_client_links')->where('client_id', $clientId)->where('legacy_client_id', '!=', $legacyId)->exists(), 'El cliente tiene otra identidad contable registrada.');
    }
}
