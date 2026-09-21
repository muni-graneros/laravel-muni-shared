<?php

use Muni\Shared\Testing\ContratoDeEnvExample;

/**
 * Fixtures en tests/Testing/Fixtures/: `ConfigDeMentira/` con dos archivos
 * de config y `env-example-de-mentira/.env.example`. Ver el docblock de
 * ContratoDeEnvExample para por qué hace falta un tokenizador y no una regex.
 */
beforeEach(function () {
    $this->config = __DIR__.'/Fixtures/ConfigDeMentira';
    $this->envExample = __DIR__.'/Fixtures/env-example-de-mentira/.env.example';
});

it('detecta la clave que config/ usa y .env.example no documenta', function () {
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, $this->envExample))
        ->toBe(['MAESTRO_URL']);
});

it('ignora un env() que quedó comentado en config/: no se usa, no se exige', function () {
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, $this->envExample))
        ->not->toContain('LEGACY_TOKEN');
});

it('no se confunde con el nombre de una clave mencionado dentro de un string', function () {
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, $this->envExample))
        ->not->toContain('CADENA_NO_ES_CLAVE');
});

it('una clave documentada COMENTADA en .env.example cuenta como documentada', function () {
    // MODO_EXPERIMENTAL lo lee opcional.php con env() y .env.example la trae
    // como «#MODO_EXPERIMENTAL=false»: es el mismo patrón que usan las
    // banderas opcionales de los sistemas reales (CSP_ENABLED, OCR_ENABLED).
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, $this->envExample))
        ->not->toContain('MODO_EXPERIMENTAL');
});

it('una clave documentada sin comentar también cuenta', function () {
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, $this->envExample))
        ->not->toContain('MAESTRO_TOKEN');
});

it('sin .env.example, todo lo que config/ usa queda como faltante', function () {
    // Ordenadas alfabéticamente: es el contrato de clavesFaltantes(), no un
    // detalle de este fixture.
    expect(ContratoDeEnvExample::clavesFaltantes($this->config, '/no/existe/.env.example'))
        ->toBe(['MAESTRO_TOKEN', 'MAESTRO_URL', 'MODO_EXPERIMENTAL']);
});

it('sin config/, no hay nada que exigir', function () {
    expect(ContratoDeEnvExample::clavesFaltantes('/no/existe', $this->envExample))->toBe([]);
});
