{{--
    Plantilla de las notificaciones de correo (la que usa `MailMessage`).

    Es la del framework con UN cambio: la despedida usa el nombre institucional
    del sistema y no `config('app.name')`.

    Por qué: la plantilla institucional ya pone el nombre del sistema bajo el
    logo, y el nombre de la app en la despedida lo deshacía todo —el correo
    decía «Regards, Sistema de Prueba» debajo de un logo que dice
    «Municipalidad de Graneros». Con el nombre configurado en
    `correo-institucional.sistema` los dos dicen lo mismo.

    El resto se deja como está: saludar, líneas, botón, subcopy. Este archivo
    tiene que seguir al pie del framework, así que conviene revisarlo cuando se
    actualiza Laravel.
--}}
<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
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

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('correo-institucional.sistema') ?: config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
