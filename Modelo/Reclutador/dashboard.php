<?php
session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict"
]);
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["auth" => false, "message" => "Metodo no permitido"]);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

function rows_query($con, string $sql, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($con));
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function scalar_query($con, string $sql, string $types = '', array $params = []) {
    $rows = rows_query($con, $sql, $types, $params);
    if (!$rows) return 0;
    $first = array_values($rows[0]);
    return (int)($first[0] ?? 0);
}

function table_exists($con, string $table) {
    $safe = mysqli_real_escape_string($con, $table);
    $res = mysqli_query($con, "SHOW TABLES LIKE '$safe'");
    return $res && mysqli_num_rows($res) > 0;
}

try {
    $usuarioRows = rows_query($con,
        "SELECT id_usuario, nombres, apellidos, correo, foto_perfil, id_rol
         FROM usuarios
         WHERE id_usuario = ? AND estado = 1
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    if (!$usuarioRows || (int)$usuarioRows[0]["id_rol"] !== 3) {
        mysqli_close($con);
        http_response_code(403);
        echo json_encode(["auth" => false, "message" => "Solo reclutadores pueden consultar este dashboard"]);
        exit;
    }

    $usuario = $usuarioRows[0];

    $reclutadorRows = rows_query($con,
        "SELECT
            r.id_reclutador,
            r.puesto,
            r.id_empresas,
            e.nombre_empresas,
            e.estado_validacion
         FROM reclutadores r
         LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
         WHERE r.id_usuario = ?
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    if (!$reclutadorRows) {
        mysqli_close($con);
        http_response_code(404);
        echo json_encode(["auth" => true, "success" => false, "message" => "No existe perfil de reclutador"]);
        exit;
    }

    $reclutador = $reclutadorRows[0];
    $id_reclutador = (int)$reclutador["id_reclutador"];
    $empresaAprobada = ((int)($reclutador["estado_validacion"] ?? 0)) === 1;

    $vacantesActivas = scalar_query($con,
        "SELECT COUNT(*) FROM vacantes WHERE id_reclutador = ? AND estado = 'activa'",
        "i",
        [$id_reclutador]
    );

    $candidatosTotales = scalar_query($con,
        "SELECT COUNT(DISTINCT p.id_postulante)
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         WHERE v.id_reclutador = ?",
        "i",
        [$id_reclutador]
    );

    $postulacionesHoy = scalar_query($con,
        "SELECT COUNT(*)
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         WHERE v.id_reclutador = ?
           AND DATE(p.fecha_postulacion) = CURDATE()",
        "i",
        [$id_reclutador]
    );

    $reportesActivos = 0;
    if (table_exists($con, "reportes")) {
        $reportesActivos = scalar_query($con,
            "SELECT COUNT(*)
             FROM reportes r
             INNER JOIN vacantes v ON v.id_vacante = r.id_vacante
             WHERE v.id_reclutador = ?
               AND COALESCE(r.estado, 'activo') IN ('activo', 'pendiente')",
            "i",
            [$id_reclutador]
        );
    }

    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $label = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'][(int)date('w', strtotime($fecha))];
        $post = scalar_query($con,
            "SELECT COUNT(*)
             FROM postulaciones p
             INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
             WHERE v.id_reclutador = ?
               AND DATE(p.fecha_postulacion) = ?",
            "is",
            [$id_reclutador, $fecha]
        );
        $dias[] = [
            "day" => $label,
            "postulaciones" => $post,
            "vistas" => ($post * 4) + $vacantesActivas,
        ];
    }

    $vacantes = rows_query($con,
        "SELECT
            v.id_vacante AS id,
            v.titulo_puesto AS titulo,
            v.estado,
            v.fecha_publicacion,
            COUNT(p.id_postulacion) AS postulaciones
         FROM vacantes v
         LEFT JOIN postulaciones p ON p.id_vacante = v.id_vacante
         WHERE v.id_reclutador = ?
         GROUP BY v.id_vacante, v.titulo_puesto, v.estado, v.fecha_publicacion
         ORDER BY v.fecha_publicacion DESC
         LIMIT 4",
        "i",
        [$id_reclutador]
    );

    foreach ($vacantes as &$v) {
        $v["id"] = (int)$v["id"];
        $v["postulaciones"] = (int)$v["postulaciones"];
        $v["vistas"] = ($v["postulaciones"] * 4) + 12;
        $v["dias"] = max(0, (int)floor((time() - strtotime($v["fecha_publicacion"])) / 86400));
    }
    unset($v);

    $candidatos = rows_query($con,
        "SELECT
            p.id_postulacion AS id,
            LOWER(p.estado) AS estado,
            p.fecha_postulacion,
            v.titulo_puesto AS vacante,
            CONCAT(u.nombres, ' ', u.apellidos) AS nombre
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         INNER JOIN postulantes po ON po.id_postulante = p.id_postulante
         INNER JOIN usuarios u ON u.id_usuario = po.id_usuario
         WHERE v.id_reclutador = ?
         ORDER BY p.fecha_postulacion DESC
         LIMIT 4",
        "i",
        [$id_reclutador]
    );

    foreach ($candidatos as &$c) {
        $segundos = time() - strtotime($c["fecha_postulacion"]);
        if ($segundos < 86400) {
            $c["tiempo"] = max(1, (int)floor($segundos / 3600)) . "h";
        } else {
            $c["tiempo"] = (int)floor($segundos / 86400) . "d";
        }
        $c["id"] = (int)$c["id"];
    }
    unset($c);

    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "user" => [
            "id" => (int)$usuario["id_usuario"],
            "nombre" => trim($usuario["nombres"] . " " . $usuario["apellidos"]),
            "rol" => $reclutador["puesto"] ?: "Reclutador",
            "avatar" => $usuario["foto_perfil"],
        ],
        "empresa" => [
            "id" => $reclutador["id_empresas"] ? (int)$reclutador["id_empresas"] : null,
            "nombre" => $reclutador["nombre_empresas"] ?: "Empresa sin registrar",
            "estado" => $empresaAprobada ? "aprobada" : "pendiente",
            "aprobada" => $empresaAprobada,
        ],
        "stats" => [
            "vacantes_activas" => $vacantesActivas,
            "candidatos_totales" => $candidatosTotales,
            "postulaciones_hoy" => $postulacionesHoy,
            "reportes_activos" => $reportesActivos,
        ],
        "chart" => $dias,
        "vacantes_recientes" => $vacantes,
        "candidatos_recientes" => $candidatos,
    ]);
} catch (Throwable $e) {
    error_log("[RECLUTADOR_DASHBOARD] " . $e->getMessage());
    if (isset($con)) {
        mysqli_close($con);
    }
    http_response_code(500);
    echo json_encode(["auth" => true, "success" => false, "message" => "Error al cargar dashboard"]);
}
?>