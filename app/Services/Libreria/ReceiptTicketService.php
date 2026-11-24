<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Receipt;
use Illuminate\Support\Facades\DB;
use TCPDF;

class ReceiptTicketService
{
    /** @param list<array<string, mixed>> $receipts */
    public function render(array $receipts): string
    {
        $pdf = new TCPDF('P', 'mm', [80, 190], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 4, 4);
        $pdf->SetAutoPageBreak(true, 5);
        $pdf->SetCreator('Santa Cecilia');
        $pdf->SetTitle('Recibos');
        $pdf->SetFont('dejavusans', '', 8);
        $emitter = DB::table('public.opciones_factura_electronica_emisor')->first();
        foreach ($receipts as $receipt) {
            $issuer = (object) ($receipt['issuer'] ?? $emitter ?? []);
            $pdf->AddPage();
            $pdf->writeHTML(view('libreria.recibos.ticket', ['receipt' => $receipt, 'emitter' => $issuer])->render(), true, false, true, false, '');
        }

        return $pdf->Output('', 'S');
    }

    /** @return array<string, mixed> */
    public function document(Receipt $receipt): array
    {
        $lines = DB::table('libreria.receipt_lines')->where('receipt_id', $receipt->id)->orderBy('id')->get();
        $payments = DB::table('libreria.receipt_payments')->where('receipt_id', $receipt->id)->orderBy('id')->get();
        $batch = DB::table('libreria.receipt_batches')->where('id', $receipt->batch_id)->firstOrFail();

        return ['number' => $receipt->number(), 'date' => $receipt->created_at->setTimezone('America/El_Salvador')->format('d/m/Y H:i'),
            'recipient' => $receipt->recipient_name, 'payer' => $receipt->payer_name, 'email' => $receipt->payer_email, 'register' => $receipt->register,
            'document' => $lines->isEmpty() ? null : (json_decode($lines->first()->data, true, 512, JSON_THROW_ON_ERROR)['recipient_document'] ?? null),
            'operator' => $receipt->operator_name, 'status' => $receipt->status, 'annulled' => $receipt->annulled_at !== null,
            'observation' => $receipt->observation, 'total' => $receipt->total, 'paid' => $receipt->paid, 'change' => $receipt->change,
            'test' => $batch->environment !== '01', 'issuer' => json_decode($batch->issuer, true, 512, JSON_THROW_ON_ERROR), 'lines' => $lines->map(function ($line): array {
                $data = json_decode($line->data, true, 512, JSON_THROW_ON_ERROR);

                return ['name' => $line->description, 'amount' => $line->amount, 'invoice' => $data['invoice']['number'] ?? null,
                    'year' => $data['year'] ?? null, 'funding' => $data['funding'] ?? null,
                    'payer' => $data['payer_name'] ?? null, 'email' => $data['payer_email'] ?? null];
            })->all(), 'payments' => $payments->map(fn ($p) => ['method' => $p->method, 'amount' => $p->amount, 'reference' => $p->reference])->all()];
    }
}
