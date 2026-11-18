<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoginPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $response = response()->view('auth.login', [
            'accessDenied' => $request->cookie('employee_access_denied') === '1',
        ]);

        if ($request->hasCookie('employee_access_denied')) {
            $response->withoutCookie('employee_access_denied');
        }

        return $response;
    }
}
