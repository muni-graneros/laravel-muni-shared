<?php

use Illuminate\Support\Facades\File;
use Muni\Shared\Assets;

/**
 * `asset_versionado()` vivía en `app/Helpers/assets.php`, cargado por
 * `autoload.files`, en 7 de los 8 sistemas (idéntico salvo un
 * `declare(strict_types=1)` en licencias).
 *
 * El paquete expone las dos formas y eso es deliberado: la CLASE
 * (`Assets::versionado()`) es lo que se prueba y lo que ve PHPStan, y la FUNCIÓN
 * GLOBAL es lo que ya escriben decenas de plantillas Blade. Si el paquete
 * expusiera solo la clase, adoptarlo obligaría a editar todas esas vistas; si
 * expusiera solo la función, no habría nada tipado que analizar.
 *
 * La función lleva su guarda `function_exists`, igual que la del sistema: los
 * dos archivos pueden convivir mientras dura la adopción sin un fatal por
 * redeclaración.
 */
beforeEach(function () {
    $this->publico = sys_get_temp_dir().'/muni-assets-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->publico.'/sitio/css');
    $this->app->usePublicPath($this->publico);
});

afterEach(function () {
    File::deleteDirectory($this->publico);
});

it('cuelga de la URL la fecha de modificación del archivo', function () {
    $archivo = $this->publico.'/sitio/css/estilo.css';
    File::put($archivo, 'body{}');
    touch($archivo, 1_700_000_000);

    expect(Assets::versionado('sitio/css/estilo.css'))
        ->toBe(asset('sitio/css/estilo.css').'?v=1700000000');
});

it('cambia la URL cuando cambia el archivo, que es para lo que existe', function () {
    $archivo = $this->publico.'/sitio/css/estilo.css';

    File::put($archivo, 'body{}');
    touch($archivo, 1_700_000_000);
    $antes = Assets::versionado('sitio/css/estilo.css');

    File::put($archivo, 'body{color:red}');
    touch($archivo, 1_700_000_900);
    $despues = Assets::versionado('sitio/css/estilo.css');

    expect($despues)->not->toBe($antes);
});

it('con un archivo que no existe devuelve una URL usable, no una excepción', function () {
    expect(Assets::versionado('sitio/css/no-esta.css'))
        ->toBe(asset('sitio/css/no-esta.css').'?v=1');
});

it('expone la función global que ya usan las plantillas Blade', function () {
    $archivo = $this->publico.'/sitio/css/estilo.css';
    File::put($archivo, 'body{}');
    touch($archivo, 1_700_000_000);

    expect(function_exists('asset_versionado'))->toBeTrue()
        ->and(asset_versionado('sitio/css/estilo.css'))
        ->toBe(Assets::versionado('sitio/css/estilo.css'));
});
