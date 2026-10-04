<?php

declare(strict_types=1);

/**
 * Patrón «env vacía pisa el default»: con una variable DEFINIDA PERO VACÍA en el
 * entorno (`X=` en un compose o en un `.env` copiado del ejemplo), `env()`
 * devuelve '' y no el valor por omisión. En `config/privacidad.php` eso cambiaba
 * cosas que la Ley 21.719 no deja al azar:
 *  - PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD vacía dejaba de suspender el
 *    tratamiento mientras se resuelve una rectificación u oposición;
 *  - PRIVACIDAD_PLAZO_RESPUESTA_DIAS y _NOTIFICACION_BRECHA_DIAS vacías valían 0:
 *    toda solicitud nacía vencida y toda brecha con plazo cumplido;
 *  - PRIVACIDAD_RETENCION_CANDADO_SEGUNDOS vacía valía 0 y un candado de 0 s
 *    en Laravel no vence nunca: una corrida muerta dejaba la retención parada.
 * Acá se carga el archivo de config con el entorno manipulado a mano.
 */
if (! function_exists('configDePrivacidadConEntorno')) {
    /**
     * @param  array<string, string|null>  $entorno  variable => valor; null = ausente
     * @return array<string, mixed>
     */
    function configDePrivacidadConEntorno(array $entorno): array
    {
        // env() de Laravel lee $_SERVER, $_ENV y getenv(): hay que fijar (y
        // restaurar) los tres, o el entorno real del proceso se cuela en el test.
        $previos = [];
        foreach (array_keys($entorno) as $variable) {
            $previos[$variable] = [$_SERVER[$variable] ?? null, $_ENV[$variable] ?? null, getenv($variable)];
        }

        $fijar = static function (string $variable, string|false|null $valor): void {
            if ($valor === null || $valor === false) {
                unset($_SERVER[$variable], $_ENV[$variable]);
                putenv($variable);
            } else {
                $_SERVER[$variable] = $valor;
                $_ENV[$variable] = $valor;
                putenv($variable.'='.$valor);
            }
        };

        try {
            foreach ($entorno as $variable => $valor) {
                $fijar($variable, $valor);
            }

            return require dirname(__DIR__, 2).'/config/privacidad.php';
        } finally {
            foreach ($previos as $variable => [$server, $env, $getenv]) {
                $fijar($variable, $getenv);
                foreach ([['_SERVER', $server], ['_ENV', $env]] as [$tabla, $valor]) {
                    if ($valor === null) {
                        unset($GLOBALS[$tabla][$variable]);
                    } else {
                        $GLOBALS[$tabla][$variable] = $valor;
                    }
                }
            }
        }
    }
}

describe('PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD', function () {
    it('sin la variable, vacía o en blanco el tratamiento se suspende durante la solicitud', function () {
        foreach ([null, '', ' '] as $valor) {
            expect(configDePrivacidadConEntorno(['PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD' => $valor])['bloquear_durante_solicitud'])
                ->toBeTrue('PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD='.var_export($valor, true));
        }
    });

    it('sólo un valor explícito lo apaga, y uno no reconocido lo deja encendido', function () {
        foreach (['false', '0', 'off', 'no'] as $apagado) {
            expect(configDePrivacidadConEntorno(['PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD' => $apagado])['bloquear_durante_solicitud'])->toBeFalse($apagado);
        }
        foreach (['true', '1', 'on', 'yes', 'quizas'] as $encendido) {
            expect(configDePrivacidadConEntorno(['PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD' => $encendido])['bloquear_durante_solicitud'])->toBeTrue($encendido);
        }
    });
});

describe('plazos legales y retención', function () {
    $variables = [
        'PRIVACIDAD_PLAZO_RESPUESTA_DIAS' => ['plazo_respuesta_dias', 30],
        'PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS' => ['plazo_notificacion_brecha_dias', 3],
        'PRIVACIDAD_RETENCION_LOTE' => ['retencion.lote', 100],
        'PRIVACIDAD_RETENCION_CANDADO_SEGUNDOS' => ['retencion.candado_segundos', 21600],
    ];

    $leer = function (array $config, string $clave): mixed {
        return data_get($config, $clave);
    };

    it('sin la variable, vacía, en blanco o no numérica vale el valor por omisión, nunca 0', function () use ($variables, $leer) {
        foreach ($variables as $variable => [$clave, $porOmision]) {
            foreach ([null, '', ' ', 'treinta'] as $valor) {
                expect($leer(configDePrivacidadConEntorno([$variable => $valor]), $clave))
                    ->toBe($porOmision, $variable.'='.var_export($valor, true));
            }
        }
    });

    it('un número explícito manda', function () use ($variables, $leer) {
        foreach ($variables as $variable => [$clave]) {
            expect($leer(configDePrivacidadConEntorno([$variable => '7']), $clave))->toBe(7, $variable);
        }
    });
});
