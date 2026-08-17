<?php

namespace Muni\Shared\Privacidad;

use Throwable;

/**
 * Marca las excepciones que dicen "el módulo se negó a tramitar esto, y esta
 * es la razón legal" — el tipo de negativa que una UI necesita mostrar tal
 * cual a quien la pidió (identidad sin verificar, edad sin acreditar,
 * representación sin acreditar), no un fallo de infraestructura ni un error
 * de integración del adoptante.
 *
 * Es una interfaz y no una clase base nueva, a propósito: `IdentidadNoVerificada`
 * extiende `RuntimeException` y `EdadNoAcreditada`, `RepresentacionNoAcreditada`
 * y `RepresentacionRequerida` extienden `DomainException` —dos ramas que no se
 * tocan entre sí en la jerarquía de PHP—, y ese era justamente el problema que
 * esto cierra: no había forma de atraparlas juntas sin atrapar de más. Convertir
 * la interfaz en una clase abstracta habría obligado a elegir UNA de las dos
 * ramas como padre común, y quien hoy atrapa `catch (RuntimeException $e)` para
 * `IdentidadNoVerificada`, o `catch (DomainException $e)` para las otras tres,
 * habría dejado de recibirla. PHP sí permite atrapar por interfaz
 * (`catch (SolicitudRechazada $e)` hace un `instanceof` como cualquier otro
 * catch), así que la interfaz da la captura conjunta sin tocar el `extends` de
 * ninguna clase existente ni el mensaje que ya llevan.
 *
 * Qué NO implementa esta interfaz, y por qué: `OpcionInvalida` y
 * `TextoNoPublicado` (que `Consentimientos::otorgar()` también puede lanzar)
 * no son una negativa a la petición de un titular, son el módulo rechazando
 * una llamada mal armada del sistema adoptante —un valor que no es un caso del
 * enum, una fila de texto que no existe—. No hay ninguna persona al otro lado
 * del mesón a quien mostrárselas: son errores de integración, y mezclarlas acá
 * le haría creer a un panel que puede presentárselas a un titular como si
 * fueran una razón legal de la negativa.
 *
 * `FinalidadInvalida` queda fuera por el mismo motivo en su rama principal
 * —la finalidad que el sistema adoptante pasó no admite consentimiento, algo
 * que se decide al configurar el RAT, no al atender a un titular—, aunque uno
 * de sus tres orígenes (`exigirRegimenDeNna()`, cuando la finalidad no admite
 * NNA) sí es una negativa sobre ESE titular. Separar una excepción por el
 * origen de la llamada y no por su clase sería más frágil que el problema que
 * resuelve esta interfaz, así que queda entera fuera; si un consumidor
 * necesita distinguir ese caso, es un candidato a su propia excepción en un
 * cambio futuro, no a sumarse acá a medias.
 */
interface SolicitudRechazada extends Throwable {}
