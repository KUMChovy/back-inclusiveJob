<?php

session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict",
]);
session_start();

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
    echo "Metodo no permitido.";
    exit;
}

$mensaje = $_POST["mensaje"] ?? "";
if (trim($mensaje) === "") {
    http_response_code(400);
    echo "Escribe un mensaje.";
    exit;
}

$apiKey = "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";
$url = "https://api.groq.com/openai/v1/chat/completions";

$datos = [
    "model" => "llama-3.3-70b-versatile",
    "messages" => [
        [
            "role" => "system",
            "content" => "Eres el chatbot de InclusiveJob. Ayudas a personas con discapacidad a encontrar empleo. Responde de forma clara, breve y util.",
        ],
        [
            "role" => "user",
            "content" => $mensaje,
        ],
    ],
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $apiKey,
    "Content-Type: application/json",
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos, JSON_UNESCAPED_UNICODE));

$respuesta = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(502);
    echo "Error cURL: " . curl_error($ch);
    curl_close($ch);
    exit;
}
curl_close($ch);

$json = json_decode($respuesta, true);
$textoRespuesta = $json["choices"][0]["message"]["content"] ?? "Ocurrio un error.";

if (isset($_SESSION["user"]["id"])) {
    require_once __DIR__ . "/../../../Conexion.php";
    require_once __DIR__ . "/chat_store.php";

    try {
        $con = conectarbd();
        save_chat_interaction(
            $con,
            (int)$_SESSION["user"]["id"],
            "chat_general",
            trim($mensaje),
            $textoRespuesta,
            "chat_general"
        );
        mysqli_close($con);
    } catch (Throwable $e) {
        if (isset($con) && $con instanceof mysqli) {
            mysqli_close($con);
        }
        error_log("[POSTULANTE_CHATBOT_SAVE] " . $e->getMessage());
    }
}

echo $textoRespuesta;

?>
