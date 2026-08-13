<?php

use Muni\Shared\Privacidad\Brechas;
use Muni\Shared\Privacidad\Modelos\Brecha;

it('registra una brecha con su naturaleza y alcance', function () {
    $brecha = app(Brechas::class)->registrar('Acceso indebido a fichas de salud', [
        'naturaleza' => 'acceso_no_autorizado',
        'categorias_afectadas' => ['salud', 'identificacion'],
        'titulares_estimados' => 12,
        'riesgo_alto' => true,
    ]);

    expect($brecha->riesgo_alto)->toBeTrue()
        ->and($brecha->categorias_afectadas)->toBe(['salud', 'identificacion'])
        ->and($brecha->detectada_en)->not->toBeNull();
});

it('sella las dos notificaciones por separado', function () {
    $servicio = app(Brechas::class);
    $brecha = $servicio->registrar('Respaldo extraviado', ['riesgo_alto' => true]);

    $servicio->notificarAgencia($brecha);

    expect($brecha->refresh()->notificada_agencia_en)->not->toBeNull()
        ->and($brecha->notificada_titulares_en)->toBeNull();

    $servicio->notificarTitulares($brecha);

    expect($brecha->refresh()->notificada_titulares_en)->not->toBeNull();
});

it('lista las brechas de riesgo alto que aún no se notifican a la Agencia', function () {
    $servicio = app(Brechas::class);
    $servicio->registrar('Sin notificar', ['riesgo_alto' => true]);
    $notificada = $servicio->registrar('Ya notificada', ['riesgo_alto' => true]);
    $servicio->notificarAgencia($notificada);

    expect(Brecha::sinNotificar()->pluck('descripcion')->all())->toBe(['Sin notificar']);
});

it('sinNotificar() no filtra por nivel de riesgo, solo por el hito de la Agencia', function () {
    app(Brechas::class)->registrar('Riesgo bajo sin notificar', ['riesgo_alto' => false]);

    expect(Brecha::sinNotificar()->pluck('descripcion')->all())->toBe(['Riesgo bajo sin notificar']);
});

it('una brecha sin evaluar queda en null, no en false, y aparece como pendiente', function () {
    $brecha = app(Brechas::class)->registrar('Detectada, sin triage aún');

    expect($brecha->riesgo_alto)->toBeNull()
        ->and(Brecha::sinEvaluarRiesgo()->pluck('descripcion')->all())->toBe(['Detectada, sin triage aún']);
});
