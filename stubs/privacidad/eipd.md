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

## 5. Medidas técnicas

> Esta EIPD se firma y se entrega a la autoridad: acá no puede quedar marcada
> ninguna medida que el municipio no haya verificado en ESTE sistema.

### 5.1 Aportadas por el módulo de privacidad
Vienen con el paquete instalado y migrado. Se dan por presentes una vez que
`php artisan privacidad:rat` responde con las finalidades del sistema.

- Trazabilidad de resoluciones y supresiones (`privacidad_bitacora`)
- Retención con purga de sensibles y anonimización
  (`privacidad:aplicar-retencion`), sujeta a que el sistema implemente
  `ResuelveTitularesVencidos`: sin eso el comando no revisa nada y lo advierte
- Registro de brechas con doble hito de notificación independiente —
  Agencia y titulares (`privacidad_brechas`)
- Separación de funciones entre registrar y resolver una solicitud, auditada en
  `user_registro_id` / `user_resolucion_id`

### 5.2 Por confirmar en este sistema (pendientes de verificación)
No son propiedades del módulo, sino de cada instalación. Marcar solo después de
comprobarlas en el sistema que esta EIPD describe, y anotar cómo se comprobaron.

- [ ] Cifrado de los campos sensibles en reposo — ¿qué campos, con qué mecanismo?
- [ ] Segundo factor obligatorio para el personal del panel — verificado en el
      navegador, no en `tinker`
- [ ] Control de acceso por rol efectivamente aplicado (no solo los permisos
      visibles en la interfaz, sino las políticas que los hacen cumplir)
- [ ] Respaldos cifrados y con restauración probada

Verificado por: ______________________ Fecha: ____________

## 6. Conclusión y fecha de revisión
