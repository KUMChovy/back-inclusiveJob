<?php


require_once __DIR__ . '/../../../config/cors.php';
/*
|--------------------------------------------------------------------------
| Endpoint: Iniciar entrevista simulada con IA
|--------------------------------------------------------------------------
| Recibe id_vacante, valida que el usuario en sesion este postulado a esa
| vacante, arma el contexto de la entrevista y pide a Groq el mensaje de
| bienvenida + primera pregunta. No guarda nada en `chabots` todavia; el
| guardado final ocurre solo cuando se llama a entrevista_finalizar.php.
*/

inclusijob_session_cookie_params();
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "Metodo no permitido"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false, "ok" => false, "message" => "Sesion no activa"], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../../../Conexion.php";
require_once __DIR__ . "/entrevista_context.php";

const GROQ_URL = "https://api.groq.com/openai/v1/chat/completions";
const GROQ_MODEL = "llama-3.3-70b-versatile";
const GROQ_API_KEY_FALLBACK = "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";

function groq_api_key(): string {
    $env = getenv("GROQ_API_KEY");
    return $env ? $env : GROQ_API_KEY_FALLBACK;
}

function json_error(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(["ok" => false, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
$idVacante = (int)($body["id_vacante"] ?? 0);

if ($idVacante <= 0) {
    json_error(400, "Falta indicar la vacante para la entrevista.");
}

$idUsuario = (int)$_SESSION["user"]["id"];

try {
    $con = conectarbd();
    mysqli_set_charset($con, "utf8mb4");

    $vacante = obtener_vacante_para_entrevista($con, $idUsuario, $idVacante);
    mysqli_close($con);
    $con = null;

    if (!$vacante) {
        json_error(404, "No estas postulado a esa vacante o ya no existe.");
    }

    $contexto = construirContextoEntrevista($vacante);

    $payload = [
        "model" => GROQ_MODEL,
        "temperature" => 0.4,
        "max_tokens" => 500,
        "messages" => [
            ["role" => "system", "content" => $contexto],
            ["role" => "user", "content" => "Inicia la entrevista simulada."],
        ],
    ];

    $ch = curl_init(GROQ_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . groq_api_key(),
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        json_error(502, "Error cURL: " . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300 || !$response) {
        json_error(502, "No se pudo iniciar la entrevista con IA.");
    }

    $json = json_decode($response, true);
    $mensaje = trim((string)($json["choices"][0]["message"]["content"] ?? ""));

    if ($mensaje === "") {
        json_error(502, "La IA no genero una respuesta valida para iniciar la entrevista.");
    }

    echo json_encode([
        "auth" => true,
        "ok" => true,
        "mensaje" => $mensaje,
        "vacante" => [
            "id_vacante" => $vacante["id_vacante"],
            "titulo_puesto" => $vacante["titulo_puesto"],
            "empresa" => $vacante["empresa"],
            "modalidad" => $vacante["modalidad"],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli) {
        mysqli_close($con);
    }
    error_log("[POSTULANTE_ENTREVISTA_INICIAR] " . $e->getMessage());
    json_error(500, "Error interno al iniciar la entrevista.");
}

?>