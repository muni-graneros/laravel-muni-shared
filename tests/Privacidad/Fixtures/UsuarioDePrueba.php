<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as ContratoAutenticable;
use Illuminate\Database\Eloquent\Model;

/**
 * Usuario autenticado mínimo, solo para que `Auth::id()` devuelva algo.
 *
 * No tiene tabla ni se persiste: al módulo lo único que le llega es el id que
 * queda guardado en las columnas `user_*`. Sirve para ejercitar el caso que
 * importa —un adoptante con portal ciudadano, donde el usuario autenticado ES
 * el titular— sin arrastrar acá el esquema de autenticación de ningún sistema.
 */
class UsuarioDePrueba extends Model implements ContratoAutenticable
{
    use Authenticatable;

    protected $table = 'usuarios_de_prueba';

    protected $guarded = [];
}
