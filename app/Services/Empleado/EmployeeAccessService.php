<?php

namespace App\Services\Empleado;

use App\Models\Dte\Empleado;
use App\Models\User;

class EmployeeAccessService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function employeeFor(?User $user): ?Empleado
    {
        $email = $this->normalizeEmail($user?->email ?? '');
        if ($email === null || ! $this->hasAllowedDomain($email)) {
            return null;
        }

        return Empleado::query()
            ->where('correo', $email)
            ->first();
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = trim($email);
        $email = strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function hasAllowedDomain(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return $domain === config('employee-access.allowed_domain');
    }
}
