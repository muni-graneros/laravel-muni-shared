@props(['url' => null])

{{--
    La cabecera institucional: logo incrustado, nombre del sistema y franja de
    siete colores.

    El `$slot` se ignora a propósito. El header del framework pone ahí el
    nombre de la app y, si ese nombre es "Laravel", el logo de Laravel. Acá va
    el de la Municipalidad, siempre.

    El logo va con `cid:` y no con una URL: el PNG viaja incrustado en el
    correo (lo agrega `IncrustarLogoInstitucional` al enviar), así que se ve
    aunque el sistema esté detrás de la VPN y el cliente no pueda cargar nada
    externo.
--}}
<tr>
<td class="header" bgcolor="#ffffff" style="background-color:#ffffff;padding:24px 0 0 0;text-align:center;">
    <img src="cid:{{ \Muni\Shared\Correo\PlantillaInstitucional::CID }}" width="240" height="67" alt="Municipalidad de Graneros" style="display:block;margin:0 auto;border:0;width:240px;height:67px;">
    <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:17px;font-weight:bold;color:#355a63;padding:14px 16px 18px 16px;">
        {{ config('correo-institucional.sistema') ?: config('app.name') }}
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">
        <tr>
            <td style="height:5px;background-color:#adcd60;"></td>
            <td style="height:5px;background-color:#355a63;"></td>
            <td style="height:5px;background-color:#eab02c;"></td>
            <td style="height:5px;background-color:#c76421;"></td>
            <td style="height:5px;background-color:#7ccbe1;"></td>
            <td style="height:5px;background-color:#ca3048;"></td>
            <td style="height:5px;background-color:#9c9b9b;"></td>
        </tr>
    </table>
</td>
</tr>
