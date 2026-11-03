<x-mail::message>
# Hola {{ $nombre }}

Tu trámite fue recibido.

<x-mail::button :url="'https://sistema.example/tramite'">
Ver trámite
</x-mail::button>
</x-mail::message>
