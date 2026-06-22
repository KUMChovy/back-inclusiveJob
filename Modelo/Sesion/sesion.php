<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin:http://localhost:5173");
header("Access-Control-Allow-Credentials: true");

/* =========================
   CONTROL DE SESIÓN (TIMEOUT)
========================= */

// validar existencia de sesión
if (!isset($_SESSION["user"])) {
    echo json_encode([
        "auth" => false
    ]);
    exit;
}

// inicializar control si no existe (fallback seguro)
if (!isset($_SESSION["last_activity"])) {
    $_SESSION["last_activity"] = time();
}

if (!isset($_SESSION["session_timeout"])) {
    $_SESSION["session_timeout"] = 1800; // 30 min por defecto
}

/* =========================
   EXPIRACIÓN POR INACTIVIDAD
========================= */
if (time() - $_SESSION["last_activity"] > $_SESSION["session_timeout"]) {

    session_unset();
    session_destroy();

    echo json_encode([
        "auth" => false,
        "message" => "Sesión expirada"
    ]);
    exit;
}

/* =========================
   ACTUALIZAR ACTIVIDAD
========================= */
$_SESSION["last_activity"] = time();

/* =========================
   RESPUESTA SEGURA
========================= */
echo json_encode([
    "auth" => true,
    "user" => $_SESSION["user"]
]);