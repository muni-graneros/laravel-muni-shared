<?php

namespace Muni\Shared\Auditoria\Pages;

use Filament\Resources\Pages\ListRecords;

/**
 * Página base del listado de auditoría del panel (`spatie/laravel-activitylog`
 * mostrado como Resource de Filament).
 *
 * El `ListActivities` de cada sistema era, en 5 de los 8 (licencias,
 * seguridad, control-acceso, web, rrhh), byte a byte idéntico salvo por
 * declarar su propio `$resource` — que no es portable: cada sistema tiene
 * SU `ActivityResource`, con sus propias columnas de tabla. Por eso esta
 * clase es abstracta y NO fija `$resource`: solo entrega el sitio único al
 * que un comportamiento común (filtros, orden, exportación) podría llegar
 * mañana sin volver a editar N copias.
 *
 * `discapacidad-graneros` diverge del resto solo en el namespace de su
 * página (`App\Filament\Discapacidad\Resources\...`, por su panel Filament
 * separado), no en el contenido: extender esta clase base funciona igual.
 *
 * Adopción, por sistema:
 *
 * ```php
 * namespace App\Filament\Resources\ActivityResource\Pages;
 *
 * use App\Filament\Resources\ActivityResource;
 * use Muni\Shared\Auditoria\Pages\ListActivitiesBase;
 *
 * class ListActivities extends ListActivitiesBase
 * {
 *     protected static string $resource = ActivityResource::class;
 * }
 * ```
 */
abstract class ListActivitiesBase extends ListRecords {}
