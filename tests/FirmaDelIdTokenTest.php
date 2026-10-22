<?php

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Muni\Shared\Sso\KeycloakSsoController;

/*
 * La FIRMA del id_token es lo que acredita QUIÉN es el funcionario.
 *
 * Antes de este arreglo, si la firma no se podía verificar —el JWKS caído, la
 * llave del realm rotada, o un id_token manipulado— el login se caía a
 * "decodificar sin firma" y SE ACEPTABA. Eso convierte un fallo de verificación
 * en un login válido, justo en la ventana en que alguien intentaría colarse.
 *
 * OIDC (Core 3.1.3.7) manda lo contrario: si la firma no valida, el id_token no
 * se acepta. Este archivo deja eso fijo.
 *
 * Es el hallazgo #10 del plan de arreglo que el propio phpstan.neon anota para
 * esta clase.
 */

beforeEach(function () {
    config([
        'services.keycloak.enabled' => true,
        'services.keycloak.realm' => 'municipio',
        'services.keycloak.client_id' => 'licencias',
        'services.keycloak.internal_base' => 'http://keycloak.test',
        'services.keycloak.redirect' => 'http://localhost/auth/sso/callback',
        'services.keycloak.public_base' => 'auto',
        'services.keycloak.public_port' => '8180',
    ]);

    // El JWKS se cachea una hora: cada prueba parte de una caché limpia.
    Cache::forget('keycloak_jwks');

    app('router')->get('/login', fn () => 'login')->name('ingresar');

    // La ruta del callback la registra cada sistema adoptante; acá se replica
    // con la pila `web`, que es la que trae la sesión.
    app('router')->get('/auth/sso/callback', [KeycloakSsoController::class, 'callback'])
        ->middleware('web')
        ->name('sso.callback');
});

/*
 * Dos pares de llaves RSA: la que el realm publica y otra que no.
 * El `kid` es el mismo en las dos a propósito: el atacante firma con SU llave
 * pero con el `kid` que el realm sí publica, y así el verificador elige la
 * llave publicada —y la comprobación de la firma es la que lo detiene.
 */
$generarLlaves = function (): array {
    $una = function (): array {
        $clave = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($clave, $pem);

        $detalles = openssl_pkey_get_details($clave);

        return [
            'pem' => $pem,
            'jwks' => [
                'keys' => [[
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => 'llave-de-prueba',
                    'n' => rtrim(strtr(base64_encode($detalles['rsa']['n']), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($detalles['rsa']['e']), '+/', '-_'), '='),
                ]],
            ],
        ];
    };

    return [$una(), $una()];
};

/*
 * Un id_token firmado con la llave dada.
 */
$idToken = function (string $pemPrivada, string $correo = 'funcionario@graneros.cl'): string {
    return JWT::encode([
        'email' => $correo,
        'email_verified' => true,
        'iat' => time(),
        'exp' => time() + 300,
    ], $pemPrivada, 'RS256', 'llave-de-prueba');
};

/*
 * EL VECTOR: un id_token firmado con una llave que el realm NO publica.
 *
 * El id_token llega por el token endpoint (server-to-server, HTTPS), así que el
 * atacante no puede inventarlo ahí: lo que pone es el `code` del callback. Este
 * caso cubre el otro lado de la moneda —la verificación que NO tiene por qué
 * pasar— y deja fijo que un token sin firma válida no abre sesión.
 */
it('un id_token firmado con una llave que el realm NO publica no inicia sesión', function () use ($generarLlaves, $idToken) {
    [$publicada, $ajena] = $generarLlaves();

    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['id_token' => $idToken($ajena['pem'])], 200),
        '*/protocol/openid-connect/certs' => Http::response($publicada['jwks'], 200),
    ]);

    $respuesta = $this->withSession(['sso_state' => 'un-estado'])
        ->get('/auth/sso/callback?state=un-estado&code=un-codigo');

    $respuesta->assertStatus(503);

    // Lo importante: no quedó nadie adentro.
    $this->assertGuest();
});

/*
 * El JWKS caído tampoco puede abrir la puerta: sin JWKS no hay con qué verificar
 * la firma, y el login se corta en vez de continuar sin ella.
 */
it('con el JWKS inalcanzable el login se corta en vez de continuar sin firma', function () use ($generarLlaves, $idToken) {
    [$publicada] = $generarLlaves();

    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['id_token' => $idToken($publicada['pem'])], 200),
        '*/protocol/openid-connect/certs' => Http::response('el realm no responde', 503),
    ]);

    $respuesta = $this->withSession(['sso_state' => 'un-estado'])
        ->get('/auth/sso/callback?state=un-estado&code=un-codigo');

    $respuesta->assertStatus(503);

    $this->assertGuest();
});

/*
 * La rotación de llaves NO puede dejar el login cortado: el JWKS está cacheado
 * una hora, y en esa hora el realm pudo rotar la llave de firma. Si el primer
 * intento falla, se descarta la caché y se reintenta contra el JWKS fresco.
 */
it('una rotación de llaves no deja el login cortado', function () use ($generarLlaves, $idToken) {
    [$nueva, $vieja] = $generarLlaves();

    // El primer JWKS es el viejo —el que estaba cacheado cuando rotó la llave—;
    // el segundo ya es el nuevo.
    Http::fake([
        '*/protocol/openid-connect/certs' => Http::sequence()
            ->push($vieja['jwks'], 200)
            ->push($nueva['jwks'], 200),
    ]);

    $controlador = new class extends KeycloakSsoController
    {
        /**
         * @return array<string,mixed>
         */
        public function claims(string $jwt): array
        {
            return $this->claimsDelIdToken($jwt);
        }
    };

    expect($controlador->claims($idToken($nueva['pem'])))
        ->toHaveKey('email');
});

/*
 * El camino feliz: con la firma verificada, las claims son las VERIFICADAS.
 */
it('con la firma verificada usa las claims verificadas', function () use ($generarLlaves, $idToken) {
    [$publicada] = $generarLlaves();

    Http::fake([
        '*/protocol/openid-connect/certs' => Http::response($publicada['jwks'], 200),
    ]);

    $controlador = new class extends KeycloakSsoController
    {
        /**
         * @return array<string,mixed>
         */
        public function claims(string $jwt): array
        {
            return $this->claimsDelIdToken($jwt);
        }
    };

    expect($controlador->claims($idToken($publicada['pem'])))
        ->toHaveKey('email', 'funcionario@graneros.cl');
});

/*
 * La puerta de escape, explícita: quien tenga el IdP caído y necesite aceptar el
 * id_token sin firma lo pide a propósito. Apagada por defecto.
 */
it('con el opt-in explícito vuelve a aceptar el id_token sin firma verificada', function () use ($generarLlaves, $idToken) {
    config(['services.keycloak.permitir_id_token_sin_firma' => true]);

    [$publicada, $ajena] = $generarLlaves();

    Http::fake([
        '*/protocol/openid-connect/certs' => Http::response($publicada['jwks'], 200),
    ]);

    $controlador = new class extends KeycloakSsoController
    {
        /**
         * @return array<string,mixed>
         */
        public function claims(string $jwt): array
        {
            return $this->claimsDelIdToken($jwt);
        }
    };

    expect($controlador->claims($idToken($ajena['pem'])))
        ->toHaveKey('email');
});

/*
 * Apagado por defecto: el opt-in no se prende solo.
 */
it('el opt-in para aceptar un id_token sin firma viene apagado', function () {
    expect((bool) config('services.keycloak.permitir_id_token_sin_firma', false))->toBeFalse();
});
