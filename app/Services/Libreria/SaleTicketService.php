<?php

namespace App\Services\Libreria;

use stdClass;
use TCPDF;
use TCPDF2DBarcode;

class SaleTicketService
{
    public function consultationUrl(array $document): string
    {
        $id = $document['identificacion'];

        return 'https://admin.factura.gob.sv/consultaPublica?'.http_build_query([
            'ambiente' => $id['ambiente'], 'codGen' => $id['codigoGeneracion'], 'fechaEmi' => $id['fecEmi'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function render(stdClass $journal): string
    {
        $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
        $payments = json_decode($journal->pagos, true, 512, JSON_THROW_ON_ERROR);
        $qr = new TCPDF2DBarcode($this->consultationUrl($document), 'QRCODE,H');
        $qrImage = base64_encode($qr->getBarcodePngData(4, 4));
        $height = min(2500, max(170, 130 + count($document['cuerpoDocumento']) * 17
            + array_sum(array_map(fn (array $line): int => (int) ceil(mb_strlen($line['descripcion']) / 30) * 4, $document['cuerpoDocumento']))));
        $pdf = new TCPDF('P', 'mm', [80, $height], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 4, 4);
        $pdf->SetAutoPageBreak(true, 5);
        $pdf->SetCreator('Santa Cecilia');
        $pdf->SetTitle('Ticket '.$journal->numero_control);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();
        $html = view('libreria.ticket', ['document' => $document, 'journal' => $journal, 'payments' => $payments, 'qrImage' => $qrImage])->render();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
