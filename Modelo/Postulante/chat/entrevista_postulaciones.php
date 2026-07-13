<?php


require_once __DIR__ . '/../../../config/cors.php';
/*
|--------------------------------------------------------------------------
| Endpoint: Postulaciones del postulante para simular entrevista
|--------------------------------------------------------------------------
| Devuelve las vacantes a las que el usuario en sesion ya se postulo, para
| que el chatbot las muestre como tarjetas y el usuario elija una y simule
| una entrevista basada en esa vacante real.
*/

inclusijob_session_cookie_params();
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
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

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false, "ok" => false, "message" => "Sesion no activa"], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../../../Conexion.php";

$idUsuario = (int)$_SESSION["user"]["id"];

try {
    $con = conectarbd();
    mysqli_set_charset($con, "utf8mb4");

    $sql = "
        SELECT
            po.id_postulacion,
            po.estado AS estado_postulacion,
            po.fecha_postulacion,
            v.id_vacante,
            v.titulo_puesto,
            v.descripcion_puesto,
            v.modalidad,
            v.salario_min,
            v.salario_max,
            v.estado AS estado_vacante,
            COALESCE(e.nombre_empresas, 'Empresa') AS empresa
        FROM postulaciones po
        INNER JOIN postulantes p ON p.id_postulante = po.id_postulante
        INNER JOIN vacantes v ON v.id_vacante = po.id_vacante
        INNER JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
        INNER JOIN empresas e ON e.id_empresas = r.id_empresas
        WHERE p.id_usuario = ?
          AND COALESCE(e.estado_validacion, 0) = 1
        ORDER BY po.fecha_postulacion DESC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($con));
    }

    mysqli_stmt_bind_param($stmt, "i", $idUsuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $postulaciones = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $postulaciones[] = [
            "id_postulacion" => (int)$row["id_postulacion"],
            "estado_postulacion" => $row["estado_postulacion"],
            "fecha_postulacion" => $row["fecha_postulacion"],
            "vacante" => [
                "id_vacante" => (int)$row["id_vacante"],
                "titulo_puesto" => $row["titulo_puesto"],
                "descripcion_puesto" => $row["descripcion_puesto"],
                "modalidad" => $row["modalidad"],
                "salario_min" => $row["salario_min"] !== null ? (float)$row["salario_min"] : null,
                "salario_max" => $row["salario_max"] !== null ? (float)$row["salario_max"] : null,
                "estado" => $row["estado_vacante"],
                "empresa" => $row["empresa"],
            ],
        ];
    }

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "ok" => true,
        "postulaciones" => $postulaciones,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli) {
        mysqli_close($con);
    }
    error_log("[POSTULANTE_ENTREVISTA_POSTULACIONES] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["auth" => true, "ok" => false, "message" => "Error al cargar tus postulaciones"], JSON_UNESCAPED_UNICODE);
}

?>
