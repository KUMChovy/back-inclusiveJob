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

function scalar_query($con, string $sql, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($con));
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $row ? (int)$row[0] : 0;
}

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

try {
    $usuarioRows = rows_query($con,
        "SELECT id_usuario, nombres, apellidos, correo, telefono, foto_perfil, id_rol
         FROM usuarios
         WHERE id_usuario = ? AND estado = 1
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    if (!$usuarioRows || (int)$usuarioRows[0]["id_rol"] !== 2) {
        mysqli_close($con);
        http_response_code(403);
        echo json_encode(["auth" => false, "message" => "Solo postulantes pueden consultar este dashboard"]);
        exit;
    }

    $usuario = $usuarioRows[0];
    $postulanteRows = rows_query($con,
        "SELECT id_postulante, descripcion_discapacidad, esfuerzo_fisico_posible, experiencia, habilidades, (cv IS NOT NULL) AS tiene_cv, portafolio_url
         FROM postulantes
         WHERE id_usuario = ?
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    $postulante = $postulanteRows[0] ?? null;
    $id_postulante = $postulante ? (int)$postulante["id_postulante"] : 0;

    $discapacidades = [];
    $tipos = [];
    if ($id_postulante > 0) {
        $discRows = rows_query($con,
            "SELECT td.id_tipo_discapacidad, td.nombre_discapacidad
             FROM postulante_discapacidad pd
             INNER JOIN tipo_discapacidad td ON td.id_tipo_discapacidad = pd.id_tipo_discapacidad
             WHERE pd.id_postulante = ?
             ORDER BY td.nombre_discapacidad",
            "i",
            [$id_postulante]
        );
        foreach ($discRows as $d) {
            $tipos[] = (int)$d["id_tipo_discapacidad"];
            $discapacidades[] = $d["nombre_discapacidad"];
        }
    }

    $totalPostulaciones = $id_postulante > 0
        ? scalar_query($con, "SELECT COUNT(*) FROM postulaciones WHERE id_postulante = ?", "i", [$id_postulante])
        : 0;
    $enProceso = $id_postulante > 0
        ? scalar_query($con, "SELECT COUNT(*) FROM postulaciones WHERE id_postulante = ? AND LOWER(estado) IN ('pendiente','en proceso','entrevista')", "i", [$id_postulante])
        : 0;
    $aceptadas = $id_postulante > 0
        ? scalar_query($con, "SELECT COUNT(*) FROM postulaciones WHERE id_postulante = ? AND LOWER(estado) IN ('aceptado','aceptada','contratado','contratada')", "i", [$id_postulante])
        : 0;

    $vacantesCompatibles = [];
    if (!empty($tipos)) {
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));
        $types = str_repeat('i', count($tipos));

        $vacantesCompatibles = rows_query($con,
            "SELECT DISTINCT
                v.id_vacante,
                v.titulo_puesto,
                v.descripcion_puesto,
                v.requisitos,
                v.modalidad,
                v.salario_min,
                v.salario_max,
                v.estado,
                v.fecha_publicacion,
                v.fecha_cierre,
                COALESCE(e.nombre_empresas, 'Empresa') AS empresa
             FROM vacantes v
             INNER JOIN vacante_discapacidad vd ON vd.id_vacante = v.id_vacante
             LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
             LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
             WHERE v.estado = 'activa'
               AND vd.id_tipo_discapacidad IN ($placeholders)
               AND NOT EXISTS (
                    SELECT 1 FROM postulaciones p
                    WHERE p.id_vacante = v.id_vacante AND p.id_postulante = ?
               )
             ORDER BY v.fecha_publicacion DESC
             LIMIT 3",
            $types . 'i',
            [...$tipos, $id_postulante]
        );
    }

    $vacantesNuevas = 0;
    if (!empty($tipos)) {
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));
        $types = str_repeat('i', count($tipos));
        $vacantesNuevas = scalar_query($con,
            "SELECT COUNT(DISTINCT v.id_vacante)
             FROM vacantes v
             INNER JOIN vacante_discapacidad vd ON vd.id_vacante = v.id_vacante
             WHERE v.estado = 'activa'
               AND vd.id_tipo_discapacidad IN ($placeholders)
               AND v.fecha_publicacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $types,
            $tipos
        );
    }

    $postulacionesRecientes = [];
    if ($id_postulante > 0) {
        $postulacionesRecientes = rows_query($con,
            "SELECT
                p.id_postulacion AS id,
                LOWER(p.estado) AS estado,
                p.fecha_postulacion AS fecha,
                v.id_vacante,
                v.titulo_puesto AS vacante,
                v.modalidad,
                COALESCE(e.nombre_empresas, 'Empresa') AS empresa
             FROM postulaciones p
             INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
             LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
             LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
             WHERE p.id_postulante = ?
             ORDER BY p.fecha_postulacion DESC
             LIMIT 3",
            "i",
            [$id_postulante]
        );
    }

    $certificaciones = $id_postulante > 0
        ? scalar_query($con, "SELECT COUNT(*) FROM certificaciones WHERE id_postulante = ?", "i", [$id_postulante])
        : 0;

    $hasInicial = $postulante &&
        trim((string)($postulante["descripcion_discapacidad"] ?? '')) !== '' &&
        trim((string)($postulante["experiencia"] ?? '')) !== '' &&
        trim((string)($postulante["habilidades"] ?? '')) !== '' &&
        $postulante["esfuerzo_fisico_posible"] !== null;

    $checklist = [
        ["id" => 1, "label" => "Datos de cuenta", "done" => trim((string)$usuario["nombres"]) !== '' && trim((string)$usuario["correo"]) !== '', "path" => "/formulario", "peso" => 20],
        ["id" => 2, "label" => "Evaluacion inicial", "done" => (bool)$hasInicial, "path" => "/formulario", "peso" => 25],
        ["id" => 3, "label" => "Foto de perfil", "done" => !empty($usuario["foto_perfil"]), "path" => "/formulario", "peso" => 10],
        ["id" => 4, "label" => "Discapacidad registrada", "done" => count($discapacidades) > 0, "path" => "/formulario", "peso" => 15],
        ["id" => 5, "label" => "Al menos 1 certificacion", "done" => $certificaciones > 0, "path" => "/postulante/certificaciones", "peso" => 20],
        ["id" => 6, "label" => "CV o portafolio", "done" => $postulante && (!empty($postulante["tiene_cv"]) || !empty($postulante["portafolio_url"])), "path" => "/formulario", "peso" => 10],
    ];

    $completado = 0;
    foreach ($checklist as $item) {
        if ($item["done"]) {
            $completado += (int)$item["peso"];
        }
    }

    $vacantesFormateadas = [];
    foreach ($vacantesCompatibles as $v) {
        $vacantesFormateadas[] = [
            "id" => (int)$v["id_vacante"],
            "id_vacante" => (int)$v["id_vacante"],
            "titulo" => $v["titulo_puesto"],
            "titulo_puesto" => $v["titulo_puesto"],
            "empresa" => $v["empresa"],
            "salario_min" => $v["salario_min"] !== null ? (float)$v["salario_min"] : null,
            "salario_max" => $v["salario_max"] !== null ? (float)$v["salario_max"] : null,
            "modalidad" => $v["modalidad"],
            "estado" => $v["estado"],
            "fecha_publicacion" => $v["fecha_publicacion"],
            "discapacidades" => $discapacidades,
        ];
    }

    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "user" => [
            "id" => (int)$usuario["id_usuario"],
            "nombre" => trim($usuario["nombres"] . " " . $usuario["apellidos"]),
            "rol" => "Postulante",
            "avatar" => $usuario["foto_perfil"],
            "discapacidades" => $discapacidades,
        ],
        "perfil" => [
            "completado" => $completado,
            "checklist" => $checklist,
            "certificaciones" => $certificaciones,
            "id_postulante" => $id_postulante ?: null,
        ],
        "stats" => [
            "postulaciones" => $totalPostulaciones,
            "en_proceso" => $enProceso,
            "aceptadas" => $aceptadas,
            "vacantes_nuevas" => $vacantesNuevas,
        ],
        "postulaciones_recientes" => $postulacionesRecientes,
        "vacantes_recomendadas" => $vacantesFormateadas,
    ]);
} catch (Throwable $e) {
    error_log("[POSTULANTE_DASHBOARD] " . $e->getMessage());
    if (isset($con)) {
        mysqli_close($con);
    }
    http_response_code(500);
    echo json_encode(["auth" => true, "success" => false, "message" => "Error al cargar dashboard"]);
}
?>