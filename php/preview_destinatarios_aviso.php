<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config_blacklist_correos.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$modoEnvio   = trim($data['modo_envio'] ?? 'localidades');
$localidades = $data['localidades'] ?? [];

if (!is_array($localidades)) {
    $localidades = [];
}

try {
    $blacklist = array_map(function($email) {
        return strtolower(trim($email));
    }, $BLACKLIST_CORREOS);

    $blacklist = array_flip(array_filter($blacklist));

    $params = [];
    $types = '';
    $whereLocalidades = '';

    if ($modoEnvio !== 'todos') {
        if (count($localidades) === 0) {
            echo json_encode([
                'ok' => false,
                'msg' => 'Selecciona al menos una localidad'
            ]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($localidades), '?'));
        $whereLocalidades = " AND localidad IN ($placeholders) ";
        $types .= str_repeat('s', count($localidades));
        $params = array_merge($params, $localidades);
    }

    $sql = "SELECT idcliente, nombre, email, localidad
            FROM clientes
            WHERE estado = 'Activo'
            $whereLocalidades
            ORDER BY localidad, nombre";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        throw new Exception($conexion->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $destinatarios = [];
    $excluidos = [];
    $usados = [];

    while ($row = $res->fetch_assoc()) {
        $email = strtolower(trim((string)($row['email'] ?? '')));

        if ($email === '') {
            $excluidos[] = [
                'email' => '',
                'motivo' => "Sin correo - {$row['nombre']} ({$row['localidad']})"
            ];
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $excluidos[] = [
                'email' => $email,
                'motivo' => "Correo inválido - {$row['nombre']}"
            ];
            continue;
        }

        if (isset($blacklist[$email])) {
            $excluidos[] = [
                'email' => $email,
                'motivo' => "Correo en blacklist"
            ];
            continue;
        }

        if (isset($usados[$email])) {
            continue;
        }

        $usados[$email] = true;
        $destinatarios[] = $email;
    }

    echo json_encode([
        'ok' => true,
        'total_validos' => count($destinatarios),
        'destinatarios' => $destinatarios,
        'excluidos' => $excluidos
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al generar la vista previa'
    ]);
}