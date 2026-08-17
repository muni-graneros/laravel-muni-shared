<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResolucionInvalida;
use Muni\Shared\Privacidad\ResultadoDePropagacion;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\SupresionEnCurso;
use Muni\Shared\Privacidad\Supresiones;
use Muni\Shared\Privacidad\SupresionNoProcede;
use Muni\Shared\Privacidad\SupresionNoPropagada;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * El derecho de supresión a PETICIÓN del titular, que es otra cosa que la
 * supresión por vencimiento del plazo (`AplicarRetencion`).
 *
 * Lo que estas pruebas fijan, y por qué existen: acoger una solicitud de
 * supresión con `Solicitudes::acoger()` a secas sellaba la solicitud como
 * resuelta y no suprimía nada. El municipio quedaba con constancia escrita de
 * haber cumplido un derecho que no cumplió, que es el peor de los dos estados
 * posibles —peor que no haberlo tramitado—.
 */
beforeEach(function () {
    config([
        'privacidad.sistema' => 'discapacidad',
        'privacidad.disco_evidencia' => 'local',
    ]);

    // Igual que la retención: sin declaración sobre el maestro no se destruye
    // nada. Los tests que ejercitan la falta de esta declaración la borran.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $this->solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Supresion,
        'Quiero que borren mis datos del registro.',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    // Fija la fila de la persona en la base, sin pasar por el modelo.
    $this->comoQuedoEnLaBase = fn () => DB::table('personas_de_prueba')
        ->where('id', $this->titular->getKey())
        ->first();

    $this->comoQuedoLaSolicitud = fn () => DB::table('privacidad_solicitudes')
        ->where('id', $this->solicitud->getKey())
        ->first();

    // Una finalidad por función legal con su plazo VIGENTE para este titular:
    // el resolvedor por defecto (`NingunTitularVencido`) no da a nadie por
    // vencido, así que basta con crearla.
    $this->porFuncionLegal = fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal de personas con discapacidad',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422, art. 56',
        'plazo_retencion_meses' => 120,
    ]);

    $this->porConsentimiento = fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión de actividades',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
        'plazo_retencion_meses' => 24,
    ]);
});

it('acoger una supresión suprime de verdad, y se comprueba en la base y en el disco', function () {
    Storage::disk('local')->put('respuestas/sol-1.pdf', 'la respuesta al titular');

    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['x' => 1], $this->titular);

    $resultado = app(Supresiones::class)->aplicar(
        $this->solicitud,
        'Se acoge: ninguna finalidad vigente exige conservar estos datos.',
        'respuestas/sol-1.pdf',
    );

    $persona = ($this->comoQuedoEnLaBase)();
    $solicitud = ($this->comoQuedoLaSolicitud)();

    expect($resultado->total)->toBeTrue()
        // La fila de la persona, leída de la base y no del objeto en memoria.
        ->and($persona->nombre)->toBe('ANONIMIZADO')
        ->and($persona->documento)->toBeNull()
        ->and($persona->diagnostico)->toBeNull()
        // El expediente en disco: el documento de respuesta lleva los datos de
        // quien pidió que los borraran. Conservarlo contradiría la supresión.
        ->and(Storage::disk('local')->exists('respuestas/sol-1.pdf'))->toBeFalse()
        // La solicitud queda acogida y con su fecha —el hecho auditable— y sin
        // titular, sin detalle y sin fundamento: el barrido la alcanza como a
        // cualquier otra fila del módulo.
        ->and($solicitud->estado)->toBe(EstadoDeSolicitud::Acogida->value)
        ->and($solicitud->resuelta_en)->not->toBeNull()
        ->and($solicitud->titular_id)->toBeNull()
        ->and($solicitud->fundamento_resolucion)->toBeNull()
        ->and($solicitud->respuesta_path)->toBeNull()
        ->and(EntradaBitacora::where('evento', 'supresion.aplicada')->count())->toBe(1)
        // Y ninguna entrada del módulo sigue apuntando a la persona.
        ->and(EntradaBitacora::whereNotNull('titular_id')->count())->toBe(0);
});

it('no procede mientras una finalidad por función legal tenga su plazo corriendo, y lo dice con la norma', function () {
    ($this->porFuncionLegal)();

    expect(fn () => app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.'))
        ->toThrow(SupresionNoProcede::class, 'Ley 20.422, art. 56');

    $persona = ($this->comoQuedoEnLaBase)();

    expect($persona->nombre)->toBe('Rocío Paredes')
        ->and($persona->diagnostico)->toBe('dato sensible de salud')
        // No se resuelve sola: queda en trámite, esperando la resolución
        // FUNDADA que le corresponde responder al titular. Sellarla como
        // rechazada por su cuenta sería inventar el fundamento.
        ->and(($this->comoQuedoLaSolicitud)()->estado)->toBe(EstadoDeSolicitud::EnTramite->value);
});

it('la evaluación nombra las finalidades que impiden sin tocar nada', function () {
    ($this->porFuncionLegal)();
    ($this->porConsentimiento)();

    $evaluacion = app(Supresiones::class)->evaluar($this->titular);

    expect($evaluacion->procedeTotal())->toBeFalse()
        ->and($evaluacion->esParcial())->toBeTrue()
        ->and($evaluacion->codigosQueImpiden())->toBe(['registro_comunal'])
        ->and($evaluacion->codigosQueCesan())->toBe(['difusion'])
        ->and($evaluacion->explicacion())->toContain('Ley 20.422, art. 56')
        ->and(($this->comoQuedoEnLaBase)()->nombre)->toBe('Rocío Paredes');
});

it('cuando una finalidad obliga a conservar y otra no, se acoge parcialmente y cesa el tratamiento en la que sí procede', function () {
    ($this->porFuncionLegal)();
    $difusion = ($this->porConsentimiento)();

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'Cesa la difusión; el registro comunal se conserva.');

    $persona = ($this->comoQuedoEnLaBase)();

    expect($resultado->total)->toBeFalse()
        // No se destruye NADA: la ley obliga a conservar para la otra
        // finalidad, y el módulo no sabe qué columna pertenece a cuál.
        ->and($persona->nombre)->toBe('Rocío Paredes')
        ->and($persona->diagnostico)->toBe('dato sensible de salud')
        ->and(($this->comoQuedoLaSolicitud)()->estado)->toBe(EstadoDeSolicitud::AcogidaParcial->value)
        // Lo que sí ocurre es el cese sobre la finalidad en que el derecho
        // procede, y ese cese sobrevive a la resolución de la solicitud.
        ->and(app(Bloqueos::class)->vigente($this->titular, $difusion))->toBeTrue()
        ->and(Bloqueo::sole()->finalidad_id)->toBe($difusion->getKey())
        ->and(Bloqueo::sole()->solicitud_id)->toBe($this->solicitud->getKey())
        ->and(Bloqueo::sole()->levantado_en)->toBeNull();

    $evidencia = EntradaBitacora::where('evento', 'supresion.parcial')->sole();

    expect($evidencia->datos['impiden'])->toBe(['registro_comunal' => 'Ley 20.422, art. 56'])
        ->and($evidencia->datos['cesan'])->toBe(['difusion']);
});

it('una finalidad por función legal cuyo plazo ya venció para este titular no impide nada', function () {
    ($this->porFuncionLegal)();

    // El sistema adoptante es el único que sabe desde cuándo trata a cada
    // titular; el módulo se lo pregunta por el mismo contrato que usa la
    // retención.
    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::all();
        }
    });

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'Venció el plazo de conservación.');

    expect($resultado->total)->toBeTrue()
        ->and(($this->comoQuedoEnLaBase)()->nombre)->toBe('ANONIMIZADO');
});

it('una finalidad fundada en el consentimiento nunca impide la supresión', function () {
    ($this->porConsentimiento)();

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'El titular retira su consentimiento.');

    expect($resultado->total)->toBeTrue()
        ->and(($this->comoQuedoEnLaBase)()->nombre)->toBe('ANONIMIZADO');
});

it('se niega igual que la retención si el sistema no declaró qué pasa con el maestro', function () {
    app()->offsetUnset(PropagaSupresion::class);

    expect(fn () => app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.'))
        ->toThrow(SupresionNoPropagada::class, 'maestro de personas');

    // Y la negativa ocurre ANTES de tocar nada, como en la retención: ni
    // siquiera se toma la solicitud.
    expect(($this->comoQuedoEnLaBase)()->diagnostico)->toBe('dato sensible de salud')
        ->and(($this->comoQuedoLaSolicitud)()->estado)->toBe(EstadoDeSolicitud::Recibida->value);
});

it('propaga al maestro con el documento previo, fuera del contexto de supresión y antes de destruir', function () {
    $espia = new class implements PropagaSupresion
    {
        public ?string $documento = null;

        public ?string $nombreAlPropagar = null;

        public ?bool $contextoActivo = null;

        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            $this->documento = $documento;
            $this->nombreAlPropagar = $titular->titularNombre();
            $this->contextoActivo = SupresionEnCurso::activa();

            return ResultadoDePropagacion::aceptada();
        }
    };

    app()->instance(PropagaSupresion::class, $espia);

    app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.');

    expect($espia->documento)->toBe('11.111.111-1')
        ->and($espia->nombreAlPropagar)->toBe('Rocío Paredes')
        ->and($espia->contextoActivo)->toBeFalse()
        ->and(($this->comoQuedoEnLaBase)()->nombre)->toBe('ANONIMIZADO');
});

it('si el maestro rechaza la supresión no se destruye nada local ni se acoge la solicitud', function () {
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::rechazada();
        }
    });

    try {
        app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.');
    } catch (SupresionNoPropagada) {
        // Se comprueba leyendo la base, no por la excepción.
    }

    $persona = ($this->comoQuedoEnLaBase)();
    $solicitud = ($this->comoQuedoLaSolicitud)();

    expect($persona->nombre)->toBe('Rocío Paredes')
        ->and($persona->diagnostico)->toBe('dato sensible de salud')
        // Como en Rectificaciones: queda EN TRÁMITE y no vuelve a "recibida",
        // para que se vea que se intentó y falló.
        ->and($solicitud->estado)->toBe(EstadoDeSolicitud::EnTramite->value)
        ->and($solicitud->resuelta_en)->toBeNull()
        ->and(EntradaBitacora::where('evento', 'supresion.fallida')->count())->toBe(1);
});

it('anonimiza dentro del contexto de supresión, que es lo que consulta el write-through del adoptante', function () {
    PersonaDePrueba::$supresionActivaAlPurgar = null;
    PersonaDePrueba::$supresionActivaAlAnonimizar = null;

    app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.');

    expect(PersonaDePrueba::$supresionActivaAlPurgar)->toBeTrue()
        ->and(PersonaDePrueba::$supresionActivaAlAnonimizar)->toBeTrue()
        ->and(SupresionEnCurso::activa())->toBeFalse();
});

it('solo una solicitud de supresión se resuelve suprimiendo', function () {
    $otra = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero una copia',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    expect(fn () => app(Supresiones::class)->aplicar($otra, 'Se acoge.'))
        ->toThrow(ResolucionInvalida::class, 'Acceso');

    expect(($this->comoQuedoEnLaBase)()->nombre)->toBe('Rocío Paredes');
});

it('una supresión sin fundamento no se aplica', function () {
    expect(fn () => app(Supresiones::class)->aplicar($this->solicitud, '   '))
        ->toThrow(ResolucionInvalida::class, 'fundada');

    expect(($this->comoQuedoEnLaBase)()->nombre)->toBe('Rocío Paredes');
});

it('una solicitud ya resuelta no se puede suprimir de nuevo', function () {
    app(Solicitudes::class)->rechazar($this->solicitud, 'No acreditó identidad suficiente.');

    expect(fn () => app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.'))
        ->toThrow(ResolucionInvalida::class, 'ya fue resuelta');

    expect(($this->comoQuedoEnLaBase)()->nombre)->toBe('Rocío Paredes');
});

it('una solicitud cuyo titular ya no está no se puede suprimir', function () {
    // Es el estado en que queda una solicitud después de que su titular se
    // anonimizó (por retención, o por una supresión anterior): la fila sigue
    // ahí como hecho auditable, pero ya no apunta a nadie.
    DB::table('privacidad_solicitudes')
        ->where('id', $this->solicitud->getKey())
        ->update(['titular_id' => null]);

    expect(fn () => app(Supresiones::class)->aplicar($this->solicitud->fresh(), 'Se acoge.'))
        ->toThrow(ResolucionInvalida::class, 'titular');
});

it('una supresión que nadie propagó no se le puede presentar al titular como aceptada por el maestro', function () {
    // El sitio de consumo que más importa: lo que devuelve `aplicar()` es lo
    // que un panel convierte en la frase «sus datos fueron suprimidos». Si el
    // tercer estado se leyera como éxito, esa frase se le diría a un vecino
    // cuya identidad sigue viva y consultable por RUT en el registro federado.
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::noCorrespondia('El driver de la API de personas no es http en este ambiente.');
        }
    });

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.');

    expect($resultado->total)->toBeTrue()
        // Se suprimió de verdad acá...
        ->and(($this->comoQuedoEnLaBase)()->nombre)->toBe('ANONIMIZADO')
        // ...y el resultado dice, sin que haya que deducirlo, que el maestro no
        // aceptó nada porque nadie le habló.
        ->and($resultado->propagacion->loAceptoElMaestro())->toBeFalse()
        ->and($resultado->propagacion->seHabloConElMaestro())->toBeFalse()
        ->and($resultado->propagacion->motivo)->toContain('no es http')
        ->and(EntradaBitacora::where('evento', 'supresion.aplicada')->sole()->datos['propagacion'])
        ->toBe([
            'estado' => 'no_correspondia',
            'motivo' => 'El driver de la API de personas no es http en este ambiente.',
        ]);
});

it('cuando el maestro acepta la baja, el resultado y la evidencia lo dicen', function () {
    // La mitad que hace fallar cualquier intento de colapsar los dos estados.
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::aceptada();
        }
    });

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge.');

    expect($resultado->propagacion->loAceptoElMaestro())->toBeTrue()
        ->and(EntradaBitacora::where('evento', 'supresion.aplicada')->sole()->datos['propagacion'])
        ->toBe(['estado' => 'aceptada', 'motivo' => null]);
});

it('la acogida parcial no trae propagación, porque no hay ninguna baja que propagar', function () {
    // Y es null y no «aceptada»: la parcial no destruye nada, así que nada
    // viajó al maestro. Devolver un resultado de propagación acá sería inventar
    // una conversación que no ocurrió.
    ($this->porFuncionLegal)();
    ($this->porConsentimiento)();

    $resultado = app(Supresiones::class)->aplicar($this->solicitud, 'Se acoge lo que procede.');

    expect($resultado->total)->toBeFalse()
        ->and($resultado->propagacion)->toBeNull();
});
