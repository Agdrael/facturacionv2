<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashReconciliation;
use App\Models\User;
use App\Services\Empleado\EmployeeAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

class CashWorkbookService
{
    private const TEMPLATE_REVISION = '4';

    private const MAX_EXTRA_CHECK_ROWS = 1000;

    public function original(CashReconciliation $record, User $actor): string
    {
        return DB::transaction(function () use ($record, $actor): string {
            $record = CashReconciliation::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->original_path !== null && Storage::disk(config('cash.disk'))->exists($record->original_path)) {
                return $record->original_path;
            }
            $book = $this->build($record);
            try {
                $path = $this->storeBook($record, $book, 'original');
                $this->rememberFile($record, $path, 'original', $actor);
                $record->update(['original_path' => $path]);
            } finally {
                $book->disconnectWorksheets();
            }

            return $path;
        });
    }

    public function upload(CashReconciliation $record, UploadedFile $file, User $actor): void
    {
        $this->checkArchive($file->getPathname());
        $original = $this->original($record, $actor);
        DB::transaction(function () use ($record, $file, $actor, $original): void {
            $record = CashReconciliation::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->firmado_at !== null) {
                throw ValidationException::withMessages(['archivo' => 'El arqueo firmado no se puede sustituir.']);
            }
            if ($record->original_path !== $original) {
                throw ValidationException::withMessages(['archivo' => 'La plantilla cambió durante la entrega. Descarga el original actualizado antes de volver a subir el Excel.']);
            }
            $expected = IOFactory::load(Storage::disk(config('cash.disk'))->path($original));
            try {
                try {
                    $uploaded = IOFactory::load($file->getPathname());
                } catch (Exception $error) {
                    throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel. Descarga el original y vuelve a guardarlo como XLSX.']);
                }
                $this->validateWorkbook($expected, $uploaded);
            } finally {
                $expected->disconnectWorksheets();
                if (isset($uploaded)) {
                    $uploaded->disconnectWorksheets();
                }
            }
            $record->refresh();
            $path = $record->id.'/entregado-'.Str::uuid().'.xlsx';
            $this->put($path, file_get_contents($file->getPathname()));
            $this->rememberFile($record, $path, 'entregado', $actor);
            $record->update(['entregado_path' => $path, 'entregado_at' => now(), 'estado' => 'recibido', 'revisado_por' => null, 'revisado_at' => null]);
        });
    }

    public function refreshTemplate(CashReconciliation $record, User $actor): bool
    {
        return DB::transaction(function () use ($record, $actor): bool {
            $record = CashReconciliation::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->firmado_at !== null) {
                throw ValidationException::withMessages(['archivo' => 'El arqueo firmado no se puede sustituir.']);
            }
            $disk = Storage::disk(config('cash.disk'));
            $previous = null;
            $source = null;
            $book = null;
            try {
                if ($record->original_path !== null && $disk->exists($record->original_path)) {
                    $previous = IOFactory::load($disk->path($record->original_path));
                    if ((string) $previous->getSheetByName('_Control')?->getCell('A4')->getValue() === self::TEMPLATE_REVISION) {
                        return false;
                    }
                }
                $book = $this->build($record);
                if ($record->entregado_path !== null) {
                    if (! $disk->exists($record->entregado_path)) {
                        throw ValidationException::withMessages(['archivo' => 'No se encuentra el Excel entregado. Recupera ese archivo antes de actualizar la plantilla.']);
                    }
                    $source = IOFactory::load($disk->path($record->entregado_path));
                }
                $manualSource = $source ?? $previous;
                if ($manualSource !== null) {
                    $from = $manualSource->getSheetByName('Arqueo');
                    $to = $book->getSheetByName('Arqueo');
                    $revision = (int) $manualSource->getSheetByName('_Control')?->getCell('A4')->getValue();
                    $layout = $revision >= 3 ? $this->checkLayout($from) : ['own' => 0, 'third' => 0];
                    $this->expandCheckRows($to, ['own' => 0, 'third' => 0], $layout);
                    foreach ($from->getCellCollection()->getCoordinates() as $coordinate) {
                        $manual = $this->manualType($coordinate, $layout);
                        // Older revisions generated the totals and, before v2, the check detail.
                        if ($manual === null || ($manual === 'sum' && $revision < 3)
                            || ($revision < 2 && preg_match('/^[CK](35|36|37)$/', $coordinate))) {
                            continue;
                        }
                        $cell = $from->getCell($coordinate);
                        if ($cell->getValue() !== null && ($cell->getDataType() !== DataType::TYPE_FORMULA
                            || ($manual === 'sum' && $this->validCheckSum($coordinate, (string) $cell->getValue(), $layout)))) {
                            $to->getCell($coordinate)->setValueExplicit($cell->getValue(), $cell->getDataType());
                        }
                    }
                }
                $path = $this->storeBook($record, $book, 'original');
                $this->rememberFile($record, $path, 'original', $actor);
                $record->update(['original_path' => $path, 'entregado_path' => null, 'entregado_at' => null,
                    'revisado_por' => null, 'revisado_at' => null, 'estado' => 'pendiente_excel']);

                return true;
            } finally {
                $previous?->disconnectWorksheets();
                $source?->disconnectWorksheets();
                $book?->disconnectWorksheets();
            }
        });
    }

    public function review(CashReconciliation $record, User $actor, string $version): void
    {
        DB::transaction(function () use ($record, $actor, $version): void {
            $record = CashReconciliation::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->firmado_at !== null) {
                return;
            }
            if ($record->entregado_path === null) {
                throw ValidationException::withMessages(['arqueo' => 'Primero se debe cargar el Excel completado.']);
            }
            $this->assertVersion($record, $version);
            $record->update(['revisado_por' => $actor->id, 'revisado_at' => now(), 'estado' => 'revisado']);
        });
    }

    public function sign(CashReconciliation $record, string $signature, User $actor, string $version): void
    {
        $raw = base64_decode(preg_replace('#^data:image/png;base64,#', '', $signature), true);
        $image = is_string($raw) && strlen($raw) <= 250000 ? @getimagesizefromstring($raw) : false;
        if ($image === false || $image[2] !== IMAGETYPE_PNG || $image[0] < 100 || $image[1] < 30 || $image[0] > 1600 || $image[1] > 600) {
            throw ValidationException::withMessages(['firma' => 'Dibuja una firma PNG válida antes de continuar.']);
        }
        $employee = app(EmployeeAccessService::class)->employeeFor($actor);
        abort_if($employee === null, 403);
        DB::transaction(function () use ($record, $raw, $actor, $employee, $version): void {
            $record = CashReconciliation::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->firmado_at !== null) {
                return;
            }
            if ($record->entregado_path === null || $record->revisado_at === null) {
                throw ValidationException::withMessages(['firma' => 'Revisa el Excel entregado antes de firmar.']);
            }
            $this->assertVersion($record, $version);
            $signaturePath = $record->id.'/firma-'.Str::uuid().'.png';
            $this->put($signaturePath, $raw);
            $book = IOFactory::load(Storage::disk(config('cash.disk'))->path($record->entregado_path));
            try {
                $drawing = new Drawing;
                $drawing->setName('Firma del revisor');
                $drawing->setPath(Storage::disk(config('cash.disk'))->path($signaturePath));
                $drawing->setCoordinates('L3');
                $drawing->setWidth(145);
                $drawing->setWorksheet($book->getSheetByName('Arqueo'));
                $sheet = $book->getSheetByName('Resumen');
                $this->text($sheet, 'D2', 'Firmado por: '.$actor->name.' · Empleado '.$employee->codigo);
                $this->text($sheet, 'D3', now(config('cash.timezone'))->toIso8601String());
                $path = $this->storeBook($record, $book, 'firmado');
            } finally {
                $book->disconnectWorksheets();
            }
            $this->rememberFile($record, $signaturePath, 'firma', $actor);
            $this->rememberFile($record, $path, 'firmado', $actor);
            $record->update(['firmado_path' => $path, 'estado' => 'firmado', 'firmado_por' => $actor->id, 'firmado_nombre' => $actor->name,
                'firmado_empleado' => $employee->codigo, 'firmado_at' => now()]);
        });
    }

    public function build(CashReconciliation $record): Spreadsheet
    {
        $snapshot = $record->snapshot;
        $book = IOFactory::load(config('cash.template'));
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Arqueo');
        $this->text($sheet, 'B2', 'CONTROL INTERNO COLEGIO SANTA CECILIA · '.$snapshot['register']);
        $this->text($sheet, 'B3', 'ARQUEO DE CAJA · SESIÓN '.$snapshot['sessionId']);
        $this->text($sheet, 'H3', $snapshot['date']);
        $this->text($sheet, 'C11', $snapshot['register']);
        $this->text($sheet, 'C14', $snapshot['register']);
        $methods = $snapshot['methods'];
        $sheet->setCellValue('D11', (float) BigDecimal::of($methods['02'] ?? '0')->plus($methods['03'] ?? '0')->plus($methods['V1_CARD'] ?? '0')->__toString());
        $sheet->setCellValue('E11', 0);
        $sheet->setCellValue('D14', (float) ($snapshot['documents']['01'] ?? 0));
        $sheet->setCellValue('E14', (float) ($snapshot['documents']['03'] ?? 0));
        $sheet->setCellValue('F14', (float) ($snapshot['receiptTotal'] ?? 0));
        $sheet->setCellValue('D21', 0);
        $sheet->setCellValue('K10', '=SUM(K6,K9)');
        $sheet->setCellValue('K11', '=K32-K23');
        $sheet->setCellValue('K12', '=K38-K24');
        $sheet->setCellValue('K13', '=SUM(K10:K12)-SUM(K21,K22,K25)');
        $sheet->setCellValue('K38', '=SUM(K35:K37)');
        $sheet->setCellValue('K44', (float) $snapshot['receivable']);
        $checks = array_values(array_filter([...$snapshot['sales'], ...($snapshot['receipts'] ?? [])], fn (array $sale): bool => BigDecimal::of($sale['payments']['04'] ?? '0')->isPositive()));
        foreach (array_slice($checks, 0, 3) as $index => $sale) {
            $row = 35 + $index;
            if ($index === 2 && count($checks) > 3) {
                $remaining = array_slice($checks, 2);
                $this->text($sheet, 'C'.$row, count($remaining).' cheques (ver Resumen)');
                $sheet->setCellValue('K'.$row, (float) array_reduce($remaining,
                    fn (BigDecimal $sum, array $check): BigDecimal => $sum->plus($check['payments']['04']), BigDecimal::zero())->__toString());
            } else {
                $this->text($sheet, 'C'.$row, $sale['number']);
                $sheet->setCellValue('K'.$row, (float) $sale['payments']['04']);
            }
            $sheet->getStyle('C'.$row)->getFont()->setSize(7);
            $sheet->getStyle('C'.$row)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(30);
        }
        $this->text($sheet, 'C34', 'Documento');
        $creditSales = array_values(array_filter($snapshot['sales'], fn (array $sale): bool => BigDecimal::of($sale['receivable'])->isPositive()));
        foreach (array_slice($creditSales, 0, 3) as $index => $sale) {
            $row = 41 + $index;
            $this->text($sheet, 'C'.$row, $sale['client']);
            $this->text($sheet, 'G'.$row, $sale['number']);
            $sheet->setCellValue('K'.$row, (float) $sale['receivable']);
            $sheet->getStyle('C'.$row.':J'.$row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('C'.$row.':J'.$row)->getFont()->setSize(8);
            $sheet->getRowDimension($row)->setRowHeight(30);
        }
        $sheet->setCellValue('D48', (float) ($methods['01'] ?? 0));
        $sheet->setCellValue('F48', '=SUM(K6,K9,K21,K22,K25)');
        $sheet->setCellValue('H48', '=F48-D48');
        $sheet->setCellValue('J48', (float) ($methods['04'] ?? 0));
        $this->text($sheet, 'D45', 'EFECTIVO ESPERADO');
        $this->text($sheet, 'F45', 'EFECTIVO + REMESAS');
        $this->text($sheet, 'H45', 'SOBRANTE / FALTANTE');
        $this->text($sheet, 'J45', 'CHEQUES ESPERADOS');
        $this->text($sheet, 'C50', '');
        $this->text($sheet, 'B56', 'Período: '.$snapshot['openedAt'].' a '.$snapshot['closedAt']);
        $this->text($sheet, 'B57', 'El detalle completo de pagos, documentos y cuentas por cobrar está en Resumen.');
        $this->text($sheet, 'B58', 'Complete cantidades, remesas y observaciones. Los valores del sistema están protegidos.');
        $sheet->getPageSetup()->setPrintArea('B2:M58');
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setInsertRows(false);
        foreach (['D5:I5', 'D8:I8', 'D21:J25', 'C29:K31', 'K32', 'C35:K37', 'K38', 'I41:J43', 'C50:K54'] as $range) {
            $sheet->getStyle($range)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        }
        $summary = $book->createSheet()->setTitle('Resumen');
        $this->text($summary, 'A1', 'ARQUEO '.$record->id.' · '.$snapshot['register'].' · SESIÓN '.$snapshot['sessionId']);
        $this->text($summary, 'A2', 'Apertura: '.$snapshot['openedAt']);
        $this->text($summary, 'A3', 'Cierre: '.$snapshot['closedAt']);
        $this->text($summary, 'A4', 'Abrió: '.$snapshot['openedBy'].' · Cerró: '.$snapshot['closedBy']);
        $this->text($summary, 'A6', 'Total documentos');
        $summary->setCellValue('B6', (float) $snapshot['total']);
        $this->text($summary, 'A7', ($snapshot['version'] ?? 1) >= 2 ? 'Crédito emitido en ventas (no cobrado al emitir)' : 'Saldo por cobrar');
        $summary->setCellValue('B7', (float) $snapshot['receivable']);
        $row = 9;
        if (isset($snapshot['legacy'])) {
            foreach ($snapshot['origins'] as $source => $amounts) {
                $this->text($summary, 'D'.$row, strtoupper($source).' · Ventas / recibos / cobrado');
                $this->text($summary, 'E'.$row, $amounts['sales'].' / '.$amounts['receipts'].' / '.$amounts['collections']);
                $row++;
            }
            foreach ($snapshot['legacy']['sessions'] as $turn) {
                $this->text($summary, 'D'.$row, 'Turno V1 #'.$turn['legacyId']);
                $this->text($summary, 'E'.$row, $turn['openedAt'].' → '.$turn['closedAt']);
                $row++;
            }
        }
        foreach ($methods as $code => $amount) {
            $label = match ((string) $code) {
                '01' => 'Efectivo neto de cambio', '02' => 'Tarjeta débito', '03' => 'Tarjeta crédito', '04' => 'Cheque', '05' => 'Transferencia bancaria',
                'V1_CARD' => 'Tarjeta V1, sin especificar', '11' => 'Bitcoin', '99' => 'Pago en línea', default => 'Otro medio registrado'
            };
            $this->text($summary, 'A'.$row, $code.' · '.$label);
            $summary->setCellValue('B'.$row, (float) $amount);
            $summary->getStyle('B'.$row)->getNumberFormat()->setFormatCode('0.00');
            $row++;
        }
        $row += 2;
        $headers = ['Documento', 'Cliente', 'Operador', 'Estado DTE al cierre', 'Total', 'Saldo por cobrar', 'Pagos netos por método', 'Referencias', 'Origen'];
        foreach ($headers as $index => $header) {
            $this->text($summary, Coordinate::stringFromColumnIndex($index + 1).$row, $header);
        }
        $summary->getStyle('A'.$row.':I'.$row)->getFont()->setBold(true);
        foreach ($snapshot['sales'] as $sale) {
            $row++;
            $values = [$sale['number'], $sale['client'], $sale['operator'], $sale['dteState'], $sale['total'], $sale['receivable'],
                implode(' | ', array_map(fn ($code, $amount): string => $code.': '.$amount, array_keys($sale['payments']), array_values($sale['payments']))),
                implode(' | ', $sale['references']), strtoupper($sale['source'] ?? 'v2')];
            foreach ($values as $index => $value) {
                $cell = Coordinate::stringFromColumnIndex($index + 1).$row;
                if (in_array($index, [4, 5], true)) {
                    $summary->setCellValue($cell, (float) $value);
                } else {
                    $this->text($summary, $cell, (string) $value);
                }
            }
        }
        if (($snapshot['version'] ?? 1) >= 2) {
            $row += 3;
            $this->text($summary, 'A'.$row, 'RECIBOS · cobros separados de las ventas');
            $summary->setCellValue('E'.$row, (float) ($snapshot['receiptTotal'] ?? 0));
            foreach ($snapshot['receiptCategories'] ?? [] as $kind => $amount) {
                $row++;
                $this->text($summary, 'A'.$row, $kind);
                $summary->setCellValue('E'.$row, (float) $amount);
            }
            $row += 2;
            foreach ($snapshot['receipts'] ?? [] as $receipt) {
                $values = [$receipt['number'], $receipt['client'], $receipt['operator'], $receipt['state'], $receipt['total'], '',
                    implode(' | ', array_map(fn ($code, $value) => $code.': '.$value, array_keys($receipt['payments']), array_values($receipt['payments']))), implode(' | ', $receipt['references']), strtoupper($receipt['source'] ?? 'v2')];
                foreach ($values as $index => $value) {
                    $cell = Coordinate::stringFromColumnIndex($index + 1).$row;
                    if ($index === 4) {
                        $summary->setCellValue($cell, (float) $value);
                    } else {
                        $this->text($summary, $cell, (string) $value);
                    }
                }
                $row++;
            }
            $this->text($summary, 'A'.$row, 'Ingresos netos recibidos (todos los medios)');
            $summary->setCellValue('E'.$row, (float) ($snapshot['collections'] ?? 0));
        }
        $summary->getStyle('A1:I'.$row)->getFont()->setName('Arial')->setSize(10);
        $summary->getStyle('B6:B'.max(9, count($methods) + 8))->getNumberFormat()->setFormatCode('0.00');
        $summary->getStyle('A1:I'.$row)->getAlignment()->setWrapText(true);
        foreach (range('A', 'I') as $column) {
            $summary->getColumnDimension($column)->setWidth($column === 'A' ? 46 : 25);
        }
        $summary->freezePane('A5');
        $summary->getProtection()->setSheet(true);
        $control = $book->createSheet()->setTitle('_Control');
        $this->text($control, 'A1', (string) $record->id);
        $this->text($control, 'A2', (string) $snapshot['sessionId']);
        $this->text($control, 'A3', hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)));
        $this->text($control, 'A4', self::TEMPLATE_REVISION);
        $control->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function assertVersion(CashReconciliation $record, string $version): void
    {
        if (! hash_equals(hash('sha256', (string) $record->entregado_path), $version)) {
            throw ValidationException::withMessages(['arqueo' => 'La versión entregada cambió. Actualiza la página y revisa el archivo actual.']);
        }
    }

    private function storeBook(CashReconciliation $record, Spreadsheet $book, string $kind): string
    {
        $path = $record->id.'/'.$kind.'-'.Str::uuid().'.xlsx';
        $disk = Storage::disk(config('cash.disk'));
        $disk->makeDirectory((string) $record->id);
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($disk->path($path));

        return $path;
    }

    private function put(string $path, string $content): void
    {
        if (! Storage::disk(config('cash.disk'))->put($path, $content)) {
            throw new RuntimeException('No se pudo guardar el archivo del arqueo.');
        }
    }

    private function rememberFile(CashReconciliation $record, string $path, string $kind, User $actor): void
    {
        $record->update(['archivos' => [...($record->archivos ?? []), ['path' => $path, 'tipo' => $kind, 'usuario' => $actor->id, 'fecha' => now()->toIso8601String()]]]);
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no es un Excel XLSX válido.']);
        }
        try {
            $total = 0;
            if ($zip->numFiles > 1000) {
                throw new RuntimeException('Demasiados archivos internos.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                $name = strtolower($entry['name']);
                if ($total > 64 * 1024 * 1024 || str_contains($name, 'vbaproject') || str_starts_with($name, 'xl/externallinks/') || str_starts_with($name, 'xl/embeddings/')) {
                    throw new RuntimeException('Contenido no permitido.');
                }
                if ($name === '[content_types].xml' && preg_match('/macroEnabled|vbaProject/i', $zip->getFromIndex($i))) {
                    throw new RuntimeException('No se permiten macros.');
                }
                if (str_ends_with($name, '.rels') && preg_match('/TargetMode\s*=\s*["\x27]External|relationships\/(vbaProject|oleObject|package|externalLink)/i', $zip->getFromIndex($i))) {
                    throw new RuntimeException('No se permiten enlaces externos.');
                }
            }
            if ($zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('No contiene un libro Excel.');
            }
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['archivo' => 'Excel no permitido: '.$error->getMessage()]);
        } finally {
            $zip->close();
        }
    }

    private function validateWorkbook(Spreadsheet $expected, Spreadsheet $actual): void
    {
        if ($actual->getSheetNames() !== $expected->getSheetNames()) {
            throw ValidationException::withMessages(['archivo' => 'Conserva las hojas originales del archivo descargado.']);
        }
        if ((string) $expected->getSheetByName('_Control')->getCell('A4')->getValue() !== (string) $actual->getSheetByName('_Control')->getCell('A4')->getValue()) {
            throw ValidationException::withMessages(['archivo' => 'La plantilla cambió. Descarga el original actualizado y completa sus campos manuales antes de entregarlo.']);
        }
        $layout = ['own' => 0, 'third' => 0];
        $revision = (int) $expected->getSheetByName('_Control')->getCell('A4')->getValue();
        if ($revision >= 3) {
            $layout = $this->checkLayout($actual->getSheetByName('Arqueo'));
            $this->expandCheckRows($expected->getSheetByName('Arqueo'), $this->checkLayout($expected->getSheetByName('Arqueo')), $layout);
        }
        $pendingSums = [];
        foreach ($expected->getWorksheetIterator() as $sheet) {
            $other = $actual->getSheetByName($sheet->getTitle());
            $coordinates = array_unique([...$sheet->getCellCollection()->getCoordinates(), ...$other->getCellCollection()->getCoordinates()]);
            foreach ($coordinates as $coordinate) {
                $value = $other->getCell($coordinate)->getValue();
                $original = $sheet->getCell($coordinate)->getValue();
                $manual = $sheet->getTitle() === 'Arqueo' ? $this->manualType($coordinate, $layout) : null;
                if ($manual === 'sum' && $revision < 3) {
                    $manual = null;
                }
                if ($manual !== null) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if ($other->getCell($coordinate)->getDataType() === DataType::TYPE_FORMULA) {
                        if ($manual !== 'sum' || ! $this->validCheckSum($coordinate, (string) $value, $layout)) {
                            throw ValidationException::withMessages(['archivo' => 'Usa valores en las celdas editables. En los totales de cheques puedes usar SUMA únicamente sobre los importes de su propio bloque.']);
                        }
                        // Validate every input before evaluating any uploaded formula.
                        $pendingSums[] = [$other, $coordinate];

                        continue;
                    }
                    if ($manual !== 'text' && (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99 || ($manual === 'integer' && floor((float) $value) !== (float) $value))) {
                        throw ValidationException::withMessages(['archivo' => 'Revisa el valor contado en '.$coordinate.'.']);
                    }

                    continue;
                }
                $same = is_numeric($value) && is_numeric($original)
                    ? BigDecimal::of((string) $value)->isEqualTo((string) $original)
                    : (string) $value === (string) $original;
                if (! $same) {
                    throw ValidationException::withMessages(['archivo' => 'El archivo no corresponde al cierre o se modificó un valor del sistema ('.$sheet->getTitle().' '.$coordinate.').']);
                }
            }
        }
        foreach ($pendingSums as [$sheet, $coordinate]) {
            $value = $sheet->getCell($coordinate)->getCalculatedValue();
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99) {
                throw ValidationException::withMessages(['archivo' => 'Revisa la suma de cheques en '.$coordinate.'.']);
            }
        }
    }

    /** @return array{own: int, third: int} */
    private function checkLayout(Worksheet $sheet): array
    {
        $headers = ['CHEQUES AJENOS' => [], 'CUENTAS POR COBRAR' => []];
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            if (preg_match('/^B([0-9]+)$/', $coordinate, $match)) {
                $value = (string) $sheet->getCell($coordinate)->getValue();
                // The template also names third-party checks in its earlier breakdown.
                if (isset($headers[$value]) && (int) $match[1] >= ($value === 'CHEQUES AJENOS' ? 33 : 39)) {
                    $headers[$value][] = (int) $match[1];
                }
            }
        }
        if (count($headers['CHEQUES AJENOS']) !== 1 || count($headers['CUENTAS POR COBRAR']) !== 1) {
            throw ValidationException::withMessages(['archivo' => 'Conserva los encabezados originales e inserta filas solo antes del TOTAL de cada bloque de cheques.']);
        }
        $own = $headers['CHEQUES AJENOS'][0] - 33;
        $third = $headers['CUENTAS POR COBRAR'][0] - 39 - $own;
        if ($third < 0 || $own > self::MAX_EXTRA_CHECK_ROWS || $third > self::MAX_EXTRA_CHECK_ROWS) {
            throw ValidationException::withMessages(['archivo' => 'Puedes añadir hasta 1000 filas por bloque de cheques, conservando las filas originales.']);
        }

        return ['own' => $own, 'third' => $third];
    }

    /** @param array{own: int, third: int} $from
     * @param array{own: int, third: int} $to */
    private function expandCheckRows(Worksheet $sheet, array $from, array $to): void
    {
        if ($to['own'] < $from['own'] || $to['third'] < $from['third']) {
            throw ValidationException::withMessages(['archivo' => 'Conserva las filas originales del detalle de cheques.']);
        }
        if ($to['own'] > $from['own']) {
            $sheet->insertNewRowBefore(32 + $from['own'], $to['own'] - $from['own']);
        }
        if ($to['third'] > $from['third']) {
            $sheet->insertNewRowBefore(38 + $to['own'] + $from['third'], $to['third'] - $from['third']);
        }
    }

    /** @param array{own: int, third: int} $layout */
    private function validCheckSum(string $cell, string $formula, array $layout): bool
    {
        [, $row] = Coordinate::coordinateFromString($cell);
        $own = (int) $row === 32 + $layout['own'];
        $first = $own ? 29 : 35 + $layout['own'];
        $last = $own ? 31 + $layout['own'] : 37 + $layout['own'] + $layout['third'];
        if (! preg_match('/^=SUM\((.+)\)$/i', preg_replace('/\s+/', '', $formula), $sum)) {
            return false;
        }
        foreach (explode(',', $sum[1]) as $range) {
            if (! preg_match('/^\$?K\$?([0-9]+)(?::\$?K\$?([0-9]+))?$/i', $range, $match)) {
                return false;
            }
            $start = (int) $match[1];
            $end = (int) ($match[2] ?? $match[1]);
            if ($start < $first || $end > $last || $start > $end) {
                return false;
            }
        }

        return true;
    }

    /** @param array{own: int, third: int} $layout */
    private function manualType(string $cell, array $layout = ['own' => 0, 'third' => 0]): ?string
    {
        [$column, $row] = Coordinate::coordinateFromString($cell);
        $row = (int) $row;
        $col = Coordinate::columnIndexFromString($column);
        if ($column === 'K' && in_array($row, [32 + $layout['own'], 38 + $layout['own'] + $layout['third']], true)) {
            return 'sum';
        }
        if (($row >= 29 && $row <= 31 + $layout['own'])
            || ($row >= 35 + $layout['own'] && $row <= 37 + $layout['own'] + $layout['third'])) {
            return $col >= 3 && $col <= 11 ? ($col === 11 ? 'number' : 'text') : null;
        }
        if ($row >= 38 + $layout['own'] + $layout['third']) {
            $row -= $layout['own'] + $layout['third'];
        } elseif ($row >= 32 + $layout['own']) {
            $row -= $layout['own'];
        }
        if (in_array($row, [5, 8], true) && $col >= 4 && $col <= 9) {
            return 'integer';
        }
        if ($row >= 21 && $row <= 25 && $col >= 4 && $col <= 10) {
            return 'number';
        }
        if ($row >= 41 && $row <= 43 && $col >= 9 && $col <= 10) {
            return 'text';
        }
        if ($row >= 50 && $row <= 54 && $col >= 3 && $col <= 11) {
            return 'text';
        }

        return null;
    }
}
