<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "";

function empresa_normalizar_telefono($telefono_raw) {
    $telefono = trim((string)$telefono_raw);
    $digitos = preg_replace('/\D+/', '', $telefono);

    if ($telefono === '') {
        reclutador_responder(false, "El numero de telefono es obligatorio.", null, 400);
    }

    if (!preg_match('/^[0-9+\s()\-]+$/', $telefono)) {
        reclutador_responder(false, "El numero de telefono solo puede contener numeros, espacios, guiones o parentesis.", null, 400);
    }

    $ladas = [
        "502", "503", "504", "505", "506", "507",
        "591", "593", "595", "598",
        "52", "34", "54", "56", "57", "51", "58", "55",
        "1"
    ];

    $nacional = $digitos;
    if (strlen($digitos) > 10) {
        foreach ($ladas as $lada) {
            if (strpos($digitos, $lada) === 0 && strlen($digitos) > strlen($lada)) {
                $nacional = substr($digitos, strlen($lada));
                break;
            }
        }
    }

    if (!preg_match('/^[0-9]{10}$/', $nacional)) {
        reclutador_responder(false, "El numero de telefono debe contener 10 digitos sin contar la LADA.", null, 400);
    }

    return $digitos;
}

function empresa_normalizar_rfc($rfc_raw) {
    $rfc = strtoupper(preg_replace('/[\s\-]+/', '', trim((string)$rfc_raw)));

    if ($rfc === '') {
        reclutador_responder(false, "El RFC de la empresa es obligatorio.", null, 400);
    }

    if (!preg_match('/^([A-ZÑ&]{3,4})([0-9]{2})([0-9]{2})([0-9]{2})([A-Z0-9]{3})$/u', $rfc, $matches)) {
        reclutador_responder(false, "Ingresa un RFC mexicano valido.", null, 400);
    }

    $year = (int)$matches[2];
    $month = (int)$matches[3];
    $day = (int)$matches[4];
    $fullYear = $year <= 30 ? 2000 + $year : 1900 + $year;

    if (!checkdate($month, $day, $fullYear)) {
        reclutador_responder(false, "La fecha del RFC no es valida.", null, 400);
    }

    return $rfc;
}

function empresa_normalizar_digitos_sql($columna) {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($columna, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
}

function empresa_validar_dato_unico($con, $id_empresas_actual, $condicion, $params, $campo) {
    $sql = "SELECT id_empresas, nombre_empresas
            FROM empresas
            WHERE id_empresas <> ?
              AND ($condicion)
            LIMIT 1";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        reclutador_responder(false, "No se pudo validar si la empresa ya existe.", null, 500);
    }

    $bind_params = array_merge([(int)$id_empresas_actual], $params);
    $types = "i" . str_repeat("s", count($params));
    mysqli_stmt_bind_param($stmt, $types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        $nombre = trim($row["nombre_empresas"] ?? "");
        $detalle = $nombre !== "" ? " ({$nombre})" : "";
        reclutador_responder(false, "Ya existe una empresa registrada con el mismo {$campo}{$detalle}.", null, 409);
    }
}

function empresa_validar_sin_duplicados($con, $id_empresas_actual, $nombre_empresa, $rfc_empresa, $correo_empresa, $telefono_empresa, $empresas_tiene_rfc) {
    empresa_validar_dato_unico(
        $con,
        $id_empresas_actual,
        "LOWER(TRIM(nombre_empresas)) = LOWER(TRIM(?))",
        [$nombre_empresa],
        "nombre"
    );

    if ($empresas_tiene_rfc) {
        empresa_validar_dato_unico(
            $con,
            $id_empresas_actual,
            "UPPER(REPLACE(REPLACE(rfc, '-', ''), ' ', '')) = ?",
            [$rfc_empresa],
            "RFC"
        );
    }

    empresa_validar_dato_unico(
        $con,
        $id_empresas_actual,
        "LOWER(TRIM(correo_empresa)) = LOWER(TRIM(?))",
        [$correo_empresa],
        "correo"
    );

    $telefono_bd = empresa_normalizar_digitos_sql("telefono_empresa");
    $telefono_nacional = substr($telefono_empresa, -10);
    empresa_validar_dato_unico(
        $con,
        $id_empresas_actual,
        "{$telefono_bd} = ? OR RIGHT({$telefono_bd}, 10) = ?",
        [$telefono_empresa, $telefono_nacional],
        "telefono"
    );
}

if ($accion === "obtener") {
    $u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";
    $u_rfc = reclutador_columna_existe($con, "usuarios", "rfc_empresa") ? "u.rfc_empresa" : "NULL";
    $e_rfc = reclutador_columna_existe($con, "empresas", "rfc") ? "e.rfc" : "NULL";
    $u_correo = reclutador_columna_existe($con, "usuarios", "correo_empresa") ? "u.correo_empresa" : "NULL";
    $u_sitio = reclutador_columna_existe($con, "usuarios", "sitio_web") ? "u.sitio_web" : "NULL";
    $u_descripcion = reclutador_columna_existe($con, "usuarios", "descripcion_empresa") ? "u.descripcion_empresa" : "NULL";
    $u_direccion = reclutador_columna_existe($con, "usuarios", "direccion_empresa") ? "u.direccion_empresa" : "NULL";
    $u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";

    $sql = "SELECT
                COALESCE(e.nombre_empresas, $u_empresa, '') AS nombre_empresa,
                COALESCE($e_rfc, $u_rfc, '') AS rfc,
                COALESCE($e_rfc, $u_rfc, '') AS rfc_empresa,
                COALESCE(e.correo_empresa, $u_correo, '') AS correo_empresa,
                COALESCE(e.sitio_web, $u_sitio, '') AS sitio_web,
                COALESCE(e.telefono_empresa, '') AS telefono_empresa,
                COALESCE(e.descripcion, $u_descripcion, '') AS descripcion_empresa,
                COALESCE(e.direccion, $u_direccion, '') AS direccion_empresa,
                COALESCE(e.estado_validacion, $u_validada, 0) AS empresa_validada,
                COALESCE(e.estado_validacion, $u_validada, 0) AS estado_validacion,
                e.id_empresas
            FROM usuarios u
            LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
            LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
            WHERE u.id_usuario = ? AND u.id_rol = 3
            LIMIT 1";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_usuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);

    if (!$row) {
        reclutador_responder(false, "No se encontraron datos corporativos.", null, 404);
    }

    $row["empresa_validada"] = (int)$row["empresa_validada"];
    $row["estado_validacion"] = (int)$row["estado_validacion"];
    $row["empresa_rechazada"] = $row["estado_validacion"] === 2;
    $row["requiere_reenvio"] = $row["estado_validacion"] === 2;
    reclutador_responder(true, "Datos de la empresa obtenidos", $row);
}

if ($accion === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $input = reclutador_json_body();
    $nombre_empresa = trim($input["nombre_empresa"] ?? "");
    $rfc_empresa = empresa_normalizar_rfc($input["rfc"] ?? $input["rfc_empresa"] ?? "");
    $correo_empresa = trim($input["correo_empresa"] ?? "");
    $sitio_web = trim($input["sitio_web"] ?? "");
    $telefono_empresa = empresa_normalizar_telefono($input["telefono_empresa"] ?? "");
    $descripcion_empresa = trim($input["descripcion_empresa"] ?? "");
    $direccion_empresa = trim($input["direccion_empresa"] ?? "");

    if ($nombre_empresa === "") {
        reclutador_responder(false, "El nombre de la empresa es obligatorio.", null, 400);
    }

    if ($correo_empresa === "") {
        reclutador_responder(false, "El correo de la empresa es obligatorio.", null, 400);
    }

    if (strlen($correo_empresa) > 150 || !filter_var($correo_empresa, FILTER_VALIDATE_EMAIL)) {
        reclutador_responder(false, "Ingresa un correo de empresa valido.", null, 400);
    }

    $stmt_check = mysqli_prepare($con, "SELECT
            r.id_empresas,
            e.nombre_empresas,
            e.estado_validacion
        FROM usuarios u
        LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
        LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
        WHERE u.id_usuario = ?
        LIMIT 1");
    mysqli_stmt_bind_param($stmt_check, "i", $id_usuario);
    mysqli_stmt_execute($stmt_check);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));

    if (!$current) {
        reclutador_responder(false, "Usuario no encontrado.", null, 404);
    }

    $empresa_actual = $current["nombre_empresas"] ?? "";
    $validacion_actual = (int)($current["estado_validacion"] ?? 0);
    $empresa_rechazada = $validacion_actual === 2;
    $nueva_validacion = ($empresa_rechazada || $nombre_empresa !== $empresa_actual) ? 0 : $validacion_actual;
    $id_empresas = (int)($current["id_empresas"] ?? 0);
    $empresas_tiene_rfc = reclutador_columna_existe($con, "empresas", "rfc");

    empresa_validar_sin_duplicados($con, $id_empresas, $nombre_empresa, $rfc_empresa, $correo_empresa, $telefono_empresa, $empresas_tiene_rfc);

    if (!$id_empresas) {
        if ($empresas_tiene_rfc) {
            $stmt_empresa = mysqli_prepare($con, "INSERT INTO empresas (nombre_empresas, descripcion, direccion, telefono_empresa, correo_empresa, sitio_web, rfc, estado_validacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_empresa, "sssssssi", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $correo_empresa, $sitio_web, $rfc_empresa, $nueva_validacion);
        } else {
            $stmt_empresa = mysqli_prepare($con, "INSERT INTO empresas (nombre_empresas, descripcion, direccion, telefono_empresa, correo_empresa, sitio_web, estado_validacion) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_empresa, "ssssssi", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $correo_empresa, $sitio_web, $nueva_validacion);
        }

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al crear la empresa.", null, 500);
        }

        $id_empresas = (int)mysqli_insert_id($con);
        $stmt_rel = mysqli_prepare($con, "UPDATE reclutadores SET id_empresas = ? WHERE id_reclutador = ?");
        mysqli_stmt_bind_param($stmt_rel, "ii", $id_empresas, $id_reclutador);
        mysqli_stmt_execute($stmt_rel);
    } else {
        if ($empresas_tiene_rfc) {
            $stmt_empresa = mysqli_prepare($con, "UPDATE empresas SET nombre_empresas = ?, descripcion = ?, direccion = ?, telefono_empresa = ?, correo_empresa = ?, sitio_web = ?, rfc = ?, estado_validacion = ? WHERE id_empresas = ?");
            mysqli_stmt_bind_param($stmt_empresa, "sssssssii", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $correo_empresa, $sitio_web, $rfc_empresa, $nueva_validacion, $id_empresas);
        } else {
            $stmt_empresa = mysqli_prepare($con, "UPDATE empresas SET nombre_empresas = ?, descripcion = ?, direccion = ?, telefono_empresa = ?, correo_empresa = ?, sitio_web = ?, estado_validacion = ? WHERE id_empresas = ?");
            mysqli_stmt_bind_param($stmt_empresa, "ssssssii", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $correo_empresa, $sitio_web, $nueva_validacion, $id_empresas);
        }

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al guardar la empresa.", null, 500);
        }
    }

    $columnas_usuario = [
        "empresa" => $nombre_empresa,
        "rfc_empresa" => $rfc_empresa,
        "correo_empresa" => $correo_empresa,
        "sitio_web" => $sitio_web,
        "descripcion_empresa" => $descripcion_empresa,
        "direccion_empresa" => $direccion_empresa,
    ];

    $sets = [];
    $params = [];
    foreach ($columnas_usuario as $columna => $valor) {
        if (reclutador_columna_existe($con, "usuarios", $columna)) {
            $sets[] = "$columna = ?";
            $params[] = $valor;
        }
    }

    if (reclutador_columna_existe($con, "usuarios", "empresa_validada")) {
        $sets[] = "empresa_validada = ?";
        $params[] = $nueva_validacion;
    }

    if ($sets) {
        $params[] = $id_usuario;
        $types = str_repeat("s", count($params) - 1) . "i";
        $sql = "UPDATE usuarios SET " . implode(", ", $sets) . " WHERE id_usuario = ? AND id_rol = 3";
        $stmt = mysqli_prepare($con, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);

        if (!mysqli_stmt_execute($stmt)) {
            reclutador_responder(false, "Error al sincronizar los datos del usuario.", null, 500);
        }
    }

    reclutador_responder(true, "Datos corporativos actualizados", [
        "empresa_validada" => $nueva_validacion,
        "estado_validacion" => $nueva_validacion,
        "empresa_rechazada" => false,
        "requiere_reenvio" => false,
        "cambio_nombre" => $nombre_empresa !== $empresa_actual,
        "reenvio_revision" => $empresa_rechazada
    ]);
}

reclutador_responder(false, "Accion no permitida.", null, 400);
?>
