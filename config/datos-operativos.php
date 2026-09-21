<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Las tablas que vacía `env:clean-data`
    |--------------------------------------------------------------------------
    |
    | Datos operativos y temporales: nada que un funcionario haya escrito ni
    | nada que sostenga el acceso al sistema. Los valores por omisión son los
    | cuatro que la copia local de cada sistema tenía en una constante; un
    | sistema con una tabla operativa propia (una cola de importaciones, un
    | caché materializado) la agrega acá en vez de bifurcar el comando otra vez.
    |
    | Lo que NO va acá: usuarios, roles, permisos, tours de onboarding y
    | cualquier expediente. El comando no toca lo que no está en esta lista.
    |
    */

    'tablas' => [
        'onboarding_progress',
        'jobs',
        'job_batches',
        'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | La auditoría, que solo se vacía si se la pide
    |--------------------------------------------------------------------------
    |
    | Va aparte porque no es un dato operativo: es la traza de quién accedió a
    | qué, que la Ley 21.719 obliga a poder reconstruir. Solo se vacía con
    | `--auditoria`, escrito a mano y a conciencia.
    |
    */

    'tablas_de_auditoria' => [
        'activity_log',
    ],

];
