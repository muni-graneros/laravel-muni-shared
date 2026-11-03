<x-mail::layout>
{{-- Header --}}
<x-slot:header>
Municipalidad de Graneros
{{ config('correo-institucional.sistema') ?: config('app.name') }}
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)

{!! $subcopy !!}
@endisset

{{-- Footer --}}
<x-slot:footer>
@if (config('correo-institucional.unidad'))
{{ config('correo-institucional.unidad') }} ·
@endif
Ilustre Municipalidad de Graneros
{{ config('correo-institucional.sitio') }}
Este es un correo automático, por favor no lo respondas.
</x-slot:footer>
</x-mail::layout>
