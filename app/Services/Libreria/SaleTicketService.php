<?php

namespace App\Services\Libreria;

use stdClass;
use TCPDF;

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
        $height = min(2500, max(210, 165 + count($document['cuerpoDocumento']) * 17
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
        $html = view('libreria.ticket', ['document' => $document, 'journal' => $journal, 'payments' => $payments])->render();
        $pdf->writeHTML($html, true, false, true, false, '');
        if ($pdf->GetY() + 53 > $pdf->getPageHeight() - 5) {
            $pdf->AddPage();
        }
        $url = $this->consultationUrl($document);
        $pdf->write2DBarcode($url, 'QRCODE,H', 20, $pdf->GetY() + 2, 40, 40, ['border' => false, 'padding' => 4, 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]], 'N');
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->writeHTML('<p style="text-align:center"><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Consultar documento en Hacienda</a></p>', true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
