<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Libreria\StoreReceiptRequest;
use App\Models\Dte\Cat017;
use App\Models\Dte\Cat022;
use App\Models\Libreria\Receipt;
use App\Services\Libreria\ReceiptCreditService;
use App\Services\Libreria\ReceiptIntegrationService;
use App\Services\Libreria\ReceiptMoney;
use App\Services\Libreria\ReceiptSelectionService;
use App\Services\Libreria\ReceiptService;
use App\Services\Libreria\ReceiptSourceService;
use App\Services\Libreria\ReceiptTicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('receipts.enabled'), 503, 'El módulo de recibos todavía no está activado.');
    }

    public function index(Request $request, ReceiptSourceService $sources): View
    {
        $this->enabled();

        return view('libreria.recibos.index', ['options' => ['concepts' => $sources->concepts(), 'methods' => Cat017::catalogOptions()->all(),
            'identityDocuments' => Cat022::catalogOptions()->all(), 'year' => (int) now('America/El_Salvador')->format('Y'),
            'date' => now('America/El_Salvador')->toDateString(), 'accounts' => $sources->receivableAccounts(), 'cashAccount' => config('receipts.cash_account'),
            'recipientsUrl' => route('libreria.recibos.recipients'), 'cxcUrl' => route('libreria.recibos.cxc'),
            'clientCxcUrl' => route('libreria.recibos.client_cxc'), 'prepareUrl' => route('libreria.recibos.prepare'),
            'storeUrl' => route('libreria.recibos.store'), 'historyUrl' => route('libreria.recibos.history'),
            'canCreate' => $request->user()->can('libreria.recibos.crear'), 'canPrint' => $request->user()->can('libreria.recibos.descargar'),
            'canRetry' => $request->user()->can('libreria.recibos.reintentar'), 'canAnnul' => $request->user()->can('libreria.recibos.anular')]]);
    }

    public function prepare(Request $request, ReceiptSelectionService $selection): JsonResponse
    {
        $this->enabled();
        abort_unless($request->user()->can('libreria.recibos.crear'), 403);
        $data = $request->validate(['mode' => ['required', 'in:client_cxc,academic_cxc'],
            'sourceId' => ['nullable', 'integer', 'min:1', 'required_if:mode,academic_cxc'],
            'sourceKey' => ['nullable', 'string', 'max:150', 'required_if:mode,client_cxc'],
            'funding' => ['nullable', 'in:normal,beca,bienhechor'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json($selection->describe($data));
    }

    public function clientCxc(Request $request, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        abort_unless($request->user()->can('libreria.recibos.crear'), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $native = DB::table('libreria.venta_dtes as d')->join('libreria.ventas as v', 'v.id', '=', 'd.venta_id')
            ->where('d.transmision_estado', 'ACEPTADO')->whereNotNull('d.sello_recepcion')->where('v.operacion', '2')->where('v.estado', '!=', 'ANULADO')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('libreria.venta_cuotas as c')->whereColumn('c.venta_id', 'v.id');
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('libreria.venta_invalidaciones as i')->whereColumn('i.venta_dte_id', 'd.id');
            })->pluck('d.codigo_generacion')->all();
        $query = $sources->legacy()->table('public.saldo_cargos as c')->join('public.clientes as p', 'p.id', '=', 'c.id_cliente')
            ->leftJoin('public.ventas as v', 'v.numero_factura', '=', 'c.uuid_venta')->where('c.saldo_pendiente', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('c.anulado')->orWhere('c.anulado', false);
            })
            ->where(function ($q) use ($native): void {
                $q->whereIn('c.uuid_venta', $native)->orWhere(function ($q): void {
                    $q->where('v.operacion', '2')->where(function ($q): void {
                        $q->whereNull('v.anulado')->orWhere('v.anulado', false);
                    });
                });
            })->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('public.productos_vendidos as d')->whereColumn('d.id_ventas', 'v.id_venta')
                    ->where(function ($q): void {
                        $q->whereRaw('LOWER(d.nombre_producto) LIKE ?', ['%colegiatura%'])->orWhereRaw('LOWER(d.nombre_producto) LIKE ?', ['matr%']);
                    });
            });
        if (trim($data['q'] ?? '') !== '') {
            $term = '%'.mb_strtolower(trim($data['q'])).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(p.nombre) LIKE ?', [$term])->orWhere('v.numero_control', 'like', $term)->orWhere('c.uuid_venta', 'like', $term);
            });
        }
        $page = $query->orderByDesc('c.id')->paginate(20, ['c.id', 'c.uuid_venta', 'c.saldo', 'c.saldo_pendiente', 'p.nombre', 'p.dui', 'p.nit', 'p.cta_cte', 'v.numero_control']);

        return response()->json(['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'data' => $page->getCollection()->map(fn ($row) => [
            'key' => 'legacy:'.$row->id, 'name' => $row->nombre, 'invoice' => $row->numero_control ?? $row->uuid_venta,
            'amount' => (string) $row->saldo, 'remaining' => (string) $row->saldo_pendiente, 'account' => $row->cta_cte])->all()]);
    }

    public function recipients(Request $request, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        abort_unless($request->user()->canAny(['libreria.recibos.crear', 'libreria.recibos.conciliar']), 403);
        $data = $request->validate(['type' => ['required', 'in:cliente,legacy_cliente,alumno'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $sources->recipients($data['type'], $data['q'] ?? '')]);
    }

    public function debts(Request $request, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        abort_unless($request->user()->canAny(['libreria.recibos.crear', 'libreria.recibos.conciliar']), 403);
        $data = $request->validate(['type' => ['required', 'in:cliente,legacy_cliente,alumno'], 'id' => ['required', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'between:2000,2100'], 'funding' => ['required', 'in:normal,beca,bienhechor']]);
        $recipient = $sources->recipient($data);
        $year = $data['year'] ?? ($data['type'] === 'alumno' ? $sources->platform()->table('public.cxc')->where('student_id', $recipient['id'])->max('syear') : null) ?? now()->year;

        return response()->json(['data' => $sources->debts($recipient, (int) $year, $data['funding']), 'year' => (int) $year]);
    }

    public function cxc(Request $request, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        abort_unless($request->user()->canAny(['libreria.recibos.crear', 'libreria.recibos.conciliar']), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:2000,2100'],
            'funding' => ['required', 'in:normal,beca,bienhechor'], 'page' => ['nullable', 'integer', 'min:1']]);
        [, $balanceColumn] = ReceiptSourceService::fundingColumns($data['funding']);
        $query = $sources->platform()->table('public.cxc as c')->leftJoin('public.students as s', DB::raw('CAST(s.student_id AS VARCHAR)'), '=', 'c.student_id')
            ->join('public.saldo_pagos as b', function ($join): void {
                $join->on(DB::raw('CAST(b.student_id AS VARCHAR)'), '=', 'c.student_id')->on('b.syear', '=', 'c.syear')
                    ->on(DB::raw('CAST(b.mes AS VARCHAR)'), '=', DB::raw("LTRIM(c.item_pago, '0')"));
            })->where('b.'.$balanceColumn, '>', 0)
            ->where('c.facturado', true)->where(function ($query): void {
                $query->whereNull('c.recibo')->orWhere('c.recibo', false);
            })
            ->where(function ($query): void {
                $query->where('c.parciales', '>', 0)->orWhere(function ($query): void {
                    $query->whereNull('c.parciales')->where('c.monto', '>', 0);
                });
            });
        if (isset($data['year'])) {
            $query->where('c.syear', $data['year']);
        }
        if (trim($data['q'] ?? '') !== '') {
            $search = '%'.mb_strtolower(trim($data['q'])).'%';
            $query->where(function ($query) use ($search): void {
                $query->whereRaw('LOWER(c.custom_275) LIKE ?', [$search])->orWhereRaw('LOWER(s.first_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(s.last_name) LIKE ?', [$search])->orWhere('c.student_id', trim($search, '%'));
            });
        }
        $page = $query->orderByDesc('c.syear')->orderBy('c.student_id')->orderBy('c.item_pago')->paginate(20, ['c.*', 's.first_name', 's.middle_name', 's.last_name', 'b.'.$balanceColumn.' as funding_remaining']);
        $keys = $page->getCollection()->map(fn ($row) => 'academic:'.$row->id.':'.$data['funding'])->all();
        $obligations = DB::table('libreria.receipt_obligations')->whereIn('source_key', $keys)->get()->keyBy('source_key');

        return response()->json(['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'data' => $page->getCollection()->map(function ($row) use ($obligations, $data): array {
            $key = 'academic:'.$row->id.':'.$data['funding'];
            $obligation = $obligations->get($key);
            $remaining = ReceiptMoney::decimal(min(ReceiptMoney::cents($row->parciales ?? $row->monto), ReceiptMoney::cents($row->funding_remaining)));

            return ['key' => $key, 'cxcId' => $row->id, 'studentId' => $row->student_id,
                'studentName' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
                'name' => ReceiptCreditService::monthName((int) $row->item_pago), 'year' => $row->syear, 'funding' => $data['funding'],
                'amount' => (string) $row->monto, 'remaining' => $obligation->remaining ?? (string) $remaining,
                'payerName' => $row->custom_275, 'account' => $row->cuenta_contable];
        })->all()]);
    }

    public function store(StoreReceiptRequest $request, ReceiptService $receipts): JsonResponse
    {
        $this->enabled();

        return response()->json($receipts->create($request->validated(), $request->user()), 201);
    }

    public function history(Request $request, ReceiptService $receipts, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['legacy' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        if ($data['legacy'] ?? false) {
            $query = $sources->legacy()->table('public.recibos as r')->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('public.libreria_recibo_documentos as p')->whereColumn('p.legacy_receipt_id', 'r.id');
            })->orderByDesc('r.id');
            if (! $request->user()->can('libreria.recibos.ver_todos')) {
                $query->where('numero_caja', (string) $request->user()->getAttribute('puntoventa'));
            }
            if (trim($data['q'] ?? '') !== '') {
                $query->whereRaw('LOWER(nombre_cliente) LIKE ?', ['%'.mb_strtolower(trim($data['q'])).'%']);
            }
            $page = $query->paginate(30);

            return response()->json(['data' => $page->getCollection()->map(fn ($row) => ['id' => $row->id, 'number' => 'Anterior #'.$row->id,
                'recipient' => $row->nombre_cliente, 'register' => $row->numero_caja, 'total' => (string) $row->precio, 'status' => $row->anulado ? 'ANULADO' : 'HISTORICO',
                'date' => $row->fecha, 'operator' => 'Sistema anterior', 'errors' => [], 'legacy' => true, 'ticketUrl' => route('libreria.recibos.legacy.ticket', $row->id)])->all(),
                'page' => $page->currentPage(), 'pages' => $page->lastPage()]);
        }
        $query = Receipt::query()->orderByDesc('id');
        if (! $request->user()->can('libreria.recibos.ver_todos')) {
            $query->where('user_id', $request->user()->id);
        }
        if (trim($data['q'] ?? '') !== '') {
            $search = '%'.trim($data['q']).'%';
            $query->where(function ($query) use ($search, $data): void {
                $query->whereLike('recipient_name', $search)->orWhereLike('payer_name', $search)
                    ->orWhereExists(function ($line) use ($search, $data): void {
                        $line->selectRaw('1')->from('libreria.receipt_lines as l')->whereColumn('l.receipt_id', 'libreria.receipts.id')
                            ->where(function ($student) use ($search, $data): void {
                                $student->where('l.data->history_student->id', trim($data['q']))
                                    ->orWhereLike('l.data->history_student->name', $search);
                            });
                    });
            });
        }
        $page = $query->paginate(30);
        $students = [];
        foreach (DB::table('libreria.receipt_lines')->whereIn('receipt_id', $page->getCollection()->modelKeys())->get(['receipt_id', 'data']) as $line) {
            $lineData = json_decode($line->data, true, 512, JSON_THROW_ON_ERROR);
            if (isset($lineData['history_student'])) {
                $students[$line->receipt_id] = $lineData['history_student'];
            }
        }

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $receipts->present($r, $students[$r->id] ?? null))->all(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()]);
    }

    public function retry(Request $request, int $receipt, ReceiptService $receipts, ReceiptIntegrationService $integrations): JsonResponse
    {
        $this->enabled();
        $receipts->accessible($request->user(), $receipt, 'reintentar');

        return response()->json($receipts->present($integrations->process($receipt)));
    }

    public function annul(Request $request, int $receipt, ReceiptService $receipts): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json($receipts->present($receipts->annul($receipt, $request->user(), $data['reason'])));
    }

    public function reconcile(Request $request, ReceiptSourceService $sources): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['key' => ['required', 'string', 'max:150'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $sources->reconcile($data['key'], $request->user()->id, $data['reason']);

        return response()->json(['message' => 'Se concilió el saldo con sus fuentes, sin registrar un cobro.']);
    }

    public function credits(): JsonResponse
    {
        $this->enabled();
        $rows = DB::table('libreria.venta_dtes as d')->join('libreria.ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('libreria.receipt_credit_origins as o', 'o.journal_id', '=', 'd.id')
            ->where('v.operacion', '2')->where('v.estado', '!=', 'ANULADO')->where('d.transmision_estado', 'ACEPTADO')
            ->whereNull('o.applied_at')->orderByDesc('d.id')->limit(50)->get(['d.id', 'd.numero_control', 'd.cliente', 'v.total']);

        return response()->json(['data' => $rows->map(fn ($row) => ['id' => $row->id, 'number' => $row->numero_control,
            'name' => json_decode($row->cliente, true)['name'] ?? '', 'amount' => (string) $row->total])->all()]);
    }

    public function registerCredit(int $journal, ReceiptCreditService $credits): JsonResponse
    {
        $this->enabled();
        $row = DB::table('libreria.venta_dtes')->where('id', $journal)->first();
        abort_if($row === null, 404);
        $credits->register($row);
        DB::table('libreria.venta_dtes')->where('id', $journal)->update(['pagos_aplicados_at' => now(), 'transmision_error' => null, 'updated_at' => now()]);
        DB::table('libreria.ventas')->where('id', $row->venta_id)->update(['estado' => 'FACTURADO', 'updated_at' => now()]);

        return response()->json(['message' => 'Crédito registrado sin volver a emitir ni transmitir el DTE.']);
    }

    public function ticket(Request $request, int $receipt, ReceiptService $receipts, ReceiptTicketService $tickets): Response
    {
        $this->enabled();
        $record = $receipts->accessible($request->user(), $receipt, 'descargar');

        return $this->pdf($tickets->render([$tickets->document($record)]), $record->number());
    }

    public function legacyTicket(Request $request, int $receipt, ReceiptSourceService $sources, ReceiptTicketService $tickets, ReceiptService $receipts): Response
    {
        $this->enabled();
        $row = $sources->legacy()->table('public.recibos')->where('id', $receipt)->first();
        abort_if($row === null, 404);
        $uuid = $sources->legacy()->table('public.libreria_recibo_documentos')->where('legacy_receipt_id', $receipt)->value('receipt_uuid');
        if ($uuid !== null) {
            $native = Receipt::query()->where('uuid', $uuid)->firstOrFail();

            return $this->ticket($request, $native->id, $receipts, $tickets);
        }
        abort_unless($request->user()->can('libreria.recibos.ver_todos') || $row->numero_caja === (string) $request->user()->getAttribute('puntoventa'), 403);
        $document = ['number' => 'Anterior #'.$row->id, 'date' => $row->fecha, 'recipient' => $row->nombre_cliente,
            'payer' => null, 'email' => null, 'register' => $row->numero_caja, 'operator' => 'Sistema anterior', 'status' => $row->anulado ? 'ANULADO' : 'HISTORICO',
            'annulled' => (bool) $row->anulado, 'observation' => $row->observacion, 'total' => $row->precio, 'paid' => $row->precio, 'change' => '0', 'test' => false,
            'lines' => [['name' => $row->codigo_recibo, 'amount' => $row->precio, 'invoice' => null, 'funding' => null]],
            'payments' => [['method' => $row->tarjeta ? '03' : '01', 'amount' => $row->precio, 'reference' => null]]];

        return $this->pdf($tickets->render([$document]), 'Anterior-'.$receipt);
    }

    private function pdf(string $content, string $name): Response
    {
        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
