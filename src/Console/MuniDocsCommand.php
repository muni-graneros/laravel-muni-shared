<?php

namespace Muni\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera documentación técnica AUTOMÁTICA del sistema (para no re-escribirla a mano y
 * que sirva a cualquier sistema del ecosistema, presente o futuro). Introspecciona la
 * BD real y el código y emite un Markdown con:
 *   - Diccionario de datos (tablas, columnas, tipos, claves, FKs, nº de filas).
 *   - Diagrama ER (mermaid) desde las FKs reales.
 *   - Máquina de estados (mermaid) si usa spatie/laravel-model-states.
 *   - Grafo de conexiones del código (mermaid): UI → Servicios → Modelos → BD.
 *   - Inventario funcional: rutas, recursos/páginas Filament, Livewire, comandos, roles.
 *
 * Uso:  php artisan muni:docs               → escribe docs/DOCUMENTACION_TECNICA.md
 *       php artisan muni:docs --output=X.md → a otra ruta
 *       php artisan muni:docs --print       → a stdout (no escribe archivo)
 */
class MuniDocsCommand extends Command
{
    protected $signature = 'muni:docs {--output=docs/DOCUMENTACION_TECNICA.md} {--print}';

    protected $description = 'Genera documentación técnica (BD, ER, estados, grafo de código, funcionalidades)';

    /** @var list<string> */
    private array $skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens', 'personal_access_tokens', 'telescope', 'pulse'];

    public function handle(): int
    {
        $md = [];
        $app = config('app.name', 'Sistema');
        $md[] = "# Documentación técnica — {$app}";
        $md[] = '';
        $md[] = '> **Generado automáticamente** por `php artisan muni:docs` el '.now()->format('d-m-Y H:i').
            '. No editar a mano: volver a correr el comando. Fuente: la BD y el código reales.';
        $md[] = '';
        $md[] = $this->indice();

        $md[] = $this->seccionArquitectura();
        $md[] = $this->seccionDiccionario();
        $md[] = $this->seccionEr();
        $md[] = $this->seccionEstados();
        $md[] = $this->seccionGrafoCodigo();
        $md[] = $this->seccionFuncionalidades();
        $md[] = $this->seccionCrossDevice();

        $salida = implode("\n", $md)."\n";

        if ($this->option('print')) {
            $this->line($salida);

            return self::SUCCESS;
        }

        $ruta = base_path((string) $this->option('output'));
        @mkdir(dirname($ruta), 0775, true);
        file_put_contents($ruta, $salida);
        $this->info("Documentación escrita en {$ruta} (".number_format(strlen($salida)).' bytes).');

        return self::SUCCESS;
    }

    private function indice(): string
    {
        return "## Índice\n\n".implode("\n", [
            '1. [Arquitectura](#arquitectura)',
            '2. [Diccionario de datos](#diccionario-de-datos)',
            '3. [Diagrama entidad-relación](#diagrama-entidad-relación)',
            '4. [Máquina de estados](#máquina-de-estados)',
            '5. [Grafo de conexiones del código](#grafo-de-conexiones-del-código)',
            '6. [Funcionalidades](#funcionalidades)',
            '7. [Pruebas y cobertura multi-dispositivo](#pruebas-y-cobertura-multi-dispositivo)',
        ])."\n";
    }

    // ── 1. Arquitectura (capas + integraciones detectadas) ──────────────────
    private function seccionArquitectura(): string
    {
        $ui = count($this->clasesEn('app/Filament')) + count($this->clasesEn('app/Livewire'));
        $svc = count($this->clasesEn('app/Services'));
        $mdl = count($this->clasesEn('app/Models'));

        $ext = [];
        if (config('services.personas_api.url') || config('personas.maestro.url') || config('licencias.maestro_personas.url')) {
            $ext[] = '  MAESTRO["Maestro de Personas<br/>(personas-api)"]';
        }
        if (config('services.keycloak.enabled') !== null && config('services.keycloak.client_id')) {
            $ext[] = '  KC["Keycloak SSO"]';
        }
        $extNodes = $ext ? implode("\n", $ext)."\n  APP -.-> ".implode(' & ', array_map(fn ($e) => Str::before(trim($e), '['), $ext)) : '';

        return implode("\n", [
            '## Arquitectura',
            '',
            'Stack: **Laravel + Filament + Livewire** (patrón del scaffold del ecosistema). Capas y volumen:',
            '',
            '```mermaid',
            'flowchart TD',
            '  subgraph Cliente',
            '    PORTAL["Portal ciudadano<br/>(Livewire)"]',
            '    PANEL["Panel funcionario<br/>(Filament)"]',
            '  end',
            "  APP[\"Aplicación<br/>UI: {$ui} · Servicios: {$svc} · Modelos: {$mdl}\"]",
            '  DB[("Base de datos")]',
            '  PORTAL --> APP',
            '  PANEL --> APP',
            '  APP --> DB',
            $extNodes,
            '```',
            '',
        ]);
    }

    // ── 2. Diccionario de datos ─────────────────────────────────────────────
    private function seccionDiccionario(): string
    {
        $out = ["## Diccionario de datos\n"];
        try {
            $db = DB::getDatabaseName();
            foreach ($this->tablasDominio() as $n) {
                $cols = DB::select("SHOW FULL COLUMNS FROM `$n`");
                $count = DB::table($n)->count();
                $out[] = "### `$n` — {$count} filas\n";
                $out[] = '| Columna | Tipo | Null | Clave | Default | Comentario |';
                $out[] = '|---|---|---|---|---|---|';
                foreach ($cols as $c) {
                    $com = str_replace('|', '\|', (string) $c->Comment);
                    $out[] = "| {$c->Field} | {$c->Type} | {$c->Null} | {$c->Key} | ".($c->Default ?? '')." | {$com} |";
                }
                $fks = $this->foreignKeys($db, $n);
                if ($fks) {
                    $out[] = '';
                    $out[] = '_Claves foráneas:_ '.implode('; ', array_map(
                        fn ($fk) => "`{$fk->COLUMN_NAME}` → `{$fk->REFERENCED_TABLE_NAME}.{$fk->REFERENCED_COLUMN_NAME}`", $fks));
                }
                $out[] = '';
            }
        } catch (\Throwable $e) {
            $out[] = '_No se pudo leer el esquema: '.$e->getMessage().'_';
        }

        return implode("\n", $out);
    }

    // ── 3. ER (mermaid) desde las FKs ───────────────────────────────────────
    private function seccionEr(): string
    {
        $out = ['## Diagrama entidad-relación', '', '```mermaid', 'erDiagram'];
        try {
            $db = DB::getDatabaseName();
            $tablas = $this->tablasDominio();
            foreach ($tablas as $n) {
                foreach ($this->foreignKeys($db, $n) as $fk) {
                    if (in_array($fk->REFERENCED_TABLE_NAME, $tablas, true)) {
                        $out[] = "  {$fk->REFERENCED_TABLE_NAME} ||--o{ {$n} : \"{$fk->COLUMN_NAME}\"";
                    }
                }
            }
        } catch (\Throwable $e) {
            $out[] = "  %% error: {$e->getMessage()}";
        }
        $out[] = '```';
        $out[] = '';

        return implode("\n", $out);
    }

    // ── 4. Máquina de estados (spatie/model-states) ─────────────────────────
    private function seccionEstados(): string
    {
        $dir = base_path('app/States');
        if (! is_dir($dir)) {
            return "## Máquina de estados\n\n_Este sistema no usa máquina de estados (spatie/model-states)._\n";
        }
        $out = ['## Máquina de estados', '', '```mermaid', 'stateDiagram-v2'];
        foreach ($this->archivosPhp($dir) as $f) {
            $src = (string) file_get_contents($f);
            // ->allowTransition(A::class, B::class[, Guard::class])
            if (preg_match_all('/allowTransition\(\s*([A-Za-z0-9_]+)::class\s*,\s*([A-Za-z0-9_]+)::class(?:\s*,\s*([A-Za-z0-9_]+)::class)?/', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $t) {
                    $guard = ! empty($t[3]) ? " : {$t[3]}" : '';
                    $out[] = "  {$t[1]} --> {$t[2]}{$guard}";
                }
            }
        }
        $out[] = '```';
        $out[] = '';

        return implode("\n", $out);
    }

    // ── 5. Grafo de conexiones del código: UI → Servicios → Modelos ─────────
    private function seccionGrafoCodigo(): string
    {
        $servicios = $this->clasesEn('app/Services');
        $modelos = $this->clasesEn('app/Models');
        $uiDirs = ['app/Filament', 'app/Livewire', 'app/Http/Controllers'];

        $edges = [];
        $ui = [];
        foreach ($uiDirs as $dir) {
            foreach ($this->archivosPhp(base_path($dir)) as $f) {
                $clase = basename($f, '.php');
                if ($clase === 'Controller') {
                    continue;
                }
                $src = (string) file_get_contents($f);
                $refSvc = $this->referencias($src, $servicios);
                $refMdl = $this->referencias($src, $modelos);
                if (! $refSvc && ! $refMdl) {
                    continue;
                }
                $ui[$clase] = true;
                foreach ($refSvc as $s) {
                    $edges["UI_$clase|SV_$s"] = "  UI_$clase --> SV_$s";
                }
                // si no toca servicios, se conecta directo al modelo (patrón Filament/Livewire simple)
                if (! $refSvc) {
                    foreach ($refMdl as $mo) {
                        $edges["UI_$clase|MD_$mo"] = "  UI_$clase --> MD_$mo";
                    }
                }
            }
        }
        // Servicios → Modelos y Servicios → Servicios
        foreach ($this->archivosPhp(base_path('app/Services')) as $f) {
            $clase = basename($f, '.php');
            $src = (string) file_get_contents($f);
            foreach ($this->referencias($src, $modelos) as $mo) {
                $edges["SV_$clase|MD_$mo"] = "  SV_$clase --> MD_$mo";
            }
            foreach ($this->referencias($src, $servicios) as $s) {
                if ($s !== $clase) {
                    $edges["SV_$clase|SV_$s"] = "  SV_$clase --> SV_$s";
                }
            }
        }

        $out = [
            '## Grafo de conexiones del código',
            '',
            'Cómo se conectan las piezas: cada componente de interfaz (Filament/Livewire/Controllers) '.
            'con los **servicios** que invoca y los **modelos** que toca; y cada servicio con sus modelos. '.
            'Derivado de los `use`/`app()` reales del código.',
            '',
            '```mermaid',
            'flowchart LR',
            '  classDef ui fill:#e0f2fe,stroke:#0284c7;',
            '  classDef sv fill:#dcfce7,stroke:#16a34a;',
            '  classDef md fill:#fef3c7,stroke:#d97706;',
        ];
        foreach (array_keys($ui) as $c) {
            $out[] = "  UI_{$c}([\"$c\"]):::ui";
        }
        foreach ($servicios as $c) {
            $out[] = "  SV_{$c}[\"$c\"]:::sv";
        }
        foreach ($modelos as $c) {
            $out[] = "  MD_{$c}[(\"$c\")]:::md";
        }
        $out = array_merge($out, array_values($edges));
        $out[] = '```';
        $out[] = '';

        return implode("\n", $out);
    }

    // ── 6. Inventario funcional ─────────────────────────────────────────────
    private function seccionFuncionalidades(): string
    {
        $out = ['## Funcionalidades', ''];

        $resources = $this->clasesEn('app/Filament/Resources');
        $pages = $this->clasesEn('app/Filament/Pages');
        $livewire = array_map(fn ($f) => str_replace([base_path('app/Livewire').'/', '.php'], ['', ''], $f),
            $this->archivosPhp(base_path('app/Livewire')));
        $comandos = $this->clasesEn('app/Console/Commands');
        $servicios = $this->clasesEn('app/Services');

        $out[] = '### Paneles y pantallas (Filament)';
        $out[] = '';
        $out[] = '- **Recursos ('.count($resources).'):** '.($resources ? '`'.implode('`, `', $resources).'`' : '—');
        $out[] = '- **Páginas ('.count($pages).'):** '.($pages ? '`'.implode('`, `', $pages).'`' : '—');
        $out[] = '';
        $out[] = '### Portal / componentes Livewire ('.count($livewire).')';
        $out[] = '';
        $out[] = $livewire ? '`'.implode('`, `', $livewire).'`' : '—';
        $out[] = '';
        $out[] = '### Servicios de dominio ('.count($servicios).')';
        $out[] = '';
        $out[] = $servicios ? '`'.implode('`, `', $servicios).'`' : '—';
        $out[] = '';

        // Roles/permisos desde la BD (spatie/permission)
        try {
            $roles = DB::table('roles')->pluck('name')->all();
            $out[] = '### Roles ('.count($roles).')';
            $out[] = '';
            $out[] = $roles ? '`'.implode('`, `', $roles).'`' : '—';
            $out[] = '';
        } catch (\Throwable) {
        }

        // Tareas programadas (routes/console.php)
        $console = base_path('routes/console.php');
        if (is_file($console)) {
            $src = (string) file_get_contents($console);
            if (preg_match_all("/command\(([^)]*?)\)\s*->[^;]*?(daily|hourly|everyMinute|everyFiveMinutes|cron\([^)]*\)|dailyAt\([^)]*\)|weekly|monthly)/s", $src, $m, PREG_SET_ORDER)) {
                $out[] = '### Tareas programadas ('.count($m).')';
                $out[] = '';
                foreach ($m as $t) {
                    $cmd = trim(preg_replace('/\s+/', ' ', $t[1]));
                    $out[] = "- `{$cmd}` — {$t[2]}";
                }
                $out[] = '';
            }
        }
        $out[] = '- **Comandos artisan ('.count($comandos).'):** '.($comandos ? '`'.implode('`, `', $comandos).'`' : '—');
        $out[] = '';

        return implode("\n", $out);
    }

    // ── 7. Pruebas y cobertura multi-dispositivo ────────────────────────────
    private function seccionCrossDevice(): string
    {
        $out = ['## Pruebas y cobertura multi-dispositivo', ''];

        // Tests de backend (Pest/PHPUnit)
        $pest = count($this->archivosPhp(base_path('tests/Feature'))) + count($this->archivosPhp(base_path('tests/Unit')));
        $out[] = "- **Pruebas de backend:** {$pest} archivos de test (Pest/PHPUnit).";

        // E2E Playwright: config, specs y matrix de dispositivos
        $cfg = null;
        foreach (['e2e/playwright.config.ts', 'e2e/playwright.config.js', 'playwright.config.ts', 'playwright.config.js'] as $c) {
            if (is_file(base_path($c))) {
                $cfg = base_path($c);
                break;
            }
        }

        if ($cfg === null) {
            $out[] = '- **E2E Playwright:** no configurado.';
            $out[] = '';
            $out[] = '> ⚠️ Sin pruebas end-to-end ni cobertura multi-dispositivo. Recomendado agregar '.
                'Playwright con al menos un móvil (iPhone/Pixel) si el sistema tiene interfaz de uso público.';

            return implode("\n", $out);
        }

        $src = (string) file_get_contents($cfg);
        // testDir → contar specs
        $testDir = 'tests';
        if (preg_match("/testDir:\s*'([^']+)'/", $src, $m)) {
            $testDir = trim($m[1], './');
        }
        $specDir = dirname($cfg).'/'.$testDir;
        $specs = array_filter($this->archivosDe($specDir, ['ts', 'js']),
            fn ($f) => preg_match('/\.(spec|test)\./', basename($f)));

        // Proyectos y dispositivos declarados
        preg_match_all("/name:\s*'([^']+)'/", $src, $mn);
        $proyectos = array_values(array_filter($mn[1] ?? [], fn ($n) => $n !== 'setup'));
        preg_match_all("/devices\['([^']+)'\]/", $src, $md);
        $dispositivos = array_values(array_unique($md[1] ?? []));

        $moviles = array_values(array_filter($dispositivos, fn ($d) => preg_match('/iPhone|Pixel|Galaxy|iPad|Android/i', $d)));
        $navegadores = array_values(array_filter($dispositivos, fn ($d) => preg_match('/Desktop|Chrome|Firefox|Safari|Edge/i', $d)));

        $out[] = '- **E2E Playwright:** '.count($specs).' specs · '.count($proyectos).' proyectos.';
        $out[] = '- **Navegadores de escritorio:** '.($navegadores ? '`'.implode('`, `', $navegadores).'`' : '—');
        $out[] = '- **Dispositivos móviles:** '.($moviles ? '`'.implode('`, `', $moviles).'`' : '— (sin cobertura móvil)');
        $out[] = '';

        // ¿el CI corre el móvil? (heurística: workflow menciona --project móvil)
        $corrEnCiMovil = false;
        foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $wf) {
            if (preg_match('/--project[= ](iphone|android|safari|webkit|mobile)/i', (string) file_get_contents($wf))) {
                $corrEnCiMovil = true;
            }
        }

        $out[] = '```mermaid';
        $out[] = 'flowchart LR';
        $out[] = '  APP["Sistema"]';
        foreach ($navegadores as $n) {
            $id = 'D_'.preg_replace('/\W/', '', $n);
            $out[] = "  APP --> {$id}([\"🖥️ {$n}\"])";
        }
        foreach ($moviles as $mo) {
            $id = 'M_'.preg_replace('/\W/', '', $mo);
            $out[] = "  APP --> {$id}([\"📱 {$mo}\"])";
        }
        $out[] = '```';
        $out[] = '';

        if ($moviles && ! $corrEnCiMovil) {
            $out[] = '> ⚠️ **Cobertura móvil existe pero NO corre en cada push de CI** (se ejecuta a demanda, '.
                'p. ej. `npx playwright test --project=iphone`). Riesgo: una regresión solo-móvil puede llegar a '.
                '`main` sin detectarse. Recomendado: job de CI móvil (nocturno o `workflow_dispatch`).';
        } elseif (! $moviles) {
            $out[] = '> ⚠️ **Sin cobertura móvil.** Si el sistema tiene interfaz de uso público (portal/dashboard), '.
                'agregar al menos un proyecto móvil (iPhone/Pixel) en `playwright.config`.';
        } else {
            $out[] = '> ✅ Cobertura multi-dispositivo (incluye móvil) integrada en CI.';
        }
        $out[] = '';

        return implode("\n", $out);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function tablasDominio(): array
    {
        $tablas = DB::select('SHOW TABLES');
        if (! $tablas) {
            return [];
        }
        $key = array_key_first((array) $tablas[0]);
        $out = [];
        foreach ($tablas as $t) {
            $n = $t->$key;
            if (! in_array($n, $this->skip, true)) {
                $out[] = $n;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<int, object> */
    private function foreignKeys(string $db, string $tabla): array
    {
        return DB::select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$db, $tabla]
        );
    }

    /** Nombres de clase (sin .php) directamente dentro de un dir del app. @return list<string> */
    private function clasesEn(string $rel): array
    {
        $dir = base_path($rel);
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir.'/*.php') ?: [] as $f) {
            $out[] = basename($f, '.php');
        }
        sort($out);

        return $out;
    }

    /** Todos los .php bajo un dir (recursivo). @return list<string> */
    private function archivosPhp(string $dir): array
    {
        return $this->archivosDe($dir, ['php']);
    }

    /** Archivos con las extensiones dadas bajo un dir (recursivo). @return list<string> */
    private function archivosDe(string $dir, array $exts): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array($f->getExtension(), $exts, true)) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /**
     * Nombres de $candidatos referenciados en $src (por `\Nombre` como clase o `Nombre::`).
     *
     * @param  list<string>  $candidatos
     * @return list<string>
     */
    private function referencias(string $src, array $candidatos): array
    {
        $out = [];
        foreach ($candidatos as $c) {
            if (preg_match('/\b'.preg_quote($c, '/').'::(class|[a-zA-Z])/', $src)
                || preg_match('/\\\\'.preg_quote($c, '/').'\b/', $src)) {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }
}
