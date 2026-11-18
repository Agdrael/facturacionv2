<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Services\Libreria\TuitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request, TuitionService $tuition): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($tuition->students(trim($validated['q'] ?? ''), (int) ($validated['page'] ?? 1)));
    }

    public function show(string $student, TuitionService $tuition): JsonResponse
    {
        return response()->json($tuition->details($student));
    }
}
