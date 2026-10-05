<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config_blacklist_correos.php';

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN SMTP
|--------------------------------------------------------------------------
|
| Recomendado:
|   Crear una variable de entorno llamada BBS_SMTP_PASSWORD con la contraseña
|   de la cuenta noreply@bbsnetworks.net.
|
| No dejes la contraseña directamente escrita en este archivo.
|
*/

$smtpHost = 'smtp.titan.email';
$smtpPort = 587;
$smtpUser = 'noreply@bbsnetworks.net';
$smtpPassword = (string) getenv('Admin1_Pinck');

$appName   = 'BBSNetworks';
$replyMail = 'noreply@bbsnetworks.net';

/*
|--------------------------------------------------------------------------
| CONTROL DE RÁFAGA
|--------------------------------------------------------------------------
|
| Estas pausas ayudan a evitar que el servidor reciba demasiados comandos
| seguidos. NO sustituyen los límites por hora/día de Titan.
|
| 500000 microsegundos = 0.5 segundos.
|
*/
$pausaEntreEnviosUs = 500000;
$pausaCadaNEnvios   = 20;
$pausaBloqueSeg     = 3;

/*
|--------------------------------------------------------------------------
| LECTURA DEL JSON
|--------------------------------------------------------------------------
*/

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok'  => false,
        'msg' => 'La solicitud JSON no es válida'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tipo         = trim((string) ($data['tipo'] ?? ''));
$modoEnvio    = trim((string) ($data['modo_envio'] ?? 'localidades'));
$asunto       = trim((string) ($data['asunto'] ?? ''));
$fecha        = trim((string) ($data['fecha'] ?? ''));
$hora         = trim((string) ($data['hora'] ?? ''));
$mensaje      = trim((string) ($data['mensaje'] ?? ''));
$mensajeExtra = trim((string) ($data['mensaje_extra'] ?? ''));
$localidades  = $data['localidades'] ?? [];

if (!is_array($localidades)) {
    $localidades = [];
}

/*
|--------------------------------------------------------------------------
| VALIDACIONES
|--------------------------------------------------------------------------
*/

if ($asunto === '' || $mensaje === '') {
    http_response_code(400);

    echo json_encode([
        'ok'  => false,
        'msg' => 'Asunto y mensaje son obligatorios'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($modoEnvio !== 'todos' && count($localidades) === 0) {
    http_response_code(400);

    echo json_encode([
        'ok'  => false,
        'msg' => 'Debes seleccionar al menos una localidad'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($smtpPassword === '') {
    http_response_code(500);

    echo json_encode([
        'ok'  => false,
        'msg' => 'No está configurada la variable de entorno BBS_SMTP_PASSWORD'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| EVITAR QUE UNA TANDA GRANDE MUERA POR TIMEOUT DEL PHP
|--------------------------------------------------------------------------
|
| En algunos hostings set_time_limit puede estar deshabilitado. El @ evita
| que esa situación rompa la respuesta.
|
*/

@set_time_limit(0);
@ignore_user_abort(true);

$mail = null;

try {

    /*
    |--------------------------------------------------------------------------
    | BLACKLIST
    |--------------------------------------------------------------------------
    */

    $blacklist = array_map(
        static function ($email): string {
            return strtolower(trim((string) $email));
        },
        $BLACKLIST_CORREOS
    );

    $blacklist = array_flip(array_filter($blacklist));

    /*
    |--------------------------------------------------------------------------
    | CONSULTA DE CLIENTES
    |--------------------------------------------------------------------------
    */

    $params = [];
    $types = '';
    $whereLocalidades = '';

    if ($modoEnvio !== 'todos') {

        $localidades = array_values(
            array_filter(
                array_map(
                    static fn($valor): string => trim((string) $valor),
                    $localidades
                ),
                static fn($valor): bool => $valor !== ''
            )
        );

        if (count($localidades) === 0) {
            http_response_code(400);

            echo json_encode([
                'ok'  => false,
                'msg' => 'Debes seleccionar al menos una localidad válida'
            ], JSON_UNESCAPED_UNICODE);

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
        throw new Exception(
            'Error al preparar consulta: ' . $conexion->error
        );
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception(
            'Error al ejecutar consulta: ' . $stmt->error
        );
    }

    $res = $stmt->get_result();

    /*
    |--------------------------------------------------------------------------
    | DEPURACIÓN DE DESTINATARIOS
    |--------------------------------------------------------------------------
    */

    $destinatarios = [];
    $excluidos = [];
    $map = [];

    while ($row = $res->fetch_assoc()) {

        $email = strtolower(
            trim((string) ($row['email'] ?? ''))
        );

        $nombre = trim((string) ($row['nombre'] ?? 'Cliente'));
        $localidad = trim((string) ($row['localidad'] ?? ''));

        if ($email === '') {

            $excluidos[] = [
                'email'  => '',
                'motivo' => "Sin correo - {$nombre}"
            ];

            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $excluidos[] = [
                'email'  => $email,
                'motivo' => "Correo inválido - {$nombre}"
            ];

            continue;
        }

        if (isset($blacklist[$email])) {

            $excluidos[] = [
                'email'  => $email,
                'motivo' => 'Correo en blacklist'
            ];

            continue;
        }

        /*
         * Evitar mandar dos veces al mismo correo cuando aparece repetido
         * en la tabla clientes.
         */
        if (isset($map[$email])) {

            $excluidos[] = [
                'email'  => $email,
                'motivo' => 'Correo duplicado'
            ];

            continue;
        }

        $map[$email] = [
            'nombre'    => $nombre !== '' ? $nombre : 'Cliente',
            'localidad' => $localidad
        ];

        $destinatarios[] = $email;
    }

    $stmt->close();

    if (count($destinatarios) === 0) {

        echo json_encode([
            'ok'        => false,
            'msg'       => 'No hay destinatarios válidos para enviar',
            'excluidos' => count($excluidos)
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $localidadesTexto = ($modoEnvio === 'todos')
        ? 'Todos los clientes activos'
        : implode(', ', $localidades);

    /*
    |--------------------------------------------------------------------------
    | CREAR UNA SOLA INSTANCIA SMTP
    |--------------------------------------------------------------------------
    |
    | El cambio más importante respecto al archivo anterior:
    | PHPMailer se configura una sola vez y la conexión se mantiene viva.
    |
    */

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $smtpPort;
    $mail->CharSet    = 'UTF-8';

    /*
     * Mantener la conexión abierta entre destinatarios evita autenticar
     * nuevamente para cada cliente.
     */
    $mail->SMTPKeepAlive = true;

    /*
     * Timeouts razonables para que un problema de red no congele la tanda.
     */
    $mail->Timeout  = 30;
    $mail->Timelimit = 30;

    /*
     * Desactivar debug en producción.
     * Si necesitas revisar SMTP temporalmente puedes usar:
     * $mail->SMTPDebug = 2;
     */
    $mail->SMTPDebug = 0;

    $mail->setFrom($smtpUser, $appName);
    $mail->addReplyTo($replyMail, $appName);
    $mail->isHTML(true);

    /*
    |--------------------------------------------------------------------------
    | CONTADORES
    |--------------------------------------------------------------------------
    */

    $enviados = 0;
    $fallidos = 0;
    $procesados = 0;

    $erroresEnvio = [];

    /*
    |--------------------------------------------------------------------------
    | ENVÍO
    |--------------------------------------------------------------------------
    */

    foreach ($destinatarios as $correo) {

        $procesados++;

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

        /*
         * MUY IMPORTANTE:
         * Como reutilizamos PHPMailer, debemos quitar el destinatario anterior
         * antes de agregar el nuevo.
         */
        $mail->clearAddresses();
        $mail->clearCCs();
        $mail->clearBCCs();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();

        $mail->addAddress($correo, $nombreCliente);

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

        /*
         * Máximo dos intentos.
         *
         * Solo reintentamos si parece ser un error temporal:
         * 421, 450, 451, 452, conexión cerrada, timeout, etc.
         *
         * Los errores permanentes, cuotas y rechazos 550 no se reintentan.
         */
        $maxIntentos = 2;
        $intento = 0;
        $enviadoActual = false;

        while ($intento < $maxIntentos && !$enviadoActual) {

            $intento++;

            try {

                $mail->send();

                $enviados++;
                $enviadoActual = true;

            } catch (Throwable $e) {

                $detalleError = trim(
                    $mail->ErrorInfo !== ''
                        ? $mail->ErrorInfo
                        : $e->getMessage()
                );

                $esTemporal = esErrorTemporalSmtp($detalleError);

                /*
                 * Si la conexión quedó dañada, la cerramos.
                 * PHPMailer volverá a conectarse automáticamente en el
                 * siguiente send().
                 */
                $mail->smtpClose();

                if ($esTemporal && $intento < $maxIntentos) {

                    error_log(
                        "Reintento SMTP [{$correo}] intento {$intento}: {$detalleError}"
                    );

                    /*
                     * Pequeña espera antes del segundo intento.
                     */
                    usleep(1500000);

                    continue;
                }

                $fallidos++;

                $erroresEnvio[] = [
                    'correo'   => $correo,
                    'intentos' => $intento,
                    'temporal' => $esTemporal,
                    'error'    => $detalleError
                ];

                error_log(
                    "Error PHPMailer [{$correo}] intento {$intento}: {$detalleError}"
                );
            }
        }

        /*
         * Suaviza la ráfaga de envíos.
         *
         * IMPORTANTE:
         * esto NO evita exceder una cuota por hora/día del proveedor.
         */
        if ($pausaEntreEnviosUs > 0 && $procesados < count($destinatarios)) {
            usleep($pausaEntreEnviosUs);
        }

        /*
         * Descanso adicional cada cierto número de mensajes.
         */
        if (
            $pausaCadaNEnvios > 0
            && $procesados % $pausaCadaNEnvios === 0
            && $procesados < count($destinatarios)
        ) {
            sleep($pausaBloqueSeg);
        }
    }

    /*
     * Cerrar correctamente la conexión persistente.
     */
    $mail->smtpClose();

    /*
    |--------------------------------------------------------------------------
    | RESPUESTA
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'ok'                  => true,
        'total_destinatarios' => count($destinatarios),
        'procesados'          => $procesados,
        'enviados'            => $enviados,
        'excluidos'           => count($excluidos),
        'fallidos'            => $fallidos,
        'errores'             => $erroresEnvio
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    if ($mail instanceof PHPMailer) {
        $mail->smtpClose();
    }

    error_log(
        'Error general al enviar avisos: ' . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'ok'  => false,
        'msg' => 'Error al enviar avisos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


/*
|--------------------------------------------------------------------------
| DETERMINAR SI CONVIENE REINTENTAR
|--------------------------------------------------------------------------
*/

function esErrorTemporalSmtp(string $error): bool
{
    $texto = strtolower($error);

    /*
     * Si el proveedor está indicando cuota, rate limit o rebotes,
     * reintentar inmediatamente solo empeoraría la situación.
     */
    $erroresNoReintentables = [
        'quota',
        'rate limit',
        'rate-limit',
        'hourly limit',
        'daily limit',
        'bounce limit',
        'sender hourly',
        'too many messages',
        'too many emails',
        'mailbox unavailable',
        'recipient rejected',
        'user unknown',
        'does not exist'
    ];

    foreach ($erroresNoReintentables as $patron) {
        if (str_contains($texto, $patron)) {
            return false;
        }
    }

    /*
     * Códigos SMTP normalmente temporales.
     */
    if (preg_match('/\b(421|450|451|452|454)\b/', $error)) {
        return true;
    }

    /*
     * Errores transitorios de red/conexión.
     */
    $erroresTemporales = [
        'timed out',
        'timeout',
        'connection refused',
        'connection closed',
        'connection reset',
        'could not connect',
        'smtp connect() failed',
        'temporarily unavailable',
        'temporary failure',
        'try again later'
    ];

    foreach ($erroresTemporales as $patron) {
        if (str_contains($texto, $patron)) {
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| HTML DEL AVISO
|--------------------------------------------------------------------------
*/

function construirHtmlAviso(
    string $appName,
    string $nombreCliente,
    string $asunto,
    string $tipo,
    string $fecha,
    string $hora,
    string $localidades,
    string $mensaje,
    string $mensajeExtra = ''
): string {

    $appName       = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $nombreCliente = htmlspecialchars($nombreCliente, ENT_QUOTES, 'UTF-8');
    $asunto        = htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8');
    $tipo          = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
    $fecha         = htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8');
    $hora          = htmlspecialchars($hora, ENT_QUOTES, 'UTF-8');
    $localidades   = htmlspecialchars($localidades, ENT_QUOTES, 'UTF-8');

    $mensaje = nl2br(
        htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
    );

    $mensajeExtra = nl2br(
        htmlspecialchars($mensajeExtra, ENT_QUOTES, 'UTF-8')
    );

    $extraBlock = '';

    if (trim(strip_tags($mensajeExtra)) !== '') {

        $extraBlock = "
        <div style='margin-top:24px;padding:18px;border-radius:18px;background:rgba(250,204,21,0.10);border:1px solid rgba(250,204,21,0.20);'>
            <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#fde68a;margin-bottom:10px;'>
                Información adicional
            </div>

            <div style='font-size:15px;line-height:1.8;color:#e2e8f0;'>
                {$mensajeExtra}
            </div>
        </div>";
    }

    $horaHtml = ($hora !== '')
        ? "· Hora estimada: {$hora}"
        : '';

    return "
    <div style='margin:0;padding:0;background:#071322;font-family:Arial,Helvetica,sans-serif;color:#fff;'>

        <div style='max-width:700px;margin:0 auto;padding:28px 16px;'>

            <div style='border-radius:28px;overflow:hidden;background:#081728;border:1px solid rgba(255,255,255,.08);box-shadow:0 20px 50px rgba(0,0,0,.35);'>

                <div style='padding:28px;background:linear-gradient(135deg,#0ea5e9 0%,#1d4ed8 40%,#071322 100%);'>

                    <div style='font-size:28px;font-weight:700;color:#fff;'>
                        {$appName}
                    </div>

                    <div style='font-size:14px;color:#cffafe;margin-top:6px;'>
                        Aviso importante de servicio
                    </div>

                </div>

                <div style='padding:30px;'>

                    <div style='font-size:15px;color:#cbd5e1;margin-bottom:10px;'>
                        Hola, {$nombreCliente}
                    </div>

                    <div style='font-size:24px;font-weight:700;color:#fff;margin-bottom:14px;'>
                        {$asunto}
                    </div>

                    <div style='font-size:14px;color:#94a3b8;margin-bottom:24px;'>
                        Fecha del aviso: {$fecha} {$horaHtml}
                    </div>

                    <div style='margin-bottom:18px;'>

                        <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>
                            Tipo
                        </div>

                        <div style='font-size:15px;line-height:1.7;color:#e2e8f0;'>
                            {$tipo}
                        </div>

                    </div>

                    <div style='margin-bottom:18px;'>

                        <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>
                            Zona o localidades afectadas
                        </div>

                        <div style='font-size:15px;line-height:1.7;color:#e2e8f0;'>
                            {$localidades}
                        </div>

                    </div>

                    <div style='margin-bottom:18px;'>

                        <div style='font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#67e8f9;margin-bottom:8px;'>
                            Mensaje
                        </div>

                        <div style='font-size:15px;line-height:1.8;color:#e2e8f0;'>
                            {$mensaje}
                        </div>

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


/*
|--------------------------------------------------------------------------
| TEXTO PLANO
|--------------------------------------------------------------------------
*/

function construirTextoPlano(
    string $appName,
    string $nombreCliente,
    string $asunto,
    string $tipo,
    string $fecha,
    string $hora,
    string $localidades,
    string $mensaje,
    string $mensajeExtra = ''
): string {

    return
        $appName . " - Aviso de servicio\n\n" .
        "Hola, {$nombreCliente}\n\n" .
        "Asunto: {$asunto}\n" .
        "Tipo: {$tipo}\n" .
        "Fecha: {$fecha}" .
        ($hora !== '' ? " | Hora estimada: {$hora}" : "") .
        "\n" .
        "Localidades afectadas: {$localidades}\n\n" .
        "Mensaje:\n{$mensaje}\n\n" .
        ($mensajeExtra !== ''
            ? "Información adicional:\n{$mensajeExtra}\n\n"
            : '') .
        "Agradecemos su comprensión. Nuestro equipo técnico ya se encuentra trabajando para restablecer el servicio lo antes posible.";
}


