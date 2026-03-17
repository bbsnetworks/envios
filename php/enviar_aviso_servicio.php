<?php
header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config_blacklist_correos.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$tipo         = trim($data['tipo'] ?? '');
$modoEnvio    = trim($data['modo_envio'] ?? 'localidades');
$asunto       = trim($data['asunto'] ?? '');
$fecha        = trim($data['fecha'] ?? '');
$hora         = trim($data['hora'] ?? '');
$mensaje      = trim($data['mensaje'] ?? '');
$mensajeExtra = trim($data['mensaje_extra'] ?? '');
$localidades  = $data['localidades'] ?? [];

if (!is_array($localidades)) {
    $localidades = [];
}

if ($asunto === '' || $mensaje === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => 'Asunto y mensaje son obligatorios'
    ]);
    exit;
}

if ($modoEnvio !== 'todos' && count($localidades) === 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => 'Debes seleccionar al menos una localidad'
    ]);
    exit;
}

try {
    $appName   = 'BBSNetworks';
    $replyMail = 'noreply@bbsnetworks.net';

    $blacklist = array_map(function($email) {
        return strtolower(trim($email));
    }, $BLACKLIST_CORREOS);

    $blacklist = array_flip(array_filter($blacklist));

    $params = [];
    $types = '';
    $whereLocalidades = '';

    if ($modoEnvio !== 'todos') {
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
        throw new Exception('Error al preparar consulta: ' . $conexion->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $destinatarios = [];
    $excluidos = [];
    $map = [];

    while ($row = $res->fetch_assoc()) {
        $email = strtolower(trim((string)($row['email'] ?? '')));

        if ($email === '') {
            $excluidos[] = [
                'email' => '',
                'motivo' => "Sin correo - {$row['nombre']}"
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

        if (isset($map[$email])) {
            continue;
        }

        $map[$email] = [
            'nombre' => $row['nombre'],
            'localidad' => $row['localidad']
        ];

        $destinatarios[] = $email;
    }

    if (count($destinatarios) === 0) {
        echo json_encode([
            'ok' => false,
            'msg' => 'No hay destinatarios válidos para enviar'
        ]);
        exit;
    }

    $localidadesTexto = ($modoEnvio === 'todos')
        ? 'Todos los clientes activos'
        : implode(', ', $localidades);

    $enviados = 0;
    $fallidos = 0;
    $erroresEnvio = [];

    foreach ($destinatarios as $correo) {
        $nombreCliente = $map[$correo]['nombre'] ?? 'Cliente';

        $html = construirHtmlAviso(
            $appName,
            $nombreCliente,
            $asunto,
            $tipo,
            $fecha,
            $hora,
            $localidadesTexto,
            $mensaje,
            $mensajeExtra
        );

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.titan.email';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'noreply@bbsnetworks.net';
            $mail->Password   = 'Admin1_Pinck';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('noreply@bbsnetworks.net', $appName);
            $mail->addAddress($correo, $nombreCliente);
            $mail->addReplyTo($replyMail, $appName);

            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $html;
            $mail->AltBody = construirTextoPlano(
                $appName,
                $nombreCliente,
                $asunto,
                $tipo,
                $fecha,
                $hora,
                $localidadesTexto,
                $mensaje,
                $mensajeExtra
            );

            $mail->send();
            $enviados++;

        } catch (Throwable $e) {
            $fallidos++;
            $erroresEnvio[] = $correo . ': ' . $e->getMessage();
            error_log('Error PHPMailer [' . $correo . ']: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'ok' => true,
        'enviados' => $enviados,
        'excluidos' => count($excluidos),
        'fallidos' => $fallidos,
        'errores' => $erroresEnvio
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al enviar avisos: ' . $e->getMessage()
    ]);
}

function construirHtmlAviso($appName, $nombreCliente, $asunto, $tipo, $fecha, $hora, $localidades, $mensaje, $mensajeExtra = '')
{
    $appName       = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $nombreCliente = htmlspecialchars($nombreCliente, ENT_QUOTES, 'UTF-8');
    $asunto        = htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8');
    $tipo          = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
    $fecha         = htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8');
    $hora          = htmlspecialchars($hora, ENT_QUOTES, 'UTF-8');
    $localidades   = htmlspecialchars($localidades, ENT_QUOTES, 'UTF-8');
    $mensaje       = nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'));
    $mensajeExtra  = nl2br(htmlspecialchars($mensajeExtra, ENT_QUOTES, 'UTF-8'));

    $extraBlock = '';
    if (trim(strip_tags($mensajeExtra)) !== '') {
        $extraBlock = "
        <div style='margin-top:24px;padding:18px;border-radius:18px;background:rgba(250,204,21,0.10);border:1px solid rgba(250,204,21,0.20);'>
            <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#fde68a;margin-bottom:10px;'>Información adicional</div>
            <div style='font-size:15px;line-height:1.8;color:#e2e8f0;'>{$mensajeExtra}</div>
        </div>";
    }

    return "
    <div style='margin:0;padding:0;background:#071322;font-family:Arial,Helvetica,sans-serif;color:#fff;'>
      <div style='max-width:700px;margin:0 auto;padding:28px 16px;'>
        <div style='border-radius:28px;overflow:hidden;background:#081728;border:1px solid rgba(255,255,255,.08);box-shadow:0 20px 50px rgba(0,0,0,.35);'>
          <div style='padding:28px;background:linear-gradient(135deg,#0ea5e9 0%,#1d4ed8 40%,#071322 100%);'>
            <div style='font-size:28px;font-weight:700;color:#fff;'>{$appName}</div>
            <div style='font-size:14px;color:#cffafe;margin-top:6px;'>Aviso importante de servicio</div>
          </div>

          <div style='padding:30px;'>
            <div style='font-size:15px;color:#cbd5e1;margin-bottom:10px;'>Hola, {$nombreCliente}</div>
            <div style='font-size:24px;font-weight:700;color:#fff;margin-bottom:14px;'>{$asunto}</div>
            <div style='font-size:14px;color:#94a3b8;margin-bottom:24px;'>Fecha del aviso: {$fecha} " . ($hora !== '' ? "· Hora estimada: {$hora}" : "") . "</div>

            <div style='margin-bottom:18px;'>
              <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>Tipo</div>
              <div style='font-size:15px;line-height:1.7;color:#e2e8f0;'>{$tipo}</div>
            </div>

            <div style='margin-bottom:18px;'>
              <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>Zona o localidades afectadas</div>
              <div style='font-size:15px;line-height:1.7;color:#e2e8f0;'>{$localidades}</div>
            </div>

            <div style='margin-bottom:18px;'>
              <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>Mensaje</div>
              <div style='font-size:15px;line-height:1.8;color:#e2e8f0;'>{$mensaje}</div>
            </div>

            {$extraBlock}

            <div style='margin-top:26px;padding:18px;border-radius:18px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);font-size:14px;line-height:1.8;color:#cbd5e1;'>
              Agradecemos su comprensión. Nuestro equipo técnico ya se encuentra trabajando para restablecer el servicio lo antes posible. No es necesario responder a este correo.
            </div>
          </div>

          <div style='padding:18px 30px;border-top:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.03);font-size:12px;color:#94a3b8;'>
            © {$appName} · Aviso automático de servicio
          </div>
        </div>
      </div>
    </div>";
}

function construirTextoPlano($appName, $nombreCliente, $asunto, $tipo, $fecha, $hora, $localidades, $mensaje, $mensajeExtra = '')
{
    return
        $appName . " - Aviso de servicio\n\n" .
        "Hola, {$nombreCliente}\n\n" .
        "Asunto: {$asunto}\n" .
        "Tipo: {$tipo}\n" .
        "Fecha: {$fecha}" . ($hora !== '' ? " | Hora estimada: {$hora}" : "") . "\n" .
        "Localidades afectadas: {$localidades}\n\n" .
        "Mensaje:\n{$mensaje}\n\n" .
        ($mensajeExtra !== '' ? "Información adicional:\n{$mensajeExtra}\n\n" : '') .
        "Agradecemos su comprensión. Nuestro equipo técnico ya se encuentra trabajando para restablecer el servicio lo antes posible.";
}