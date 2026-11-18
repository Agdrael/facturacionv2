<?php

namespace App\Services\Libreria;

use App\Models\Dte\Cat002;
use App\Models\Dte\Cat016;
use App\Models\Dte\Cat017;
use App\Models\Dte\Cat018;
use App\Models\Dte\Cat019;
use App\Models\Dte\Cat022;
use App\Services\GeographyService;

class SaleCatalogService
{
    public function __construct(private GeographyService $geography) {}

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'documents' => Cat002::catalogOptions()->whereInStrict('codigo', ['01', '03'])->values()->all(),
            'identityDocuments' => Cat022::catalogOptions()->all(),
            'paymentMethods' => Cat017::catalogOptions()->all(),
            'operations' => Cat016::catalogOptions()->all(),
            'creditTerms' => Cat018::catalogOptions()->all(),
            'economicActivities' => Cat019::catalogOptions()->all(),
            'geography' => $this->geography->options(),
        ];
    }
}
