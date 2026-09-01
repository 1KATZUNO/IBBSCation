<?php

/**
 * Datos de la iglesia para encabezados de reportes y PDF.
 *
 * Se usan cuando no hay un tenant resuelto. En esta instalacion la tabla
 * `tenants` esta vacia y los usuarios no tienen tenant_id, asi que estos
 * valores son los que terminan saliendo en todos los PDF.
 *
 * Se pueden sobreescribir desde el .env sin tocar codigo:
 *   IGLESIA_NOMBRE="Iglesia Biblica Bautista Santa Cruz"
 *   IGLESIA_SIGLAS=IBBSC
 */

return [
    'nombre' => env('IGLESIA_NOMBRE', 'Iglesia Bíblica Bautista Santa Cruz'),
    'siglas' => env('IGLESIA_SIGLAS', 'IBBSC'),
];
