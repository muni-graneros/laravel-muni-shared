<?php

namespace Muni\Shared\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inicio de sesión con la CUENTA MUNICIPAL (Keycloak, OIDC Authorization Code).
 *
 * Base COMPARTIDA del ecosistema: antes este controller estaba duplicado casi carácter
 * por carácter en licencias, discapacidad y feria (~400 líneas × 3, solo diferían
 * comentarios). Al ser código de AUTENTICACIÓN, cada endurecimiento había que aplicarlo
 * tres veces y bastaba olvidar uno para dejar un sistema atrás. Ahora vive y se testea
 * en un solo lugar; cada sistema lo extiende con una clase vacía.
 *
 * SSO = AUTENTICACIÓN, no autorización. El callback identifica al funcionario por
 * email y lo loguea; si no existe, lo crea SIN roles. El acceso a paneles/datos
 * lo siguen dando los roles/permisos LOCALES (clave para Ley 21.719): un login
 * válido no implica acceso a nada sensible.
 *
 * El modelo de usuario se resuelve de `auth.providers.users.model`, así cada sistema
 * usa el suyo sin tocar esta clase.
 */
class KeycloakSsoController
{
    /**
     * Modelo de usuario del sistema que extiende esta base. Se lee de la config de
     * auth para NO acoplar el paquete al namespace `App\` de ningún sistema; un
     * sistema con otra convención puede sobrescribir este método.
     *
     * @return class-string<Model>
     */
    protected function modeloUsuario(): string
    {
        $modelo = config('auth.providers.users.model');

        // Sin modelo configurado no se puede crear ni buscar al funcionario: es un
        // error de configuración, y fallar aquí es preferible a loguear a nadie.
        if (! is_string($modelo) || ! class_exists($modelo)) {
            throw new \RuntimeException('SSO: no hay modelo de usuario en auth.providers.users.model.');
        }

        /** @var class-string<Model> $modelo */
        return $modelo;
    }

    protected function realmUrl(string $base): string
    {
        return rtrim($base, '/').'/realms/'.config('services.keycloak.realm').'/protocol/openid-connect';
    }

    /**
     * Base PÚBLICA de Keycloak (la que abre el navegador). En modo `auto` se deriva
     * del host desde el que se accede: mismo esquema+host que la app, con el puerto
     * de Keycloak. Así el SSO funciona por localhost, por LAN (192.168.x) o por un
     * dominio/túnel — sin reconfigurar. Si se define una URL fija (p.ej. detrás del
     * ingress: https://sso.dominio) se usa esa.
     */
    protected function publicBase(Request $request): string
    {
        $conf = (string) config('services.keycloak.public_base');

        if ($conf !== '' && $conf !== 'auto') {
            return $conf;
        }

        $port = (string) config('services.keycloak.public_port', '8180');
        $host = $request->getHost();
        $scheme = $request->getScheme();

        // Si viene por un proxy/túnel con puerto estándar (80/443), se asume que
        // Keycloak está tras el mismo host (ingress) y no se añade puerto.
        return in_array($request->getPort(), [80, 443, null], true) && $port === ''
            ? $scheme.'://'.$host
            : $scheme.'://'.$host.':'.$port;
    }

    /**
     * redirect_uri del callback. En `auto` se arma con el host actual (mismo origen
     * que el navegador), para que coincida por localhost/LAN/dominio.
     */
    protected function redirectUri(Request $request): string
    {
        $conf = (string) config('services.keycloak.redirect');

        return ($conf !== '' && $conf !== 'auto')
            ? $conf
            : $request->getSchemeAndHttpHost().'/auth/sso/callback';
    }

    public function redirigir(Request $request): Redirector|RedirectResponse
    {
        abort_unless(config('services.keycloak.enabled'), 404);

        $state = Str::random(40);
        $request->session()->put('sso_state', $state);
        // Modo ventana emergente (estilo Google): el callback cerrará el popup.
        $request->session()->put('sso_popup', $request->boolean('popup'));
        // Modo silencioso: comprueba si ya hay sesión en el IdP SIN mostrar nada
        // (prompt=none). Sirve para el auto-login al abrir otro sistema.
        $silent = $request->boolean('silent');
        $request->session()->put('sso_silent', $silent);

        // El redirect_uri se resuelve del host actual y se guarda: el callback debe
        // usar EXACTAMENTE el mismo valor en el intercambio del código.
        $redirect = $this->redirectUri($request);
        $request->session()->put('sso_redirect', $redirect);

        $params = array_filter([
            'client_id' => config('services.keycloak.client_id'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $redirect,
            'state' => $state,
            'prompt' => $silent ? 'none' : null,
        ]);
        $params = http_build_query($params);

        // El navegador va a la URL pública del IdP (derivada del host de acceso).
        return redirect($this->realmUrl($this->publicBase($request)).'/auth?'.$params);
    }

    public function callback(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(config('services.keycloak.enabled'), 404);
        abort_unless(
            $this->stateValido($request->query('state'), $request->session()->pull('sso_state')),
            403,
            'Estado inválido.'
        );

        $silent = (bool) $request->session()->pull('sso_silent');

        // En modo silencioso (prompt=none), si el IdP no tenía sesión devuelve un
        // `error` (login_required): no es un fallo, solo significa "no hay SSO
        // activo"; se vuelve al login normal marcando que ya se intentó (evita bucle).
        if ($request->query('error')) {
            if ($silent) {
                // route('ingresar') respeta el path de cada sistema (/login o
                // /ingresar); antes estaba hardcodeado a /login → 404 en disc/feria.
                return redirect(route('ingresar').'?sso=off');
            }
            abort(403, 'No se pudo iniciar sesión con la cuenta municipal.');
        }

        $code = $request->query('code');
        abort_unless(is_string($code) && $code !== '', 400, 'Falta el código de autorización.');

        // Intercambio de código y userinfo: server-side, por la URL interna.
        $base = $this->realmUrl(config('services.keycloak.internal_base'));

        $token = Http::asForm()->post($base.'/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.keycloak.client_id'),
            'client_secret' => config('services.keycloak.client_secret'),
            // MISMO redirect_uri que en el authorize (guardado en sesión): OIDC exige
            // que coincidan. En modo `auto` depende del host, por eso no se recalcula.
            'redirect_uri' => $request->session()->pull('sso_redirect') ?: $this->redirectUri($request),
        ])->throw()->json();

        // Las claims (email/nombre) se leen del id_token. Se VERIFICA su firma contra
        // el JWKS del realm; si el JWKS no está disponible se cae a decodificar sin
        // firma (el token igual llegó server-to-server por HTTPS desde el token
        // endpoint, así que sigue siendo confiable). Defensa en profundidad.
        $info = $this->verificarFirmaJwt($token['id_token'] ?? '')
            ?? $this->claimsDeIdToken($token['id_token'] ?? '');

        $email = $info['email'] ?? null;
        abort_unless(is_string($email) && $email !== '', 422, 'La cuenta municipal no entregó un correo.');

        // Anti-suplantación: el correo debe venir VERIFICADO por el IdP. Con el flag
        // apagado se mantiene el comportamiento previo (no exigirlo), por si un realm no
        // marca el claim; en producción debe quedar exigido.
        if (config('services.keycloak.exigir_email_verificado', true)) {
            abort_unless(SsoClaims::emailVerificado($info), 403, 'La cuenta municipal no tiene el correo verificado.');
        }

        $modelo = $this->modeloUsuario();

        // Identidad canónica por RUT: si el token trae un RUT válido y ya existe un
        // funcionario con ese RUT, se usa ESE (aunque el correo haya cambiado en el IdP).
        // Si no, se vincula por correo. La cuenta nueva se crea SIN roles: podrá iniciar
        // sesión, pero no verá paneles ni datos hasta que se le asigne un rol local.
        $rut = SsoClaims::rutDeClaims($info);
        $user = ($rut !== null ? $modelo::where('rut', $rut)->first() : null)
            ?? $modelo::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $info['name'] ?? trim(($info['given_name'] ?? '').' '.($info['family_name'] ?? '')) ?: $email,
                    'password' => Hash::make(Str::random(40)),
                ],
            );

        // Completa el RUT del maestro de identidad si faltaba (enlace one-way, no
        // sobrescribe uno ya cargado para no romper un enlace manual previo).
        if ($rut !== null && blank($user->rut)) {
            $user->rut = $rut;
            $user->save();
        }

        // Autorización POR ROLES DE KEYCLOAK (flag): sincroniza los roles locales desde
        // el token en cada login, de modo que la baja/cambio de rol en Keycloak propague
        // sola. Con el flag apagado, los roles se gestionan localmente (comportamiento
        // actual). Si el token no trae ningún rol de este sistema, el usuario queda sin
        // roles y el bloque de abajo lo devuelve al login con «sin acceso».
        if (config('services.keycloak.roles_desde_keycloak', false)) {
            // Los roles de cliente (`resource_access`) viajan en el ACCESS token, no en el
            // id_token (verificado contra el realm real). Se decodifica ese token para leerlos.
            $accessClaims = $this->claimsDeIdToken($token['access_token'] ?? '');
            $rolesLocales = SsoClaims::rolesLocalesDesdeKeycloak(
                $accessClaims,
                (string) config('services.keycloak.client_id'),
                (array) config('services.keycloak.roles_map', []),
            );
            $user->syncRoles($rolesLocales);
        }

        $esPopup = (bool) $request->session()->pull('sso_popup');

        // Sin rol local NO se inicia sesión (homePath llevaría a un 403 seco).
        // La cuenta queda creada para que el administrador le asigne un rol, y se
        // vuelve al login con un mensaje claro (el partial sso-boton lo muestra).
        if ($user->getRoleNames()->isEmpty()) {
            $destino = route('ingresar').'?sso=sin-acceso';

            return $esPopup ? $this->cerrarPopup($destino) : redirect()->to($destino);
        }

        Auth::login($user, remember: true);

        // El SSO ya autenticó al funcionario: se marca el MFA local como cumplido
        // (el segundo factor pasará a vivir en el IdP — fase 3 de docs/SSO.md).
        // Sin esto, disc/feria rebotarían al usuario a la pantalla de código OTP.
        $request->session()->put('auth.two_factor_verified', true);

        // Marca que ESTA sesión vino del SSO → al cerrar sesión (app o Filament)
        // se hace SINGLE LOGOUT (también en Keycloak), si no el auto-login volvería
        // a entrar de inmediato (bug: "me deslogueo y se vuelve a loguear"). Se usa
        // una COOKIE (no la sesión) porque Filament invalida la sesión antes de
        // resolver su LogoutResponse, y la cookie sobrevive a esa invalidación.
        Cookie::queue('muni_sso', '1', 60 * 24);
        // El id_token se guarda para pasarlo como `id_token_hint` en el logout: así
        // Keycloak cierra la sesión SIN pedir confirmación (~1.1 KB, cabe en cookie).
        Cookie::queue('muni_sso_idt', (string) ($token['id_token'] ?? ''), 60 * 24);

        // Mapa sid-de-Keycloak → sesión local, para el single logout por back-channel
        // (salir de un sistema cierra la sesión en todos). Se guarda tras el login
        // (Auth::login regeneró el id de sesión).
        if (! empty($info['sid'])) {
            DB::table('sso_sessions')->insert([
                'kc_sid' => $info['sid'],
                'session_id' => $request->session()->getId(),
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $destino = $user->homePath();

        return $esPopup ? $this->cerrarPopup($destino) : redirect()->intended($destino);
    }

    /**
     * ¿El `state` del callback corresponde al flujo que ESTE navegador inició?
     *
     * Comparar con `===` a secas era evitable: si la víctima nunca inició el flujo,
     * la sesión no tiene `sso_state` y `pull()` devuelve null; si el atacante omite
     * el parámetro, `query('state')` también es null → `null === null` daba por
     * válido el callback (login CSRF: se podía forzar el navegador de la víctima a
     * `/auth/sso/callback?code=<código del atacante>` y dejarla dentro de la cuenta
     * del ATACANTE, con todo lo que subiera después guardado ahí).
     *
     * Por eso se exige que AMBOS sean strings no vacíos antes de compararlos, y la
     * comparación se hace en tiempo constante.
     */
    protected function stateValido(mixed $enviado, mixed $enSesion): bool
    {
        if (! is_string($enviado) || ! is_string($enSesion)) {
            return false;
        }

        if ($enviado === '' || $enSesion === '') {
            return false;
        }

        return hash_equals($enSesion, $enviado);
    }

    /**
     * Cierra la ventana emergente del SSO y navega la ventana padre al destino
     * (la sesión ya viajó en la cookie: mismo origen que la ventana padre).
     */
    protected function cerrarPopup(string $destino): Response
    {
        return response(
            '<!doctype html><meta charset="utf-8"><body><script>'
            .'try{(window.opener||window.top).location="'.e($destino).'";}catch(e){}'
            .'window.close();location="'.e($destino).'";'
            .'</script>Entrando…</body>'
        );
    }

    /**
     * Back-channel logout: Keycloak llama a este endpoint (server-to-server) cuando
     * el usuario cierra sesión en CUALQUIER sistema. Trae un `logout_token` (JWT)
     * con el `sid` de la sesión SSO; se destruyen las sesiones locales asociadas a
     * ese `sid`, dejando al usuario fuera de este sistema de inmediato (single
     * logout estilo Google). Sin cookie de por medio: es una llamada de KC, no del
     * navegador.
     */
    public function backchannel(Request $request): ResponseFactory|Response
    {
        abort_unless(config('services.keycloak.enabled'), 404);

        // Se VERIFICA la FIRMA del logout_token contra el JWKS del realm: este
        // endpoint es server-to-server y sin firma un atacante en la red podría
        // forjar un logout. Si la firma falla → 400 (no se cierra nada).
        $claims = $this->verificarFirmaJwt((string) $request->input('logout_token'));
        if ($claims === null) {
            return response('', 400);
        }

        // El issuer debe pertenecer al realm esperado (no se exige el host exacto
        // para que funcione en cualquier modo — localhost/LAN/dominio).
        $iss = (string) ($claims['iss'] ?? '');
        $issOk = str_ends_with($iss, '/realms/'.config('services.keycloak.realm'));
        $sid = $claims['sid'] ?? null;

        if (! $issOk || ! is_string($sid) || $sid === '') {
            return response('', 400);
        }

        $handler = $request->session()->getHandler();
        $filas = DB::table('sso_sessions')->where('kc_sid', $sid)->get();
        foreach ($filas as $fila) {
            $handler->destroy($fila->session_id); // saca la sesión del store (redis/db)
        }
        DB::table('sso_sessions')->where('kc_sid', $sid)->delete();

        return response('', 200);
    }

    /**
     * Decodifica el payload de un JWT (id_token) sin verificar firma. Seguro aquí
     * porque el token llega directo del token endpoint por una llamada del servidor.
     *
     * @return array<string,mixed>
     */
    protected function claimsDeIdToken(string $jwt): array
    {
        $partes = explode('.', $jwt);
        if (count($partes) < 2) {
            return [];
        }
        $payload = base64_decode(strtr($partes[1], '-_', '+/'), true);

        return is_string($payload) ? (array) json_decode($payload, true) : [];
    }

    /**
     * Verifica la FIRMA de un JWT contra el JWKS del realm de Keycloak y devuelve
     * los claims si es válida (o null si la firma/expiración fallan). El JWKS se
     * cachea 1h (Keycloak rota las llaves de vez en cuando). Se consulta por la URL
     * INTERNA (server-to-server), no la pública.
     *
     * @return array<string,mixed>|null
     */
    protected function verificarFirmaJwt(string $jwt): ?array
    {
        try {
            $jwks = Cache::remember('keycloak_jwks', now()->addHour(), function (): array {
                $url = $this->realmUrl(config('services.keycloak.internal_base')).'/certs';

                return Http::timeout(5)->get($url)->throw()->json();
            });

            // Keycloak firma con RS256; parseKeySet arma las llaves públicas del JWKS.
            $llaves = JWK::parseKeySet($jwks);
            $decoded = JWT::decode($jwt, $llaves);

            return json_decode((string) json_encode($decoded), true);
        } catch (\Throwable $e) {
            Log::warning('Firma JWT inválida o JWKS inaccesible.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function logout(Request $request): Redirector|RedirectResponse
    {
        $vinoDeSso = $request->cookie('muni_sso') === '1';
        // La cookie puede llegar como array (manipulada); solo un string es un id_token.
        $idtCookie = $request->cookie('muni_sso_idt');
        $idToken = is_string($idtCookie) ? $idtCookie : '';
        $base = $this->publicBase($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget('muni_sso'));
        Cookie::queue(Cookie::forget('muni_sso_idt'));

        return redirect(static::urlLogout($vinoDeSso, $idToken, $base));
    }

    /**
     * Base pública de Keycloak resuelta desde el request (para el modo `auto`);
     * pública para que el LogoutResponse de Filament la calcule sin instanciar.
     */
    public static function publicBaseFrom(Request $request): string
    {
        return (new static)->publicBase($request);
    }

    /**
     * URL de cierre de sesión. Con `$singleLogout` (la sesión vino del SSO) apunta
     * al logout de Keycloak: cierra la sesión municipal y vuelve al login local, así
     * el auto-login no re-entra. Se pasa `id_token_hint` para que Keycloak cierre
     * SIN página de confirmación. Si la sesión fue local, va directo al login local
     * (no se toca la sesión del IdP, que podría ser de otro sistema). Reutilizada por
     * el LogoutResponse de Filament y la ruta /salir.
     */
    public static function urlLogout(bool $singleLogout = true, string $idToken = '', string $publicBase = ''): string
    {
        $login = route('ingresar');

        if (! $singleLogout || ! config('services.keycloak.enabled')) {
            return $login;
        }

        // En modo `auto` la base la resuelve el caller desde el request; si no, config.
        $base = $publicBase !== '' ? $publicBase : (string) config('services.keycloak.public_base');
        $realm = rtrim($base, '/').'/realms/'.config('services.keycloak.realm').'/protocol/openid-connect';

        return $realm.'/logout?'.http_build_query(array_filter([
            'client_id' => config('services.keycloak.client_id'),
            'post_logout_redirect_uri' => $login,
            'id_token_hint' => $idToken ?: null,
        ]));
    }
}
