<?php

// =========================
// CORS
// =========================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Petición OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =========================
// Mostrar errores (desarrollo)
// =========================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =========================
// Función de respuesta JSON
// =========================
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// =========================
// Validar método
// =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        "error" => "Método no permitido"
    ], 405);
}

// =========================
// Conexión
// =========================
require_once __DIR__ . '/../Conexion.php';

$conn = conectarbd();

if (!$conn) {
    jsonResponse([
        "error" => "No se pudo conectar a la base de datos"
    ], 500);
}

// =========================
// Leer JSON recibido
// =========================
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    jsonResponse([
        "error" => "No se recibieron datos"
    ], 400);
}

$email = trim($input['email'] ?? '');

if (empty($email)) {
    jsonResponse([
        "error" => "Correo requerido"
    ], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse([
        "error" => "Correo inválido"
    ], 400);
}

try {

    // =========================
    // Buscar usuario
    // =========================
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id_usuario, nombres FROM usuarios WHERE correo = ?"
    );

    if (!$stmt) {
        jsonResponse([
            "error" => mysqli_error($conn)
        ], 500);
    }

    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $email
    );

    mysqli_stmt_execute($stmt);

    $resultado = mysqli_stmt_get_result($stmt);

    $usuario = mysqli_fetch_assoc($resultado);

    // =========================
    // Respuesta genérica
    // =========================
    $respuesta = [
        "mensaje" => "Se enviará un correo a tu gmail."
    ];

    // =========================
    // Si existe usuario
    // =========================
    if ($usuario) {

        $token = bin2hex(random_bytes(32));

        $expiracion = date(
            'Y-m-d H:i:s',
            strtotime('+1 hour')
        );

        $sql = "
            INSERT INTO tokens
            (
                token,
                tipo,
                tiempo_expira,
                veces_usado,
                tiempo_creado,
                id_usuario
            )
            VALUES
            (
                ?,
                'password_reset',
                ?,
                0,
                NOW(),
                ?
            )
        ";

        $stmtInsert = mysqli_prepare(
            $conn,
            $sql
        );

        if (!$stmtInsert) {
            jsonResponse([
                "error" => mysqli_error($conn)
            ], 500);
        }

        mysqli_stmt_bind_param(
            $stmtInsert,
            "ssi",
            $token,
            $expiracion,
            $usuario['id_usuario']
        );

        if (!mysqli_stmt_execute($stmtInsert)) {
            jsonResponse([
                "error" => mysqli_stmt_error($stmtInsert)
            ], 500);
        }

        // =========================
        // Enlace de recuperación
        // =========================
        $resetLink =
            "http://localhost/back-inclusiveJob/API/reset_password.php?token="
            . $token;

        $asunto = "Restablecer contraseña";

        $mensajeCorreo =
            "Hola " . $usuario['nombres'] . "\n\n" .
            "Haz clic en el siguiente enlace:\n\n" .
            $resetLink .
            "\n\nEste enlace expirará en 1 hora.";

        $headers = "From: no-reply@inclusijob.com\r\n";

        $mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;

    // TU CORREO DE GMAIL
    $mail->Username   = 'donwarikobv@gmail.com';

    // CONTRASEÑA DE APLICACIÓN DE GOOGLE
    $mail->Password   = 'ilwkizprkxzhsiyt
';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(
        'tucorreo@gmail.com',
        'InclusiJob'
    );

            $mail->addAddress($email);

            $mail->isHTML(true);

            $mail->Subject = 'Restablecer contraseña';

            $mail->Body = "
                <h2>Recuperación de contraseña</h2>

                <p>Hola {$usuario['nombres']}</p>

                <p>Haz clic en el siguiente enlace para crear una nueva contraseña:</p>

                <a href='{$resetLink}'>
                    Restablecer contraseña
                </a>

                <p>Este enlace expirará en 1 hora.</p>
            ";

            $mail->send();

            $respuesta = [
                "mensaje" => "Correo enviado correctamente."
            ];

        } catch (Exception $e) {

            $respuesta = [
                "error" => "Error al enviar correo: " . $mail->ErrorInfo
            ];

        }
    }

    mysqli_close($conn);

    jsonResponse($respuesta);

} catch (Exception $e) {

    jsonResponse([
        "error" => $e->getMessage()
    ], 500);
}
