<?php

use Illuminate\Http\Request;
use Muni\Shared\Sso\KeycloakSsoController;

/**
 * Contrato de la base compartida del SSO. Lo que se fija aquí es lo que los tres
 * sistemas daban por sentado cuando cada uno tenía su copia del controller.
 */
beforeEach(function () {
    config([
        'services.keycloak.enabled' => true,
        'services.keycloak.realm' => 'municipio',
        'services.keycloak.client_id' => 'licencias',
        'services.keycloak.public_base' => 'auto',
        'services.keycloak.public_port' => '8180',
    ]);

    app('router')->get('/login', fn () => 'login')->name('ingresar');
});

it('en modo auto deriva la base pública del host de acceso', function () {
    $request = Request::create('http://192.168.1.50:8000/auth/sso');

    // Mismo esquema+host que la app, con el puerto de Keycloak: así el SSO sirve
    // por localhost, por LAN y por dominio sin reconfigurar.
    expect(KeycloakSsoController::publicBaseFrom($request))
        ->toBe('http://192.168.1.50:8180');
});

it('respeta una base pública fija cuando no es auto', function () {
    config(['services.keycloak.public_base' => 'https://sso.graneros.cl']);

    expect(KeycloakSsoController::publicBaseFrom(Request::create('http://localhost:8000/')))
        ->toBe('https://sso.graneros.cl');
});

it('sin single logout vuelve al login local y NO toca la sesión del IdP', function () {
    // La sesión no vino del SSO: cerrar la sesión del IdP sacaría al funcionario de
    // los OTROS sistemas donde sí la abrió.
    expect(KeycloakSsoController::urlLogout(singleLogout: false))->toBe(route('ingresar'));
});

it('con SSO deshabilitado nunca arma una URL de Keycloak', function () {
    config(['services.keycloak.enabled' => false]);

    expect(KeycloakSsoController::urlLogout(singleLogout: true))->toBe(route('ingresar'));
});

it('el single logout pasa el id_token_hint para no pedir confirmación', function () {
    $url = KeycloakSsoController::urlLogout(true, 'ID.TOKEN.AQUI', 'https://sso.graneros.cl');

    expect($url)
        ->toContain('https://sso.graneros.cl/realms/municipio/protocol/openid-connect/logout')
        ->toContain('id_token_hint=ID.TOKEN.AQUI')
        ->toContain('client_id=licencias')
        ->toContain('post_logout_redirect_uri=');
});

it('omite el id_token_hint cuando no hay token guardado', function () {
    expect(KeycloakSsoController::urlLogout(true, '', 'https://sso.graneros.cl'))
        ->not->toContain('id_token_hint');
});

it('publicBaseFrom se resuelve sobre la subclase (new static, no new self)', function () {
    // Cada sistema extiende esta base; si se usara `new self` la subclase que
    // sobrescriba publicBase() quedaría ignorada en el estático.
    $subclase = new class extends KeycloakSsoController
    {
        protected function publicBase(Request $request): string
        {
            return 'https://sobrescrito.test';
        }
    };

    expect($subclase::publicBaseFrom(Request::create('http://localhost/')))
        ->toBe('https://sobrescrito.test');
});
