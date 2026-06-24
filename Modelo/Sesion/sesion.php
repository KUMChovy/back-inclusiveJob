<?php
session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict"
]);

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$accion = $_GET["accion"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $accion === "logout") {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    echo json_encode([
        "success" => true,
        "message" => "Sesion cerrada"
    ]);
    exit;
}

/* =========================
   VALIDACIÃ“N DE SESIÃ“N
========================= */

if (!isset($_SESSION["user"])) {
    echo json_encode([
        "auth" => false
    ]);
    exit;
}

/* =========================
   CONFIGURACIÃ“N DE TIMEOUT
   (solo se define una vez)
========================= */

if (!isset($_SESSION["session_timeout"])) {
    $_SESSION["session_timeout"] = 1800; // 30 min
}

/* =========================
   INICIALIZAR ACTIVIDAD
========================= */

if (!isset($_SESSION["last_activity"])) {
    $_SESSION["last_activity"] = time();
}

/* =========================
   EXPIRACIÃ“N POR INACTIVIDAD
========================= */

$inactiveTime = time() - $_SESSION["last_activity"];

if ($inactiveTime > $_SESSION["session_timeout"]) {

    // limpiar sesiÃ³n completamente
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    echo json_encode([
        "auth" => false,
        "message" => "SesiÃ³n expirada"
    ]);
    exit;
}

/* =========================
   SOLO AQUÃ se actualiza actividad
   (IMPORTANTE: despuÃ©s de validar timeout)
========================= */

//$_SESSION["last_activity"] = time();

require_once __DIR__ . "/../../Conexion.php";
$conn = conectarbd();
$idUsuario = (int)($_SESSION["user"]["id"] ?? 0);

if ($idUsuario > 0) {
    $sql = "SELECT
                u.id_usuario,
                u.nombres,
                u.apellidos,
                u.correo,
                u.foto_perfil,
                u.id_rol,
                r.nombre_rol
            FROM usuarios u
            INNER JOIN rol r ON u.id_rol = r.id_rol
            WHERE u.id_usuario = ?
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $idUsuario);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($res);

        if ($user) {
            $_SESSION["user"] = [
                "id" => (int)$user["id_usuario"],
                "rol" => $user["nombre_rol"],
                "nombre" => trim(($user["nombres"] ?? "") . " " . ($user["apellidos"] ?? "")),
                "nombres" => $user["nombres"],
                "apellido" => $user["apellidos"],
                "correo" => $user["correo"],
                "foto_perfil" => $user["foto_perfil"],
                "avatar" => $user["foto_perfil"],
                "id_rol" => (int)$user["id_rol"],
            ];
        }
    }
}

/* =========================
   RESPUESTA
========================= */

echo json_encode([
    "auth" => true,
    "user" => $_SESSION["user"]
]);
