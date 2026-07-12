<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers("GET, POST, OPTIONS, PUT, DELETE");

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "";
$data = reclutador_json_body();

function vacante_discapacidad_columnas($con) {
    $vacante_col = reclutador_columna_existe($con, 'vacante_discapacidad', 'id_vacante') ? 'id_vacante' : 'vacante_id';
    $tipo_col = reclutador_columna_existe($con, 'vacante_discapacidad', 'id_tipo_discapacidad') ? 'id_tipo_discapacidad' : 'tipo_discapacidad_id';

    if (!reclutador_columna_existe($con, 'vacante_discapacidad', $vacante_col) || !reclutador_columna_existe($con, 'vacante_discapacidad', $tipo_col)) {
        reclutador_responder(false, 'La tabla vacante_discapacidad no tiene las columnas esperadas.', null, 500);
    }

    return [$vacante_col, $tipo_col];
}

function vacante_discapacidad_ids($raw) {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : explode(',', $raw);
    }

    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function vacante_validar_discapacidades($con, $ids) {
    if (!$ids) {
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', $ids);
    $res = mysqli_query($con, "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE id_tipo_discapacidad IN ($in)");

    if (!$res) {
        reclutador_responder(false, 'No se pudieron validar las discapacidades.', null, 500);
    }

    $validos = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $validos[] = (int)$row['id_tipo_discapacidad'];
    }

    sort($ids);
    sort($validos);

    if ($ids !== $validos) {
        reclutador_responder(false, 'Una o mas discapacidades seleccionadas no existen.', null, 400);
    }

    return $validos;
}

function vacante_sync_discapacidades($con, $id_vacante, $ids) {
    [$vacante_col, $tipo_col] = vacante_discapacidad_columnas($con);

    $stmt_del = mysqli_prepare($con, "DELETE FROM vacante_discapacidad WHERE `$vacante_col` = ?");
    mysqli_stmt_bind_param($stmt_del, 'i', $id_vacante);
    if (!mysqli_stmt_execute($stmt_del)) {
        return false;
    }
    mysqli_stmt_close($stmt_del);

    if (!$ids) {
        return true;
    }

    $stmt_ins = mysqli_prepare($con, "INSERT IGNORE INTO vacante_discapacidad (`$vacante_col`, `$tipo_col`) VALUES (?, ?)");
    foreach ($ids as $id_tipo) {
        $id_tipo = (int)$id_tipo;
        mysqli_stmt_bind_param($stmt_ins, 'ii', $id_vacante, $id_tipo);
        if (!mysqli_stmt_execute($stmt_ins)) {
            mysqli_stmt_close($stmt_ins);
            return false;
        }
    }
    mysqli_stmt_close($stmt_ins);

    return true;
}

function vacante_agregar_discapacidades($con, &$vacantes) {
    if (!$vacantes) {
        return;
    }

    [$vacante_col, $tipo_col] = vacante_discapacidad_columnas($con);
    $ids = array_map(fn($v) => (int)$v['id_vacante'], $vacantes);
    $in = implode(',', $ids);
    $sql = "SELECT
                vd.`$vacante_col` AS id_vacante,
                td.id_tipo_discapacidad,
                td.nombre_discapacidad,
                COALESCE(td.descripcion, '') AS descripcion
            FROM vacante_discapacidad vd
            INNER JOIN tipo_discapacidad td ON td.id_tipo_discapacidad = vd.`$tipo_col`
            WHERE vd.`$vacante_col` IN ($in)
            ORDER BY td.nombre_discapacidad ASC";
    $res = mysqli_query($con, $sql);

    $por_vacante = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $id_vacante = (int)$row['id_vacante'];
            $por_vacante[$id_vacante][] = [
                'id_tipo_discapacidad' => (int)$row['id_tipo_discapacidad'],
                'nombre_discapacidad' => $row['nombre_discapacidad'],
                'descripcion' => $row['descripcion'],
            ];
        }
    }

    foreach ($vacantes as &$vacante) {
        $id = (int)$vacante['id_vacante'];
        $vacante['discapacidades'] = $por_vacante[$id] ?? [];
        $vacante['discapacidad_ids'] = array_map(fn($d) => (int)$d['id_tipo_discapacidad'], $vacante['discapacidades']);
    }
    unset($vacante);
}

function vacante_pertenece_reclutador($con, $id_vacante, $id_reclutador) {
    $stmt = mysqli_prepare($con, 'SELECT id_vacante FROM vacantes WHERE id_vacante = ? AND id_reclutador = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $id_vacante, $id_reclutador);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (bool)$row;
}

function vacante_empresa_validada($con, $id_reclutador) {
    $u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";
    $sql = "SELECT COALESCE(e.estado_validacion, $u_validada, 0) AS empresa_validada
            FROM reclutadores r
            LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
            LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
            WHERE r.id_reclutador = ?
            LIMIT 1";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_reclutador);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int)($row['empresa_validada'] ?? 0);
}

function vacante_exigir_empresa_aprobada($con, $id_reclutador) {
    if (vacante_empresa_validada($con, $id_reclutador) !== 1) {
        reclutador_responder(false, 'No puedes publicar vacantes hasta que tu empresa sea aprobada por el administrador.', null, 403);
    }
}

function vacante_validar_salarios($salario_min_raw, $salario_max_raw) {
    if ($salario_min_raw === '' || $salario_max_raw === '' || $salario_min_raw === null || $salario_max_raw === null) {
        reclutador_responder(false, 'El salario debe estar entre $1,000 y $1,000,000 pesos.', null, 400);
    }

    if (!is_numeric($salario_min_raw) || !is_numeric($salario_max_raw)) {
        reclutador_responder(false, 'El salario debe estar entre $1,000 y $1,000,000 pesos.', null, 400);
    }

    $salario_min = (float)$salario_min_raw;
    $salario_max = (float)$salario_max_raw;

    if ($salario_min < 1000 || $salario_min > 1000000 || $salario_max < 1000 || $salario_max > 1000000) {
        reclutador_responder(false, 'El salario debe estar entre $1,000 y $1,000,000 pesos.', null, 400);
    }

    if ($salario_min > $salario_max) {
        reclutador_responder(false, 'El salario maximo debe ser mayor o igual al salario minimo.', null, 400);
    }

    return [$salario_min, $salario_max];
}

function vacante_fecha_valida($fecha) {
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt && $dt->format('Y-m-d') === $fecha;
}

function vacante_validar_fechas($fecha_publicacion_raw, $fecha_cierre_raw) {
    $fecha_publicacion = trim((string)$fecha_publicacion_raw);
    $fecha_cierre = trim((string)$fecha_cierre_raw);

    if ($fecha_publicacion === '' || $fecha_cierre === '') {
        reclutador_responder(false, 'La fecha de publicacion y la fecha de cierre son obligatorias.', null, 400);
    }

    if (!vacante_fecha_valida($fecha_publicacion) || !vacante_fecha_valida($fecha_cierre)) {
        reclutador_responder(false, 'Las fechas de la vacante no tienen un formato valido.', null, 400);
    }

    if ($fecha_publicacion > $fecha_cierre) {
        reclutador_responder(false, 'La fecha de cierre debe ser posterior a la fecha de publicacion.', null, 400);
    }

    return [$fecha_publicacion . ' 00:00:00', $fecha_cierre];
}

if ($accion === "listar") {
    $sql = "SELECT id_vacante, titulo_puesto, descripcion_puesto, requisitos, modalidad,
                   salario_min, salario_max, estado, fecha_publicacion, fecha_cierre, id_reclutador
            FROM vacantes
            WHERE id_reclutador = ?
            ORDER BY id_vacante DESC";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $vacantes = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['id_vacante'] = (int)$row['id_vacante'];
        $vacantes[] = $row;
    }
    mysqli_stmt_close($stmt);

    vacante_agregar_discapacidades($con, $vacantes);
    reclutador_responder(true, "Vacantes obtenidas", $vacantes);
}

if (($accion === "insertar" || $accion === "guardar") && $_SERVER["REQUEST_METHOD"] === "POST") {
    vacante_exigir_empresa_aprobada($con, $id_reclutador);

    $titulo = trim($data["titulo_puesto"] ?? "");
    $descripcion = trim($data["descripcion_puesto"] ?? "");
    $requisitos = trim($data["requisitos"] ?? "");
    $modalidad = trim($data["modalidad"] ?? "Presencial");
    $salarios = vacante_validar_salarios($data["salario_min"] ?? null, $data["salario_max"] ?? null);
    $salario_min = $salarios[0];
    $salario_max = $salarios[1];
    $estado = trim($data["estado"] ?? "activa");
    $fechas = vacante_validar_fechas($data["fecha_publicacion"] ?? "", $data["fecha_cierre"] ?? "");
    $fecha_publicacion = $fechas[0];
    $fecha_cierre = $fechas[1];
    $discapacidades = vacante_validar_discapacidades($con, vacante_discapacidad_ids($data['discapacidades'] ?? []));

    if ($titulo === "") {
        reclutador_responder(false, "El titulo de la vacante es obligatorio.", null, 400);
    }

    mysqli_begin_transaction($con);

    $sql = "INSERT INTO vacantes
                (titulo_puesto, descripcion_puesto, requisitos, modalidad, salario_min, salario_max, estado, fecha_publicacion, fecha_cierre, id_reclutador)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ssssddsssi", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $fecha_publicacion, $fecha_cierre, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);
        reclutador_responder(false, "Error al crear la vacante: " . mysqli_error($con), null, 500);
    }

    $id_vacante = (int)mysqli_insert_id($con);
    mysqli_stmt_close($stmt);

    if (!vacante_sync_discapacidades($con, $id_vacante, $discapacidades)) {
        mysqli_rollback($con);
        reclutador_responder(false, "Error al guardar las discapacidades de la vacante.", null, 500);
    }

    mysqli_commit($con);
    reclutador_responder(true, "Vacante creada correctamente", ["id_vacante" => $id_vacante]);
}

if ($accion === "editar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $id_vacante = (int)($data["id_vacante"] ?? 0);
    $titulo = trim($data["titulo_puesto"] ?? "");
    $descripcion = trim($data["descripcion_puesto"] ?? "");
    $requisitos = trim($data["requisitos"] ?? "");
    $modalidad = trim($data["modalidad"] ?? "Presencial");
    $salarios = vacante_validar_salarios($data["salario_min"] ?? null, $data["salario_max"] ?? null);
    $salario_min = $salarios[0];
    $salario_max = $salarios[1];
    $estado = trim($data["estado"] ?? "activa");
    $fechas = vacante_validar_fechas($data["fecha_publicacion"] ?? "", $data["fecha_cierre"] ?? "");
    $fecha_publicacion = $fechas[0];
    $fecha_cierre = $fechas[1];
    $discapacidades = vacante_validar_discapacidades($con, vacante_discapacidad_ids($data['discapacidades'] ?? []));

    if (!$id_vacante || $titulo === "") {
        reclutador_responder(false, "Faltan datos para actualizar la vacante.", null, 400);
    }

    if (!vacante_pertenece_reclutador($con, $id_vacante, $id_reclutador)) {
        reclutador_responder(false, "No se encontro la vacante.", null, 404);
    }

    mysqli_begin_transaction($con);

    $sql = "UPDATE vacantes
            SET titulo_puesto = ?, descripcion_puesto = ?, requisitos = ?, modalidad = ?,
                salario_min = ?, salario_max = ?, estado = ?, fecha_publicacion = ?, fecha_cierre = ?
            WHERE id_vacante = ? AND id_reclutador = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ssssddsssii", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $fecha_publicacion, $fecha_cierre, $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);
        reclutador_responder(false, "Error al actualizar la vacante: " . mysqli_error($con), null, 500);
    }
    mysqli_stmt_close($stmt);

    if (!vacante_sync_discapacidades($con, $id_vacante, $discapacidades)) {
        mysqli_rollback($con);
        reclutador_responder(false, "Error al actualizar las discapacidades de la vacante.", null, 500);
    }

    mysqli_commit($con);
    reclutador_responder(true, "Vacante actualizada con exito");
}

if ($accion === "actualizar_estado" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $id_vacante = (int)($data["id_vacante"] ?? 0);
    $estado = trim($data["estado"] ?? "activa");

    if (!$id_vacante) {
        reclutador_responder(false, "ID de vacante requerido.", null, 400);
    }

    $stmt = mysqli_prepare($con, "UPDATE vacantes SET estado = ? WHERE id_vacante = ? AND id_reclutador = ?");
    mysqli_stmt_bind_param($stmt, "sii", $estado, $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "No se pudo actualizar el estado.", null, 500);
    }

    reclutador_responder(true, "Estado actualizado");
}

if ($accion === "eliminar") {
    $id_vacante = (int)($_GET["id_vacante"] ?? ($data["id_vacante"] ?? 0));

    if (!$id_vacante) {
        reclutador_responder(false, "ID de vacante requerido.", null, 400);
    }

    if (!vacante_pertenece_reclutador($con, $id_vacante, $id_reclutador)) {
        reclutador_responder(false, "No se encontro la vacante.", null, 404);
    }

    [$vacante_col] = vacante_discapacidad_columnas($con);
    mysqli_begin_transaction($con);

    $stmt_rel = mysqli_prepare($con, "DELETE FROM vacante_discapacidad WHERE `$vacante_col` = ?");
    mysqli_stmt_bind_param($stmt_rel, "i", $id_vacante);
    if (!mysqli_stmt_execute($stmt_rel)) {
        mysqli_rollback($con);
        reclutador_responder(false, "No se pudieron eliminar las discapacidades de la vacante.", null, 500);
    }
    mysqli_stmt_close($stmt_rel);

    $stmt = mysqli_prepare($con, "DELETE FROM vacantes WHERE id_vacante = ? AND id_reclutador = ?");
    mysqli_stmt_bind_param($stmt, "ii", $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);
        reclutador_responder(false, "No se pudo eliminar la vacante.", null, 500);
    }

    mysqli_commit($con);
    reclutador_responder(true, "Vacante eliminada");
}

reclutador_responder(false, "Accion no valida.", null, 400);
?>
