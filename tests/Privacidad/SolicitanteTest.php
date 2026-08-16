<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        // Adulta: desde el régimen de NNA, otorgar() exige la edad acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);

    $this->difusion = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);
});

it('registra quién ejerció el derecho como categoría, no como texto', function () {
    app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Vengo por mi hija',
        $this->verificacion,
        Solicitante::RepresentanteLegal,
    );

    expect(Solicitud::sole()->solicitante)->toBe(Solicitante::RepresentanteLegal);
});

it('por defecto ejerce el propio titular', function () {
    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    );

    app(Consentimientos::class)->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    expect(Solicitud::sole()->solicitante)->toBe(Solicitante::Titular)
        ->and(Consentimiento::sole()->otorgado_por)->toBe(Solicitante::Titular);
});

it('el consentimiento guarda quién lo otorgó con el mismo vocabulario', function () {
    // Es el caso de NNA: consiente el representante legal, el titular es el niño.
    app(Consentimientos::class)->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel, [
        'otorgado_por' => Solicitante::RepresentanteLegal,
    ]);

    expect(Consentimiento::sole()->otorgado_por)->toBe(Solicitante::RepresentanteLegal)
        ->and(Consentimiento::sole()->otorgado_por->exigeAcreditarRepresentacion())->toBeTrue();
});

it('la columna no admite un nombre de persona: es lo que justifica conservarla al anonimizar', function () {
    // El barrido conserva `solicitante` y `otorgado_por` porque son categorías.
    // Este test fija la otra mitad del argumento: que no puedan contener otra
    // cosa. Si alguien devuelve estas columnas a string libre, «Juan Pérez,
    // hijo» entra sin resistencia y la lista de conservadas del test de
    // aceptación pasa a bendecir un dato personal.
    expect(fn () => app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Vengo por mi madre',
        $this->verificacion,
        // @phpstan-ignore-next-line argument.type (el error de tipo es la aserción)
        'Juan Pérez, hijo',
    ))->toThrow(TypeError::class);
});

it('la categoría sobrevive a la anonimización, el resto no', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Vengo por mi hija, mi RUT es 11.111.111-1',
        $this->verificacion,
        Solicitante::Apoderado,
    );

    // La historia tiene que ser anterior: lo escrito en el mismo segundo que la
    // anonimización queda huérfano sin referencia de grupo, a propósito.
    Solicitud::query()->whereKey($solicitud->getKey())->update(['created_at' => now()->subMonth()]);

    app(Bitacora::class)->desvincular($this->titular);

    $huerfana = Solicitud::sole();

    expect($huerfana->solicitante)->toBe(Solicitante::Apoderado)
        ->and($huerfana->titular_id)->toBeNull()
        ->and($huerfana->detalle)->toBe(Bitacora::SUPRIMIDO);
});
