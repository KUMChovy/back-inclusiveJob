<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario, true);
$accion = $_GET["accion"] ?? "";

function perfil_reclutador_empresa_actual($con, $id_usuario) {
    $u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";
    $u_puesto = reclutador_columna_existe($con, "usuarios", "puesto") ? "u.puesto" : "NULL";
    $u_sector = reclutador_columna_existe($con, "usuarios", "sector") ? "u.sector" : "NULL";
    $u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";

    $sql = "SELECT
                u.id_usuario,
                u.nombres,
                u.apellidos,
                u.correo,
                COALESCE(u.telefono, '') AS telefono,
                u.foto_perfil,
                COALESCE(e.nombre_empresas, $u_empresa, '') AS empresa,
                COALESCE(r.puesto, $u_puesto, 'Reclutador') AS puesto,
                COALESCE($u_sector, 'No especificado') AS sector,
                COALESCE(e.estado_validacion, $u_validada, 0) AS empresa_validada,
                r.id_reclutador,
                r.id_empresas
            FROM usuarios u
            LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
            LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
            WHERE u.id_usuario = ? AND u.id_rol = 3
            LIMIT 1";

    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_usuario);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row;
}

function perfil_reclutador_sync_usuario_legacy($con, $id_usuario, $empresa, $puesto, $sector, $empresa_validada) {
    $columnas = [
        "empresa" => $empresa,
        "puesto" => $puesto,
        "sector" => $sector,
    ];

    $sets = [];
    $params = [];

    foreach ($columnas as $columna => $valor) {
        if (reclutador_columna_existe($con, "usuarios", $columna)) {
            $sets[] = "$columna = ?";
            $params[] = $valor;
        }
    }

    if (reclutador_columna_existe($con, "usuarios", "empresa_validada")) {
        $sets[] = "empresa_validada = ?";
        $params[] = $empresa_validada;
    }

    if (!$sets) {
        return;
    }

    $params[] = $id_usuario;
    $types = str_repeat("s", count($params) - 1) . "i";
    $sql = "UPDATE usuarios SET " . implode(", ", $sets) . " WHERE id_usuario = ? AND id_rol = 3";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function perfil_reclutador_input() {
    $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
    if (stripos($contentType, "multipart/form-data") !== false) {
        return $_POST;
    }

    return reclutador_json_body();
}

function perfil_reclutador_normalizar_telefono($telefono_raw) {
    $digitos = preg_replace('/\D+/', '', (string)$telefono_raw);

    if ($digitos === '') {
        return "";
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
        reclutador_responder(false, "El telefono debe contener 10 digitos sin contar la LADA.", null, 422);
    }

    return $digitos;
}

function perfil_reclutador_guardar_foto($id_usuario) {
    if (empty($_FILES["fotoPerfil"]["name"])) {
        return null;
    }

    $archivo = $_FILES["fotoPerfil"];
    $extensiones = ["jpg", "jpeg", "png", "webp"];
    $ext = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $extensiones, true)) {
        reclutador_responder(false, "Formato de imagen no valido. Usa JPG, PNG o WEBP.", null, 422);
    }

    if ((int)$archivo["size"] > 5 * 1024 * 1024) {
        reclutador_responder(false, "La imagen no debe superar 5 MB.", null, 422);
    }

    if ((int)$archivo["error"] !== UPLOAD_ERR_OK) {
        reclutador_responder(false, "Error al subir la imagen (codigo " . $archivo["error"] . ").", null, 422);
    }

    $carpeta = __DIR__ . "/../../uploads/fotos_perfil/";
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }

    $nombreArchivo = "perfil_" . $id_usuario . "_" . time() . "." . $ext;
    if (!move_uploaded_file($archivo["tmp_name"], $carpeta . $nombreArchivo)) {
        reclutador_responder(false, "No se pudo guardar la imagen de perfil.", null, 500);
    }

    return "uploads/fotos_perfil/" . $nombreArchivo;
}

if ($accion === "obtener") {
    $row = perfil_reclutador_empresa_actual($con, $id_usuario);

    if (!$row) {
        reclutador_responder(false, "No se encontro el perfil del reclutador.", null, 404);
    }

    $row["id_usuario"] = (int)$row["id_usuario"];
    $row["empresa_validada"] = (int)$row["empresa_validada"];
    unset($row["id_reclutador"], $row["id_empresas"]);

    reclutador_responder(true, "Perfil obtenido", $row);
}

if ($accion === "actualizar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $input = perfil_reclutador_input();

    $nombres = trim($input["nombres"] ?? "");
    $apellidos = trim($input["apellidos"] ?? "");
    $telefono = perfil_reclutador_normalizar_telefono($input["telefono"] ?? "");
    $empresa = trim($input["empresa"] ?? "");
    $puesto = trim($input["puesto"] ?? "Reclutador");
    $sector = trim($input["sector"] ?? "No especificado");

    if ($nombres === "" || $apellidos === "") {
        reclutador_responder(false, "Faltan campos obligatorios: nombre y apellidos.", null, 400);
    }

    if ($empresa === "") {
        reclutador_responder(false, "La empresa es obligatoria.", null, 400);
    }

    $actual = perfil_reclutador_empresa_actual($con, $id_usuario);
    if (!$actual) {
        reclutador_responder(false, "Usuario no encontrado en la base de datos.", null, 404);
    }

    $empresa_actual = trim($actual["empresa"] ?? "");
    $validacion_actual = (int)($actual["empresa_validada"] ?? 0);
    $id_empresas = (int)($actual["id_empresas"] ?? 0);
    $nueva_validacion = ($empresa !== $empresa_actual) ? 0 : $validacion_actual;
    $foto_perfil_ruta = perfil_reclutador_guardar_foto($id_usuario);

    if ($foto_perfil_ruta !== null) {
        $stmt_user = mysqli_prepare($con, "UPDATE usuarios SET nombres = ?, apellidos = ?, telefono = ?, foto_perfil = ? WHERE id_usuario = ? AND id_rol = 3");
        mysqli_stmt_bind_param($stmt_user, "ssssi", $nombres, $apellidos, $telefono, $foto_perfil_ruta, $id_usuario);
    } else {
        $stmt_user = mysqli_prepare($con, "UPDATE usuarios SET nombres = ?, apellidos = ?, telefono = ? WHERE id_usuario = ? AND id_rol = 3");
        mysqli_stmt_bind_param($stmt_user, "sssi", $nombres, $apellidos, $telefono, $id_usuario);
    }
    if (!mysqli_stmt_execute($stmt_user)) {
        reclutador_responder(false, "Error al actualizar los datos personales.", null, 500);
    }
    mysqli_stmt_close($stmt_user);

    if (!$id_empresas) {
        $stmt_empresa = mysqli_prepare($con, "INSERT INTO empresas (nombre_empresas, descripcion, direccion, telefono_empresa, correo_empresa, sitio_web, estado_validacion) VALUES (?, '', '', '', '', '', ?)");
        mysqli_stmt_bind_param($stmt_empresa, "si", $empresa, $nueva_validacion);

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al crear la empresa.", null, 500);
        }

        $id_empresas = (int)mysqli_insert_id($con);
        mysqli_stmt_close($stmt_empresa);
    } else {
        $stmt_empresa = mysqli_prepare($con, "UPDATE empresas SET nombre_empresas = ?, estado_validacion = ? WHERE id_empresas = ?");
        mysqli_stmt_bind_param($stmt_empresa, "sii", $empresa, $nueva_validacion, $id_empresas);

        if (!mysqli_stmt_execute($stmt_empresa)) {
            reclutador_responder(false, "Error al actualizar la empresa.", null, 500);
        }
        mysqli_stmt_close($stmt_empresa);
    }

    $stmt_reclutador = mysqli_prepare($con, "UPDATE reclutadores SET puesto = ?, id_empresas = ? WHERE id_reclutador = ? AND id_usuario = ?");
    mysqli_stmt_bind_param($stmt_reclutador, "siii", $puesto, $id_empresas, $id_reclutador, $id_usuario);
    if (!mysqli_stmt_execute($stmt_reclutador)) {
        reclutador_responder(false, "Error al actualizar el perfil de reclutador.", null, 500);
    }
    mysqli_stmt_close($stmt_reclutador);

    perfil_reclutador_sync_usuario_legacy($con, $id_usuario, $empresa, $puesto, $sector, $nueva_validacion);

    $_SESSION["user"]["nombre"] = trim($nombres . " " . $apellidos);
    $_SESSION["user"]["nombres"] = $nombres;
    $_SESSION["user"]["apellido"] = $apellidos;
    if ($foto_perfil_ruta !== null) {
        $_SESSION["user"]["foto_perfil"] = $foto_perfil_ruta;
        $_SESSION["user"]["avatar"] = $foto_perfil_ruta;
    }

    reclutador_responder(true, "Perfil actualizado", [
        "empresa_validada" => $nueva_validacion,
        "cambio_empresa" => $empresa !== $empresa_actual,
        "foto_perfil" => $foto_perfil_ruta
    ]);
}

reclutador_responder(false, "Accion no permitida.", null, 400);
?>
