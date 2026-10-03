<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

/**
 * Login CSRF: el `state` es lo único que ata el callback al flujo que ESTE navegador
 * inició. Si un callback sin `state` pasa la comprobación, un atacante puede forzar
 * el navegador de la víctima a /auth/sso/callback?code=<código del atacante> y dejarla
 * con la sesión del ATACANTE iniciada; todo lo que la víctima haga después (subir
 * documentos, crear solicitudes) queda en la cuenta del atacante.
 */
it('la comparación de state no acepta nulos ni vacíos', function (mixed $enviado, mixed $enSesion) {
    $controller = new class extends KeycloakSsoController
    {
        public function comparar(mixed $enviado, mixed $enSesion): bool
        {
            return $this->stateValido($enviado, $enSesion);
        }
    };

    expect($controller->comparar($enviado, $enSesion))->toBeFalse();
})->with([
    'ambos nulos (el bypass)' => [null, null],
    'ambos vacíos' => ['', ''],
    'sesión vacía' => ['abc', ''],
    'sesión nula' => ['abc', null],
    'enviado nulo' => [null, 'abc'],
    'enviado array' => [['abc'], 'abc'],
]);

it('acepta el state legítimo', function () {
    $controller = new class extends KeycloakSsoController
    {
        public function comparar(mixed $enviado, mixed $enSesion): bool
        {
            return $this->stateValido($enviado, $enSesion);
        }
    };

    expect($controller->comparar('token-de-estado-largo', 'token-de-estado-largo'))->toBeTrue();
});

/**
 * laravel-muni-mfa guarda el id del usuario verificado (string) y lo compara en
 * estricto; los sistemas que aún tienen su middleware local esperan `true`.
 */
it('con laravel-muni-mfa instalado el SSO marca la sesión con el id del usuario como string', function () {
    config(['muni-mfa.enabled' => false]);
    Auth::setUser(new GenericUser(['id' => 42]));
    $request = Request::create('http://localhost/auth/sso/callback');
    $request->setLaravelSession(app('session.store'));

    $controller = new class extends KeycloakSsoController
    {
        public function marcar(Request $request): void
        {
            $this->marcarSegundoFactor($request);
        }
    };
    $controller->marcar($request);

    expect($request->session()->get('auth.two_factor_verified'))->toBe('42');
});

it('sin laravel-muni-mfa el SSO sigue marcando la sesión con true', function () {
    expect(config()->has('muni-mfa.enabled'))->toBeFalse();
    Auth::setUser(new GenericUser(['id' => 42]));
    $request = Request::create('http://localhost/auth/sso/callback');
    $request->setLaravelSession(app('session.store'));

    $controller = new class extends KeycloakSsoController
    {
        public function marcar(Request $request): void
        {
            $this->marcarSegundoFactor($request);
        }
    };
    $controller->marcar($request);

    expect($request->session()->get('auth.two_factor_verified'))->toBeTrue();
});
