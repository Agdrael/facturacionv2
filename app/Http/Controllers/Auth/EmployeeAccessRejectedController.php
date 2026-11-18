<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;
use Symfony\Component\HttpFoundation\Response;

class EmployeeAccessRejectedController extends Controller
{
    public function __invoke(AuthKitLogoutRequest $request): Response
    {
        return $request->logout('/')
            ->withCookie(cookie('employee_access_denied', '1', 1));
    }
}
