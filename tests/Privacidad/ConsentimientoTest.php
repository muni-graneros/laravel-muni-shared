<?php

use Illuminate\Database\QueryException;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->difusion = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);
});

it('otorga un consentimiento vigente para una finalidad accesoria', function () {
    app(Consentimientos::class)->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeTrue()
        // Sin sesión autenticada en el test, Auth::id() es null: la columna queda
        // poblada con lo que devuelva el guard, no ignorada.
        ->and(Consentimiento::sole()->user_id)->toBe(auth()->id());
});

it('revocar no borra la evidencia: marca la fecha y deja de estar vigente', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    $servicio->revocar($this->titular, $this->difusion);

    expect($servicio->vigente($this->titular, $this->difusion))->toBeFalse()
        ->and(Consentimiento::count())->toBe(1)
        ->and(Consentimiento::sole()->revocado_en)->not->toBeNull()
        // Las dos columnas se mueven juntas: si revocado_en se llena y
        // vigente_clave no se limpia, el índice único bloquearía otorgar() de nuevo.
        ->and(Consentimiento::sole()->vigente_clave)->toBeNull();
});

it('volver a otorgar tras una revocación deja un consentimiento vigente y conserva el anterior', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);
    $servicio->revocar($this->titular, $this->difusion);

    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::VerbalRegistrada);

    expect(Consentimiento::count())->toBe(2)
        ->and($servicio->vigente($this->titular, $this->difusion))->toBeTrue();
});

it('no hay consentimiento vigente si nunca se otorgó', function () {
    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeFalse();
});

it('la base de datos, no el orden de llamadas, impide dos consentimientos vigentes para el mismo par', function () {
    $clave = sha1($this->titular::class.'|'.$this->titular->getKey().'|'.$this->difusion->getKey());

    Consentimiento::create([
        'titular_type' => $this->titular::class,
        'titular_id' => $this->titular->getKey(),
        'finalidad_id' => $this->difusion->getKey(),
        'vigente_clave' => $clave,
        'otorgado_en' => now(),
        'medio' => MedioDeConsentimiento::FirmaPapel,
    ]);

    // Segunda fila vigente con la misma clave, insertada sin pasar por
    // Consentimientos::otorgar() (que la habría cerrado antes): si esto no
    // reventara, dos vigentes convivirían y nadie podría acreditar cuál texto
    // aceptó el titular.
    Consentimiento::create([
        'titular_type' => $this->titular::class,
        'titular_id' => $this->titular->getKey(),
        'finalidad_id' => $this->difusion->getKey(),
        'vigente_clave' => $clave,
        'otorgado_en' => now(),
        'medio' => MedioDeConsentimiento::FirmaDigital,
    ]);
})->throws(QueryException::class);
