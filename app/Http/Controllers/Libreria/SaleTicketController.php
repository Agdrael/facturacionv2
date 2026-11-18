<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Services\Libreria\SaleTicketService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SaleTicketController extends Controller
{
    public function __invoke(Request $request, int $journal, SaleTicketService $tickets): Response
    {
        $row = DB::table('libreria.venta_dtes')->where('id', $journal)->first();
        abort_if($row === null, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->getAuthIdentifier(), 403);
        $row->anulado = DB::table('libreria.ventas')->where('id', $row->venta_id)->value('estado') === 'ANULADO';

        return response($tickets->render($row), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Ticket-'.$row->codigo_generacion.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
