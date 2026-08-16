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
- Régimen reforzado de niños, niñas y adolescentes, **igual en el
  consentimiento y en los cinco derechos ARCOP**: los ejerce su representante
  legal, acreditado con un documento cuya ruta queda en la fila
  (`acreditacion_path`), y una fecha de nacimiento desconocida se rechaza en vez
  de asumir mayoría de edad. Con dos límites que hay que declarar acá: el módulo
  comprueba que el documento **esté declarado**, no que exista ni que diga lo que
  dice ser; y las dos guardas cubren los métodos del paquete, no un `INSERT`
  directo a las tablas
- Constancia de qué texto informativo exacto aceptó el titular
  (`privacidad_consentimientos.texto_id` → `privacidad_textos`, con hash y
  versión), **siempre que el sistema pase la fila que mostró**: si no la pasa, la
  columna queda en null y el consentimiento no acredita qué versión se leyó.
  Revisar en la sección 5.2 que el sistema la esté pasando
- Retención con purga de sensibles y anonimización
  (`privacidad:aplicar-retencion`), sujeta a que el sistema implemente
  `ResuelveTitularesVencidos`: sin eso el comando no revisa nada y lo advierte
- Registro de brechas con doble hito de notificación independiente —
  Agencia y titulares (`privacidad_brechas`)
- Separación de funciones entre registrar y resolver una solicitud, auditada en
  `user_registro_id` / `user_resolucion_id` — **con un límite que hay que
  declarar acá, no descubrirlo en una fiscalización**: esos dos ids se
  **suprimen** cuando el titular se anonimiza. En las solicitudes de titulares
  anonimizados la separación de funciones ya no es auditable en este registro.
  Se suprimen a propósito: el módulo guarda `Auth::id()` sin poder distinguir al
  funcionario del propio ciudadano, y en un sistema con portal ciudadano ese id
  ES la persona, o sea un puntero directo al titular que sobreviviría a la
  anonimización. Si el municipio necesita acreditar la separación de funciones
  también sobre casos ya anonimizados, la evidencia tiene que venir de otra capa
  (el `activity_log` del sistema, por ejemplo) y hay que nombrarla en la
  sección 4.

### 5.2 Por confirmar en este sistema (pendientes de verificación)
No son propiedades del módulo, sino de cada instalación. Marcar solo después de
comprobarlas en el sistema que esta EIPD describe, y anotar cómo se comprobaron.

- [ ] Los formularios de consentimiento pasan la fila del texto que mostraron
      (`['texto' => $fila]`), y no queda ningún consentimiento nuevo con
      `texto_id` en null — se comprueba con una consulta, no de memoria
- [ ] Los códigos de los textos informativos nombran **finalidades y no grupos de
      personas**: un código por barrio, programa o cohorte particiona las filas
      anonimizadas y puede volver a distinguir a la persona en grupos chicos
- [ ] Cifrado de los campos sensibles en reposo — ¿qué campos, con qué mecanismo?
- [ ] Segundo factor obligatorio para el personal del panel — verificado en el
      navegador, no en `tinker`
- [ ] Control de acceso por rol efectivamente aplicado (no solo los permisos
      visibles en la interfaz, sino las políticas que los hacen cumplir)
- [ ] Respaldos cifrados y con restauración probada

Verificado por: ______________________ Fecha: ____________

## 6. Conclusión y fecha de revisión
