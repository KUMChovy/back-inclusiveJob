<?php

function registrarPostulante($con, $nombres, $correo, $password, $telefono) {

    // ðŸ” Verificar si ya existe el correo en usuarios
    $check = $con->prepare("SELECT id_usuario FROM usuarios WHERE correo = ?");
    $check->bind_param("s", $correo);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return [
            "success" => false,
            "message" => "Datos ya existentes"
        ];
    }
    $check->close();

    // ðŸ” Hash de contraseÃ±a (se guarda ya hasheada dentro del token)
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // ðŸ” Borrar cualquier registro pendiente anterior con este correo
    //     (por si el usuario intentÃ³ registrarse antes y no verificÃ³)
    $stmtDel = $con->prepare("DELETE FROM tokens WHERE tipo = 'registro_pendiente' AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.correo')) = ?");
    $stmtDel->bind_param("s", $correo);
    $stmtDel->execute();
    $stmtDel->close();

    // ðŸ” Generar cÃ³digo de verificaciÃ³n de 6 dÃ­gitos
    $codigo = sprintf('%06d', random_int(0, 999999));

    // ðŸ” Armar el payload con todos los datos del registro + el cÃ³digo
    $payload = json_encode([
        "codigo"   => $codigo,
        "nombres"  => $nombres,
        "correo"   => $correo,
        "password" => $hash,
        "telefono" => $telefono,
        "id_rol"   => 2,
        "tipo_usuario" => "postulante",
    ]);

    $tiempo_expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $tipo = 'registro_pendiente';

    // ðŸ” Insertar en tokens con id_usuario = NULL (aÃºn no existe el usuario)
    $stmt = $con->prepare("
        INSERT INTO tokens (token, tipo, tiempo_expira, id_usuario)
        VALUES (?, ?, ?, NULL)
    ");
    $stmt->bind_param("sss", $payload, $tipo, $tiempo_expira);

    if (!$stmt->execute()) {
        $stmt->close();
        return [
            "success" => false,
            "message" => "Error al generar el cÃ³digo de verificaciÃ³n"
        ];
    }
    $stmt->close();

    return [
        "success" => true,
        "correo"  => $correo,
        "codigo"  => $codigo,   // el frontend lo usa para enviarlo por EmailJS
        "message" => "CÃ³digo de verificaciÃ³n generado correctamente"
    ];
}
?>

