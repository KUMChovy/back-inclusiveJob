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

if ($accion === "obtener") {
    $u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";
    $u_rfc = reclutador_columna_existe($con, "usuarios", "rfc_empresa") ? "u.rfc_empresa" : "NULL";
    $u_sitio = reclutador_columna_existe($con, "usuarios", "sitio_web") ? "u.sitio_web" : "NULL";
    $u_descripcion = reclutador_columna_existe($con, "usuarios", "descripcion_empresa") ? "u.descripcion_empresa" : "NULL";
    $u_direccion = reclutador_columna_existe($con, "usuarios", "direccion_empresa") ? "u.direccion_empresa" : "NULL";
    $u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";

    $sql = "SELECT
                COALESCE(e.nombre_empresas, $u_empresa, '') AS nombre_empresa,
                COALESCE($u_rfc, '') AS rfc_empresa,
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
    $rfc_empresa = trim($input["rfc_empresa"] ?? "");
    $sitio_web = trim($input["sitio_web"] ?? "");
    $telefono_empresa = empresa_normalizar_telefono($input["telefono_empresa"] ?? "");
    $descripcion_empresa = trim($input["descripcion_empresa"] ?? "");
    $direccion_empresa = trim($input["direccion_empresa"] ?? "");

    if ($nombre_empresa === "") {
        reclutador_responder(false, "El nombre de la empresa es obligatorio.", null, 400);
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

    if (!$id_empresas) {
        $stmt_empresa = mysqli_prepare($con, "INSERT INTO empresas (nombre_empresas, descripcion, direccion, telefono_empresa, correo_empresa, sitio_web, estado_validacion) VALUES (?, ?, ?, ?, '', ?, ?)");
        mysqli_stmt_bind_param($stmt_empresa, "sssssi", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $sitio_web, $nueva_validacion);

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al crear la empresa.", null, 500);
        }

        $id_empresas = (int)mysqli_insert_id($con);
        $stmt_rel = mysqli_prepare($con, "UPDATE reclutadores SET id_empresas = ? WHERE id_reclutador = ?");
        mysqli_stmt_bind_param($stmt_rel, "ii", $id_empresas, $id_reclutador);
        mysqli_stmt_execute($stmt_rel);
    } else {
        $stmt_empresa = mysqli_prepare($con, "UPDATE empresas SET nombre_empresas = ?, descripcion = ?, direccion = ?, telefono_empresa = ?, sitio_web = ?, estado_validacion = ? WHERE id_empresas = ?");
        mysqli_stmt_bind_param($stmt_empresa, "sssssii", $nombre_empresa, $descripcion_empresa, $direccion_empresa, $telefono_empresa, $sitio_web, $nueva_validacion, $id_empresas);

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al guardar la empresa.", null, 500);
        }
    }

    $columnas_usuario = [
        "empresa" => $nombre_empresa,
        "rfc_empresa" => $rfc_empresa,
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
