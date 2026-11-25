<?php

namespace App\Services\Libreria;

use stdClass;
use TCPDF;

class SaleTicketService
{
    /** @param array{identificacion: array{ambiente: string, codigoGeneracion: string, fecEmi: string}} $document */
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
        $height = min(2500, max(200, 160 + count($document['cuerpoDocumento']) * 17
            + array_sum(array_map(fn (array $line): int => (int) ceil(mb_strlen($line['descripcion']) / 30) * 4, $document['cuerpoDocumento']))
            + (int) ceil(mb_strlen($document['resumen']['observaciones'] ?? '') / 40) * 4 + count($document['resumen']['pagos'] ?? []) * 4));
        $pdf = new TCPDF('P', 'mm', [80, $height], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 4, 4);
        $pdf->SetAutoPageBreak(true, 5);
        $pdf->SetCreator('Santa Cecilia');
        $pdf->SetTitle('Ticket '.$journal->numero_control);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();
        $view = view('libreria.ticket', ['document' => $document, 'journal' => $journal, 'payments' => $payments]);
        $styles = $view->fragment('styles');
        $pdf->writeHTML($styles.$view->fragment('header'), true, false, true, false, '');
        $pdf->write2DBarcode($this->consultationUrl($document), 'QRCODE,H', 20, $pdf->GetY() + 2, 40, 40,
            ['padding' => 'auto', 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]], 'N');
        $pdf->Ln(2);
        $pdf->writeHTML($styles.$view->fragment('detail'), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
