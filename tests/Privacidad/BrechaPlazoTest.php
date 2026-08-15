<?php

use Muni\Shared\Privacidad\Brechas;
use Muni\Shared\Privacidad\Modelos\Brecha;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.plazo_notificacion_brecha_dias' => 3]);
});

it('calcula el vencimiento desde la detección', function () {
    $this->travelTo('2026-09-01 10:00:00');

    $brecha = app(Brechas::class)->registrar('Acceso indebido', ['riesgo_alto' => true]);

    expect($brecha->vence_notificacion_agencia_en->toDateString())->toBe('2026-09-04');
});

it('respeta la fecha de detección cuando la brecha se registra tarde', function () {
    $this->travelTo('2026-09-10 10:00:00');

    $brecha = app(Brechas::class)->registrar('Detectada antes', [
        'detectada_en' => '2026-09-01 10:00:00',
        'riesgo_alto' => true,
    ]);

    // El reloj corre desde que ocurrió, no desde que alguien la anotó.
    expect($brecha->vence_notificacion_agencia_en->toDateString())->toBe('2026-09-04')
        ->and(Brecha::vencidas()->count())->toBe(1);
});

it('lista las brechas por vencer y las vencidas, sin solaparse', function () {
    $this->travelTo('2026-09-01 10:00:00');
    app(Brechas::class)->registrar('Sin notificar', ['riesgo_alto' => true]);

    $this->travelTo('2026-09-03 10:00:00');
    expect(Brecha::porVencer(2)->count())->toBe(1)
        ->and(Brecha::vencidas()->count())->toBe(0);

    $this->travelTo('2026-09-06 10:00:00');
    expect(Brecha::vencidas()->count())->toBe(1)
        ->and(Brecha::porVencer(2)->count())->toBe(0);
});

it('una brecha ya notificada sale de ambas listas', function () {
    $this->travelTo('2026-09-01 10:00:00');
    $brecha = app(Brechas::class)->registrar('Notificada', ['riesgo_alto' => true]);
    app(Brechas::class)->notificarAgencia($brecha);

    $this->travelTo('2026-09-06 10:00:00');

    expect(Brecha::vencidas()->count())->toBe(0)
        ->and(Brecha::porVencer(2)->count())->toBe(0);
});
