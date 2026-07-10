<?php

/*
|--------------------------------------------------------------------------
| Endpoint: Continuar entrevista simulada con IA
|--------------------------------------------------------------------------
| Recibe id_vacante, el historial de la conversacion (que mantiene el
| frontend en memoria mientras el modal esta abierto) y el nuevo mensaje
| del candidato. Vuelve a validar la postulacion y reconstruye el contexto
| en el backend (no confia en datos de vacante que pudiera mandar el
| frontend), para evitar manipulacion del prompt desde el cliente.
*/

session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict",
]);
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
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
$mensaje = trim((string)($body["mensaje"] ?? ""));
$historialCrudo = $body["historial"] ?? [];

if ($idVacante <= 0) {
    json_error(400, "Falta indicar la vacante de la entrevista.");
}

if ($mensaje === "") {
    json_error(400, "Escribe una respuesta antes de continuar.");
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
    $historial = limpiar_historial_entrevista($historialCrudo);

    $mensajes = [["role" => "system", "content" => $contexto]];
    foreach ($historial as $item) {
        $mensajes[] = $item;
    }
    $mensajes[] = ["role" => "user", "content" => mb_substr($mensaje, 0, 2000, "UTF-8")];

    $payload = [
        "model" => GROQ_MODEL,
        "temperature" => 0.4,
        "max_tokens" => 500,
        "messages" => $mensajes,
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
        json_error(502, "No se pudo continuar la entrevista con IA.");
    }

    $json = json_decode($response, true);
    $respuesta = trim((string)($json["choices"][0]["message"]["content"] ?? ""));

    if ($respuesta === "") {
        json_error(502, "La IA no genero una respuesta valida.");
    }

    echo json_encode([
        "auth" => true,
        "ok" => true,
        "mensaje" => $respuesta,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli) {
        mysqli_close($con);
    }
    error_log("[POSTULANTE_ENTREVISTA_RESPONDER] " . $e->getMessage());
    json_error(500, "Error interno al continuar la entrevista.");
}

?>