<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{-- El nombre del sistema lo pone la cabecera institucional, no el del framework. --}}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if (config('correo-institucional.unidad'))
{{ config('correo-institucional.unidad') }} ·
@endif
Ilustre Municipalidad de Graneros
[{{ config('correo-institucional.sitio') }}]({{ config('correo-institucional.sitio') }})
Este es un correo automático, por favor no lo respondas.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
