<?php

namespace Muni\Shared\Testing;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * El contrato entre `config/` y `.env.example` de un sistema adoptante:
 * TODA clave que un `env(...)` real lee en `config/` tiene que estar
 * declarada en `.env.example`, aunque sea comentada.
 *
 * Nace de la auditoría del ecosistema (2026-08-30, hallazgo #15): licencias
 * tenía 134 claves fuera del ejemplo —incluidas `REVERB_APP_KEY` y las
 * `CLAVEUNICA_*`—, discapacidad 130, feria 125. Sin esto, quien clona el
 * repo copia un `.env.example` que parece completo y arranca con la
 * configuración de fábrica en variables que nunca se entera que existen: es
 * el mismo defecto de fondo que documenta
 * `gotcha_config_ausente_defaults_silenciosos`, pero a nivel de cada clave y
 * no de cada archivo.
 *
 * Vive en el paquete —no en cada sistema— para arreglarse una sola vez y
 * correr en los 12 CI: cada adoptante solo necesita `tests/Feature/
 * EnvExampleCompletoTest.php` con `assertEnvExampleCompleto()` (ver el trait
 * `AssertEnvExampleCompleto`).
 *
 * ## Por qué tokens y no una regex sobre el texto
 *
 * Una regex ingenua sobre `env\(\s*'([A-Z0-9_]+)'` se confunde con dos casos
 * reales del ecosistema: una llamada que quedó comentada al migrar un
 * servicio (`// env('LEGACY_TOKEN')`), y el nombre de una clave mencionado
 * dentro de un string de ayuda (`"Configura con env('X') en el shell"`). Los
 * dos parecen una llamada real y no lo son. El tokenizador de PHP separa
 * comentarios y strings en un único token cada uno —no vuelve a tokenizar su
 * contenido como código—, así que preguntar por la secuencia de tokens
 * `env`, `(`, string es la única forma de responder «¿esto se ejecuta?» sin
 * que un falso positivo obligue a documentar una clave que no existe.
 */
final class ContratoDeEnvExample
{
    /**
     * Las claves que algún `config/*.php` lee con `env(...)` y que
     * `.env.example` no declara, ni siquiera comentadas.
     *
     * @return list<string> ordenadas, sin repetir
     */
    public static function clavesFaltantes(string $rutaConfig, string $rutaEnvExample): array
    {
        $usadas = self::clavesUsadasEnConfig($rutaConfig);
        $documentadas = self::clavesDocumentadas($rutaEnvExample);

        $faltantes = array_values(array_diff($usadas, $documentadas));
        sort($faltantes);

        return $faltantes;
    }

    /** @return list<string> */
    private static function clavesUsadasEnConfig(string $rutaConfig): array
    {
        $claves = [];

        foreach (self::archivosPhp($rutaConfig) as $archivo) {
            foreach (self::clavesEnvDelArchivo((string) file_get_contents($archivo)) as $clave) {
                $claves[$clave] = true;
            }
        }

        return array_keys($claves);
    }

    /** @return list<string> rutas absolutas, ordenadas */
    private static function archivosPhp(string $directorio): array
    {
        if (! is_dir($directorio)) {
            return [];
        }

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directorio, FilesystemIterator::SKIP_DOTS),
        );

        $archivos = [];
        foreach ($iterador as $info) {
            if ($info->getExtension() === 'php') {
                $archivos[] = $info->getPathname();
            }
        }

        sort($archivos);

        return $archivos;
    }

    /**
     * Solo cuenta un `env(` que sea una llamada real: el identificador
     * `env`, seguido —salteando espacios— de `(` y de un string literal como
     * primer argumento. Cualquier otra forma (comentada, dentro de otro
     * string, `env($variable)`) no produce una clave.
     *
     * @return list<string>
     */
    private static function clavesEnvDelArchivo(string $codigo): array
    {
        $claves = [];
        $tokens = token_get_all($codigo);
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'env') {
                continue;
            }

            $j = self::saltarEspacios($tokens, $i + 1);

            if (! isset($tokens[$j]) || $tokens[$j] !== '(') {
                continue;
            }

            $j = self::saltarEspacios($tokens, $j + 1);

            if (! isset($tokens[$j]) || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $clave = trim($tokens[$j][1], "'\"");

            if ($clave !== '') {
                $claves[] = $clave;
            }
        }

        return $claves;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function saltarEspacios(array $tokens, int $desde): int
    {
        while (isset($tokens[$desde]) && is_array($tokens[$desde]) && $tokens[$desde][0] === T_WHITESPACE) {
            $desde++;
        }

        return $desde;
    }

    /**
     * Toda clave `NOMBRE=` al principio de una línea de `.env.example`,
     * comentada o no: `#CSP_ENABLED=` documenta la clave igual que
     * `CSP_ENABLED=false` —es el patrón que ya usan los sistemas para las
     * banderas opcionales—.
     *
     * @return list<string>
     */
    private static function clavesDocumentadas(string $rutaEnvExample): array
    {
        if (! is_file($rutaEnvExample)) {
            return [];
        }

        $contenido = (string) file_get_contents($rutaEnvExample);
        preg_match_all('/^\s*#*\s*([A-Z0-9_]+)\s*=/m', $contenido, $coincidencias);

        return $coincidencias[1];
    }
}
