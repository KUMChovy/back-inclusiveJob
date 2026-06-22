<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin:http://localhost:5173");
header("Access-Control-Allow-Credentials: true");

/* =========================
   VALIDACIÓN DE SESIÓN
========================= */

if (!isset($_SESSION["user"])) {
    echo json_encode([
        "auth" => false
    ]);
    exit;
}

/* =========================
   CONFIGURACIÓN DE TIMEOUT
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
   EXPIRACIÓN POR INACTIVIDAD
========================= */

$inactiveTime = time() - $_SESSION["last_activity"];

if ($inactiveTime > $_SESSION["session_timeout"]) {

    // limpiar sesión completamente
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
        "message" => "Sesión expirada"
    ]);
    exit;
}

/* =========================
   SOLO AQUÍ se actualiza actividad
   (IMPORTANTE: después de validar timeout)
========================= */

//$_SESSION["last_activity"] = time();

/* =========================
   RESPUESTA
========================= */

echo json_encode([
    "auth" => true,
    "user" => $_SESSION["user"]
]);