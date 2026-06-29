<?php

/*
|--------------------------------------------------------------------------
| API DE SESION - sesion.php
|--------------------------------------------------------------------------
| Este endpoint mantiene, consulta y destruye la sesion PHP.
|
| Usos desde el front:
| - GET sesion.php: devuelve { auth, user } si hay sesion activa.
| - POST sesion.php?accion=logout: destruye la sesion y borra la cookie.
|
| Flujo en GET:
| 1. Configura la cookie y headers CORS con credenciales.
| 2. Si no existe $_SESSION["user"], responde auth=false.
| 3. Valida timeout de inactividad.
| 4. Consulta la base de datos para refrescar nombre, rol, correo y foto.
| 5. Actualiza $_SESSION["user"] con datos completos y devuelve el usuario.
|
| Flujo en logout:
| 1. Limpia $_SESSION.
| 2. Borra la cookie PHPSESSID usando los parametros actuales.
| 3. Ejecuta session_destroy().
| 4. Devuelve success=true para que el front redirija al login.
*/

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

    ini_set('display_errors', 0);
error_reporting(0);

    session_destroy();

    echo json_encode([
        "success" => true,
        "message" => "Sesion cerrada"
    ]);
    exit;
}

/* =========================
   VALIDACION DE SESION
========================= */

if (!isset($_SESSION["user"])) {
    echo json_encode([
        "auth" => false
    ]);
    exit;
}

/* =========================
   CONFIGURACION DE TIMEOUT
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
   EXPIRACION POR INACTIVIDAD
========================= */

$inactiveTime = time() - $_SESSION["last_activity"];

if ($inactiveTime > $_SESSION["session_timeout"]) {

    // limpiar sesion completamente
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
        "message" => "Sesion expirada"
    ]);
    exit;
}

/* =========================
   SOLO AQUI se actualiza actividad
   (IMPORTANTE: despues de validar timeout)
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
