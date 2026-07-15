<?php

namespace Muni\Shared\Sso;

use Muni\Shared\RutHelper;

/**
 * Helpers para leer los claims de un id_token de Keycloak (OIDC) de forma unificada en
 * todo el ecosistema. Extraído de los KeycloakSsoController (antes idénticos y
 * duplicados en licencias/discapacidad/feria): la lógica sensible (verificación del
 * correo, RUT canónico, autorización por roles) vive y se testea en un solo lugar.
 *
 * Todos los métodos son puros (no tocan sesión/BD): reciben el array de claims ya
 * decodificado del id_token y devuelven valores; el controller decide qué hacer.
 */
class SsoClaims
{
    /**
     * ¿El IdP confirma que el correo está verificado? Keycloak entrega `email_verified`
     * como booleano. Si el claim falta se considera NO verificado (defensa en
     * profundidad: no crear/loguear cuentas con correos sin validar → anti-suplantación).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function emailVerificado(array $claims): bool
    {
        return ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? null) === 'true';
    }

    /**
     * RUT canónico de la persona, tomado del token y NORMALIZADO (RutHelper::normalize),
     * o null si el token no trae un RUT válido. Busca en los claims habituales del realm
     * municipal chileno: primero un claim explícito `rut`, luego `preferred_username`
     * (en Chile es común que el username del funcionario sea su RUT). Solo devuelve el
     * valor si pasa la validación de dígito verificador (evita guardar basura como RUT).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function rutDeClaims(array $claims): ?string
    {
        foreach (['rut', 'RUT', 'run', 'preferred_username'] as $clave) {
            $valor = $claims[$clave] ?? null;
            if (! is_string($valor) || $valor === '') {
                continue;
            }
            // preferred_username puede no ser un RUT (p. ej. un nombre de usuario): solo
            // se acepta si valida como RUT chileno. Para 'rut'/'run' también se valida.
            if (RutHelper::validate($valor)) {
                return RutHelper::normalize($valor);
            }
        }

        return null;
    }

    /**
     * Roles de la app (locales) que corresponden a este funcionario según el token de
     * Keycloak: traduce `resource_access.<clientId>.roles` con el `$mapa`
     * (rolKeycloak => rolLocal). Devuelve la lista de roles locales (sin repetir). Vacío
     * significa "sin autorización en este sistema" → el controller decide (p. ej. 403).
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, string>  $mapa  rolKeycloak => rolLocal
     * @return list<string>
     */
    public static function rolesLocalesDesdeKeycloak(array $claims, string $clientId, array $mapa): array
    {
        $rolesKeycloak = $claims['resource_access'][$clientId]['roles'] ?? [];
        if (! is_array($rolesKeycloak)) {
            return [];
        }

        $locales = [];
        foreach ($rolesKeycloak as $rol) {
            if (is_string($rol) && isset($mapa[$rol])) {
                $locales[] = $mapa[$rol];
            }
        }

        return array_values(array_unique($locales));
    }
}
