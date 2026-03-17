<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';

try {
    $sql = "SELECT idlocalidad, nombrelocalidad
            FROM localidad
            WHERE nombrelocalidad IS NOT NULL
              AND nombrelocalidad <> ''
            ORDER BY nombrelocalidad ASC";

    $res = $conexion->query($sql);

    $localidades = [];
    while ($row = $res->fetch_assoc()) {
        $localidades[] = $row;
    }

    echo json_encode([
        'ok' => true,
        'localidades' => $localidades
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al obtener localidades'
    ]);
}