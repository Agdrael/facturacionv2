<?php

namespace App\Services\Libreria;

use App\Models\Plataforma\Estudiante;
use App\Models\Plataforma\GradoEstudiante;
use App\Models\Plataforma\RolEstudiante;
use App\Models\Plataforma\SaldoPago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TuitionService
{
    public function __construct(private TuitionProductService $products) {}

    public const RESPONSIBLES = ['custom_275', 'custom_300', 'custom_304', 'clientes_varios', 'beca', 'bienhechor'];

    private const MONTHS = [
        1 => 'Matrícula', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre',
    ];

    private const ECONOMIC_RESPONSIBLES = [
        'custom_275' => ['role' => 'custom_53', 'name' => 'custom_275', 'documentType' => 'custom_335', 'document' => 'custom_276', 'email' => 'custom_277', 'payments' => 'custom_307'],
        'custom_300' => ['role' => 'custom_299', 'name' => 'custom_300', 'documentType' => 'custom_336', 'document' => 'custom_301', 'email' => 'custom_302', 'payments' => 'custom_308'],
        'custom_304' => ['role' => 'custom_303', 'name' => 'custom_304', 'documentType' => 'custom_337', 'document' => 'custom_305', 'email' => 'custom_306', 'payments' => 'custom_309'],
    ];

    private const CONTACTS = [
        'madre' => ['name' => 'custom_83', 'address' => 'custom_96', 'department' => 'custom_286', 'municipality' => 'custom_343', 'district' => 'custom_287', 'email' => 'custom_86', 'phone' => 'custom_84', 'phone2' => 'custom_97', 'phone3' => 'custom_98', 'document' => 'custom_103'],
        'padre' => ['name' => 'custom_80', 'address' => 'custom_105', 'department' => 'custom_288', 'municipality' => 'custom_344', 'district' => 'custom_289', 'email' => 'custom_85', 'phone' => 'custom_106', 'phone2' => 'custom_81', 'phone3' => 'custom_107', 'document' => 'custom_112'],
        'encargado (a)' => ['name' => 'custom_122', 'address' => 'custom_124', 'department' => 'custom_290', 'municipality' => 'custom_345', 'district' => 'custom_291', 'email' => 'custom_128', 'phone' => 'custom_125', 'phone2' => 'custom_126', 'phone3' => 'custom_127', 'document' => 'custom_133'],
    ];

    private const DOCUMENT_TYPES = [
        'dui' => '13', 'nit' => '36', 'carnet de residente' => '02', 'pasaporte' => '03', 'otro' => '37',
    ];

    private const SEARCH_DIACRITICS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        "\u{0301}" => '', "\u{0308}" => '', "\u{0303}" => '',
    ];

    public function schoolYear(): int
    {
        return now()->year - 1;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function students(string $search, int $page): LengthAwarePaginator
    {
        $students = $this->studentQuery()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    if (ctype_digit($search)) {
                        $query->where('students.student_id', $search);
                    } else {
                        foreach (preg_split('/\s+/u', $search) ?: [] as $word) {
                            $query->where(function (Builder $part) use ($word): void {
                                $this->whereStudentNameContains($part, $word);
                            });
                        }
                    }
                });
            })
            ->orderBy('students.last_name')->orderBy('students.first_name')->orderBy('students.student_id')
            ->paginate(10, ['*'], 'page', $page);

        return new LengthAwarePaginator(
            $students->getCollection()->map(fn (Estudiante $student): array => $this->studentData($student)),
            $students->total(), $students->perPage(), $students->currentPage(), $students->getOptions(),
        );
    }

    /** @param Builder<Estudiante> $query */
    private function whereStudentNameContains(Builder $query, string $word): void
    {
        $search = '%'.Str::lower(strtr($word, self::SEARCH_DIACRITICS)).'%';
        foreach (['"students"."first_name"', '"students"."middle_name"', '"students"."last_name"'] as $expression) {
            $bindings = [];
            foreach (self::SEARCH_DIACRITICS as $accent => $letter) {
                $expression = 'REPLACE('.$expression.', ?, ?)';
                $bindings[] = $accent;
                $bindings[] = $letter;
            }
            $query->orWhereRaw('LOWER('.$expression.') LIKE ?', [...$bindings, $search]);
        }
    }

    /** @return array<string, mixed> */
    public function details(string $studentId): array
    {
        $student = $this->studentQuery()->where('students.student_id', $studentId)->firstOrFail();
        $fieldsToLoad = ['student_id'];
        foreach ([...array_values(self::ECONOMIC_RESPONSIBLES), ...array_values(self::CONTACTS)] as $fields) {
            $fieldsToLoad = [...$fieldsToLoad, ...array_values($fields)];
        }
        $personal = Estudiante::query()->select(array_values(array_unique($fieldsToLoad)))->findOrFail($studentId);
        $responsibles = [];
        foreach (self::ECONOMIC_RESPONSIBLES as $id => $fields) {
            $role = trim((string) $personal->getAttribute($fields['role']));
            $contact = [];
            foreach (self::CONTACTS[Str::lower($role)] ?? [] as $key => $column) {
                $contact[$key] = trim((string) $personal->getAttribute($column));
            }
            $name = trim((string) $personal->getAttribute($fields['name'])) ?: ($contact['name'] ?? '');
            if ($role === '' && $name === '') {
                continue;
            }
            $documentType = trim((string) $personal->getAttribute($fields['documentType']));
            $responsibles[] = [
                'id' => $id,
                'label' => ($role ?: 'Responsable '.(count($responsibles) + 1)).($name !== '' ? ' · '.$name : ' · Sin datos de facturación'),
                'fundingType' => 'normal',
                'contact' => (object) $contact,
                'billing' => [
                    'name' => $name !== '' ? $name : 'Clientes varios',
                    'documentType' => self::DOCUMENT_TYPES[Str::lower($documentType)] ?? '',
                    'documentTypeLabel' => $documentType,
                    'document' => trim((string) $personal->getAttribute($fields['document'])),
                    'email' => trim((string) $personal->getAttribute($fields['email'])) ?: ($contact['email'] ?? ''),
                    'address' => $contact['address'] ?? '',
                    'department' => $contact['department'] ?? '',
                    'municipality' => $contact['municipality'] ?? '',
                    'district' => $contact['district'] ?? '',
                    'phone' => $contact['phone'] ?? '',
                    'phone2' => $contact['phone2'] ?? '',
                    'phone3' => $contact['phone3'] ?? '',
                ],
            ];
        }
        foreach (['clientes_varios' => 'Clientes varios', 'beca' => 'Beca', 'bienhechor' => 'Bienhechor'] as $id => $label) {
            $responsibles[] = [
                'id' => $id, 'label' => $label, 'fundingType' => $id === 'clientes_varios' ? 'normal' : $id,
                'billing' => ['name' => 'Clientes varios', 'document' => ''],
            ];
        }

        return ['student' => $this->studentData($student), 'responsibles' => $responsibles, 'schoolYear' => $this->schoolYear()];
    }

    /** @return array{fees: list<array<string, mixed>>, schoolYear: int} */
    public function fees(string $studentId, string $responsible): array
    {
        if (! in_array($responsible, self::RESPONSIBLES, true)) {
            throw ValidationException::withMessages(['responsable' => 'Selecciona un responsable válido.']);
        }
        $student = $this->studentQuery()->where('students.student_id', $studentId)->firstOrFail();
        $funding = match ($responsible) {
            'beca' => 'beca',
            'bienhechor' => 'bienhechor',
            default => 'normal',
        };
        [$quota, $paid, $balance] = match ($funding) {
            'beca' => ['cuota_beca', 'pago_beca', 'saldo_beca'],
            'bienhechor' => ['cuota_bienhechor', 'pago_bienhechor', 'saldo_bienhechor'],
            default => ['cuota', 'pago', 'saldo'],
        };
        $query = SaldoPago::query()->where('student_id', $studentId)->where('syear', $this->schoolYear());
        if ($funding === 'normal') {
            $query->where(function (Builder $query): void {
                $query->whereNotBetween('mes', [2, 11])->orWhere('mes', '>=', now()->month);
            });
        }
        if (isset(self::ECONOMIC_RESPONSIBLES[$responsible])) {
            $field = self::ECONOMIC_RESPONSIBLES[$responsible]['payments'];
            $assignments = Estudiante::query()->whereKey($studentId)->value($field);
            $query->whereIn('mes', $this->assignedMonths((string) $assignments));
        } elseif ($funding === 'beca') {
            $query->where('becado', true);
        } elseif ($funding === 'bienhechor') {
            $query->whereNotNull('cuota_bienhechor');
        }
        $products = $this->products->forStudent((int) $student->getAttribute('grade_id'), (string) $student->getAttribute('especialidad'), $funding);
        $fees = $query->orderBy('mes')->orderBy('id_saldo')
            ->get(['id_saldo', 'mes', 'codigo_barra', $quota, $paid, $balance])
            ->map(function (SaldoPago $row) use ($funding, $quota, $paid, $balance, $products): array {
                $month = (int) $row->getAttribute('mes');
                $id = (string) $row->getKey();
                $concept = match (true) {
                    $month === 1 => 'matricula', $month >= 2 && $month <= 11 => 'colegiatura', default => 'servicio_'.$month
                };
                $configuration = $products[$concept] ?? ['product' => null, 'error' => 'Falta configurar un producto para este servicio, grado, especialidad y responsable económico.'];

                return [
                    'id' => $funding.':'.$id,
                    'balanceId' => $id,
                    'code' => (string) ($row->getAttribute('codigo_barra') ?? ''),
                    'name' => $month === 1 ? 'Matrícula' : (isset(self::MONTHS[$month]) ? 'Colegiatura '.Str::lower(self::MONTHS[$month]) : 'Servicio '.$month),
                    'quota' => $this->cents($row->getAttribute($quota)),
                    'paid' => $this->cents($row->getAttribute($paid)),
                    'remaining' => max(0, $this->cents($row->getAttribute($balance))),
                    'month' => $month,
                    'schoolYear' => $this->schoolYear(),
                    'fundingType' => $funding,
                    'product' => $configuration['product'],
                    'configurationError' => $configuration['error'],
                    'canSelect' => $configuration['product'] !== null,
                ];
            })->values()->all();

        return ['fees' => array_values($fees), 'schoolYear' => $this->schoolYear()];
    }

    /** @return Builder<Estudiante> */
    private function studentQuery(): Builder
    {
        return Estudiante::query()
            ->join((new RolEstudiante)->getTable().' as enrollment', 'enrollment.student_id', '=', 'students.student_id')
            ->join((new GradoEstudiante)->getTable().' as grade', 'grade.id', '=', 'enrollment.grade_id')
            ->where('enrollment.syear', $this->schoolYear())
            ->select(['students.student_id', 'students.first_name', 'students.middle_name', 'students.last_name', 'enrollment.grade_id', 'enrollment.especialidad', 'enrollment.seccion', 'grade.title']);
    }

    /** @return array<string, mixed> */
    private function studentData(Estudiante $student): array
    {
        $name = implode(' ', array_filter([
            trim((string) $student->getAttribute('first_name')),
            trim((string) $student->getAttribute('middle_name')),
            trim((string) $student->getAttribute('last_name')),
        ], fn (string $part): bool => $part !== ''));

        return [
            'id' => (string) $student->getKey(), 'code' => (string) $student->getKey(), 'name' => $name,
            'grade' => (string) $student->getAttribute('title'),
            'specialty' => (string) $student->getAttribute('especialidad'),
            'section' => (string) $student->getAttribute('seccion'),
            'schoolYear' => $this->schoolYear(),
        ];
    }

    /** @return list<int> */
    private function assignedMonths(string $value): array
    {
        $tokens = preg_split('/\|\||[\r\n,;]+/u', $value) ?: [];
        $tokens = array_map(fn (string $token): string => Str::lower(Str::ascii(trim($token))), $tokens);
        $months = [];
        foreach (self::MONTHS as $month => $label) {
            if (in_array(Str::lower(Str::ascii($label)), $tokens, true)) {
                $months[] = $month;
            }
        }

        return $months;
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
