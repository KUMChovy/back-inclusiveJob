<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// RESPUESTA AL PREFLIGHT (OPTIONS)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../Conexion.php";

try {

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Metodo no permitido"]);
        exit;
    }

    $con = conectarbd();

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "JSON inválido"]);
        exit;
    }

    // =========================
    // ID viene desde React
    // =========================
    $id_usuario = isset($input["id_usuario"]) ? (int)$input["id_usuario"] : 0;
    $password   = trim($input["password"] ?? "");

    if ($id_usuario <= 0) {
        echo json_encode(["success" => false, "message" => "ID de usuario inválido"]);
        exit;
    }

    if ($password === "") {
        echo json_encode(["success" => false, "message" => "Contraseña vacía"]);
        exit;
    }

    // =========================
    // VALIDACIÓN DE SEGURIDAD
    // =========================
    $passwordRegex = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,64}$/";

    if (!preg_match($passwordRegex, $password)) {
        echo json_encode([
            "success" => false,
            "message" => "La contraseña no cumple requisitos de seguridad"
        ]);
        exit;
    }

    // =========================
    // VERIFICAR USUARIO
    // =========================
    $stmt = mysqli_prepare(
        $con,
        "SELECT id_usuario FROM usuarios WHERE id_usuario = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_usuario);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode(["success" => false, "message" => "Usuario no encontrado"]);
        exit;
    }

    mysqli_stmt_close($stmt);

    // =========================
    // HASH
    // =========================
    $hash = password_hash($password, PASSWORD_BCRYPT);

    if (!$hash) {
        echo json_encode(["success" => false, "message" => "Error al generar hash"]);
        exit;
    }

    // =========================
    // UPDATE PASSWORD
    // =========================
    $update = mysqli_prepare(
        $con,
        "UPDATE usuarios 
         SET contraseña = ?, correo_validado = 1 
         WHERE id_usuario = ? 
         LIMIT 1"
    );

    mysqli_stmt_bind_param($update, "si", $hash, $id_usuario);

    if (!mysqli_stmt_execute($update)) {
        mysqli_stmt_close($update);
        echo json_encode(["success" => false, "message" => "No se pudo actualizar la contraseña"]);
        exit;
    }

    mysqli_stmt_close($update);

    echo json_encode([
        "success" => true,
        "message" => "Contraseña actualizada correctamente",
        "id_usuario" => $id_usuario
    ]);

} catch (Throwable $e) {

    error_log("update_password_google: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor"
    ]);
}