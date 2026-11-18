<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Services\Libreria\TuitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentTuitionController extends Controller
{
    public function __invoke(Request $request, string $student, TuitionService $tuition): JsonResponse
    {
        $validated = $request->validate([
            'responsable' => ['required', 'string', Rule::in(TuitionService::RESPONSIBLES)],
        ]);

        return response()->json($tuition->fees($student, $validated['responsable']));
    }
}
