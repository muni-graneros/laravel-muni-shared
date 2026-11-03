{{--
    El contenido de las notificaciones de correo: saludo, líneas, botón y
    despedida.

    Es la del framework traducida al español, con la despedida institucional:
    "Saludos," y el nombre del sistema configurado (o el de la app), no el
    "Regards," en inglés que traía el framework.

    Va en el paquete porque los doce sistemas municipales escriben sus
    notificaciones en español y todos necesitan el mismo saludo. PERO no se impone:
    una app con su propia `resources/views/vendor/notifications/email.blade.php`
    la sigue usando. El orden de búsqueda lo arma
    `MuniSharedServiceProvider::plantillaDeNotificaciones()`:

        1. la de la app (resources/views/vendor/notifications),
        2. esta,
        3. la del framework.

    El primer paso es el que importa: atencionvecino sobrescribe esa
    plantilla para no mostrar la URL del requerimiento en el aviso de respaldo,
    y ese control de privacidad no puede quedar en manos del paquete.
--}}
<x-mail::message>
{{-- Saludo --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# ¡Ups!
@else
# ¡Hola!
@endif
@endif

{{-- Líneas de introducción --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Botón --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Líneas de cierre --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Despedida --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Saludos,<br>
{{ config('correo-institucional.sistema') ?: config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Si tiene problemas para hacer clic en el botón «{{ $actionText }}», copie y pegue esta dirección en su navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endif
</x-mail::message>
