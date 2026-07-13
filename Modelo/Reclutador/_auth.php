<?php


require_once __DIR__ . '/../../config/cors.php';
function reclutador_headers($methods = "GET, POST, OPTIONS") {
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Methods: " . $methods);

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit;
    }
}

function reclutador_start_session() {
    inclusijob_session_cookie_params();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function reclutador_responder($ok, $message, $data = null, $http = 200, $extra = []) {
    http_response_code($http);
    if (ob_get_length()) ob_clean();

    echo json_encode(array_merge([
        "success" => $ok,
        "ok" => $ok,
        "message" => $message,
        "data" => $data
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function reclutador_usuario_id() {
    reclutador_start_session();

    if (!isset($_SESSION["user"]) || empty($_SESSION["user"]["id"])) {
        reclutador_responder(false, "Acceso denegado. Inicia sesion nuevamente.", null, 401);
    }

    $_SESSION["last_activity"] = time();
    return (int)$_SESSION["user"]["id"];
}

function reclutador_id_desde_usuario($con, $id_usuario, $crear = false) {
    $sql = "SELECT id_reclutador FROM reclutadores WHERE id_usuario = ? LIMIT 1";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_usuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row) {
        return (int)$row["id_reclutador"];
    }

    if (!$crear) {
        reclutador_responder(false, "No se encontro el perfil de reclutador.", null, 404);
    }

    $id_empresa = 1;
    $puesto = "Reclutador";
    $insert = mysqli_prepare($con, "INSERT INTO reclutadores (puesto, id_usuario, id_empresas) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($insert, "sii", $puesto, $id_usuario, $id_empresa);

    if (!mysqli_stmt_execute($insert)) {
        reclutador_responder(false, "No se pudo crear el perfil de reclutador.", null, 500);
    }

    $id_reclutador = (int)mysqli_insert_id($con);
    mysqli_stmt_close($insert);

    return $id_reclutador;
}

function reclutador_json_body() {
    $input = json_decode(file_get_contents("php://input"), true);
    return is_array($input) ? $input : [];
}

function reclutador_columna_existe($con, $tabla, $columna) {
    $sql = "SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $tabla, $columna);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    return (int)($row["total"] ?? 0) > 0;
}

?>
