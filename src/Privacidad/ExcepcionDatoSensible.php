<?php

namespace Muni\Shared\Privacidad;

/**
 * Causal que habilita tratar datos sensibles.
 *
 * La ley los prohíbe como regla general y los permite solo por causales
 * tasadas: la base de licitud general NO basta para la categoría prohibida.
 * Hay que decir además por qué se puede tocar.
 *
 * REVISIÓN JURÍDICA: este catálogo se propone desde el texto de la ley, pero
 * cuál aplica a cada finalidad municipal es una calificación legal. El código
 * obliga a declarar una; no puede decidir cuál.
 */
enum ExcepcionDatoSensible: string
{
    case ConsentimientoExpreso = 'consentimiento_expreso';
    case FinesEstatalesHabilitadosPorLey = 'fines_estatales_habilitados_por_ley';
    case InteresVital = 'interes_vital';
    case DatosHechosPublicosPorElTitular = 'datos_hechos_publicos_por_el_titular';
    case EjercicioDeDerechosAnteTribunales = 'ejercicio_de_derechos_ante_tribunales';
    case FinesHistoricosEstadisticosCientificos = 'fines_historicos_estadisticos_cientificos';
    case SaludPublicaOSeguridadSocial = 'salud_publica_o_seguridad_social';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ConsentimientoExpreso => 'Consentimiento expreso del titular',
            self::FinesEstatalesHabilitadosPorLey => 'Fines estatales habilitados por ley',
            self::InteresVital => 'Protección de un interés vital',
            self::DatosHechosPublicosPorElTitular => 'Datos hechos públicos por el titular',
            self::EjercicioDeDerechosAnteTribunales => 'Ejercicio de derechos ante tribunales',
            self::FinesHistoricosEstadisticosCientificos => 'Fines históricos, estadísticos o científicos',
            self::SaludPublicaOSeguridadSocial => 'Salud pública o seguridad social',
        };
    }
}
