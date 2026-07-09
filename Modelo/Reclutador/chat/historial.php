<?php

/*
|--------------------------------------------------------------------------
| Endpoint: Historial del chatbot del reclutador
|--------------------------------------------------------------------------
| Devuelve las ultimas interacciones guardadas en `chabots` para reconstruir
| el chat cuando el reclutador vuelve a entrar.
|
| Diferencia con el historial de Postulante: aqui NO se selecciona ni se
| devuelve `archivo_cv`, porque el modulo Reclutador no lo utiliza.
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
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "Metodo no permitido"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ⚠️ SUPUESTO: se asume que el login de Reclutador guarda el id de sesion
// en la misma clave que Postulante ($_SESSION["user"]["id"]), ya que
// `chabots.id_usuario` es un campo generico compartido entre ambos modulos.
// Si tu login de Reclutador guarda el id en otra clave de sesion
// (por ejemplo $_SESSION["reclutador"]["id"]), cambia la linea de abajo.
if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false, "ok" => false, "message" => "Sesion no activa"], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../../../Conexion.php";

$idUsuario = (int)$_SESSION["user"]["id"];
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

try {
    $sql = "
        SELECT *
        FROM (
            SELECT
                id_chatbot,
                tipo_interaccion,
                mensaje_usuario,
                respuesta_ia,
                resultado,
                calificacion,
                fecha
            FROM chabots
            WHERE id_usuario = ?
            ORDER BY fecha DESC, id_chatbot DESC
            LIMIT 40
        ) historial
        ORDER BY fecha ASC, id_chatbot ASC
    ";

    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $idUsuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $items[] = [
            "id" => (int)$row["id_chatbot"],
            "tipo_interaccion" => $row["tipo_interaccion"],
            "mensaje_usuario" => $row["mensaje_usuario"],
            "respuesta_ia" => $row["respuesta_ia"],
            "resultado" => $row["resultado"],
            "calificacion" => $row["calificacion"] !== null ? (int)$row["calificacion"] : null,
            "fecha" => $row["fecha"],
        ];
    }

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "ok" => true,
        "historial" => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    mysqli_close($con);
    error_log("[RECLUTADOR_CHAT_HISTORIAL] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["auth" => true, "ok" => false, "message" => "Error al cargar historial"], JSON_UNESCAPED_UNICODE);
}

?>