<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Actúa un tercero por el titular y no se acompañó el documento que lo
 * acredita.
 *
 * Por qué el módulo se pone exigente acá: `Solicitante` ya distinguía quién
 * actúa, pero elegir «representante legal» en un desplegable no acredita nada.
 * Un régimen reforzado que un funcionario satisface seleccionando una opción no
 * es un régimen —la fila decía «lo otorgó su representante legal» sin que
 * existiera ni un documento ni una identidad detrás—, y ese es justo el papel
 * que hay que mostrar cuando alguien fiscaliza o cuando la familia discute
 * quién autorizó qué.
 *
 * Qué se guarda y qué no: el módulo guarda la RUTA del documento
 * (`acreditacion_path`), no el nombre ni el RUT del representante. Las columnas
 * de estas tablas que el barrido de anonimización conserva lo son por ser
 * categóricas, y meter la identidad de un tercero en ellas rompería ese
 * argumento (ver `Solicitante`). La identidad del representante vive en el
 * documento, que es donde ya vive.
 */
class RepresentacionNoAcreditada extends DomainException {}
