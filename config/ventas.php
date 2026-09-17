<?php

return [
    'payload_archive' => [
        // Solo habilita la copia local explícitamente configurada. Aunque se
        // active, el comando bloquea eliminar raw: este disco vive en el VPS.
        'allow_local_disk' => env('VENTAS_PAYLOAD_ALLOW_LOCAL_ARCHIVE', false),
        'local_disk' => 'ventas_archivo_local',
    ],
];
