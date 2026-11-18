<?php

use App\Models\User;

test('guests can open the login page and continue to the existing login route', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Inicia sesión')
        ->assertSee('href="'.route('login').'"', false)
        ->assertDontSee('Acceso no autorizado');
});

test('the login page shows the rejected access alert and consumes its flag', function () {
    $response = $this->withCookie('employee_access_denied', '1')
        ->get(route('home'));

    $response->assertOk()
        ->assertSee('Acceso no autorizado')
        ->assertSee('Tu cuenta no tiene acceso al sistema.')
        ->assertCookieExpired('employee_access_denied');
});

test('an inactive rejection flag does not display an alert', function () {
    $response = $this->withCookie('employee_access_denied', '0')
        ->get(route('home'));

    $response->assertOk()
        ->assertDontSee('Acceso no autorizado')
        ->assertCookieExpired('employee_access_denied');
});

test('rejected users are logged out and sent to the login page with the alert flag', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('employee-access.rejected'));

    $response->assertRedirect(route('home'))
        ->assertCookie('employee_access_denied', '1');

    $this->assertGuest();
});
