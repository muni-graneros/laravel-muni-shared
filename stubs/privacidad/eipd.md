# Evaluación de impacto en protección de datos

> Completar por el responsable del tratamiento. Exigible cuando el tratamiento
> de datos sensibles es masivo y sistemático, como el registro comunal de
> personas con discapacidad.

## 1. Responsable del tratamiento
Nombre:
Contacto:
Delegado de protección de datos:

## 2. Descripción del tratamiento
Obtener el listado vigente de finalidades con: `php artisan privacidad:rat`

## 3. Necesidad y proporcionalidad
¿Por qué no basta un tratamiento menos invasivo para la misma finalidad?

## 4. Riesgos identificados para los derechos de los titulares

| Riesgo | Probabilidad | Impacto | Medida mitigadora | Evidencia en el sistema |
|---|---|---|---|---|

## 5. Medidas técnicas ya implementadas
- Cifrado de los campos sensibles en reposo
- Control de acceso por rol y separación de funciones
- Trazabilidad de accesos y resoluciones (`privacidad_bitacora`)
- Retención con anonimización automática (`privacidad:aplicar-retencion`)
- Segundo factor obligatorio para el personal del panel
- Registro de brechas con doble hito de notificación independiente —
  Agencia y titulares (`privacidad_brechas`)

## 6. Conclusión y fecha de revisión
