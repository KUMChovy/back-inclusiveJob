<?php

function registrarPostulante($con, $nombres, $correo, $password, $telefono) {

    // 🔐 Verificar si ya existe el correo en usuarios
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

    // 🔐 Hash de contraseña (se guarda ya hasheada dentro del token)
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // 🔐 Borrar cualquier registro pendiente anterior con este correo
    //     (por si el usuario intentó registrarse antes y no verificó)
    $stmtDel = $con->prepare("DELETE FROM tokens WHERE tipo = 'registro_pendiente' AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.correo')) = ?");
    $stmtDel->bind_param("s", $correo);
    $stmtDel->execute();
    $stmtDel->close();

    // 🔐 Generar código de verificación de 6 dígitos
    $codigo = sprintf('%06d', random_int(0, 999999));

    // 🔐 Armar el payload con todos los datos del registro + el código
    $payload = json_encode([
        "codigo"   => $codigo,
        "nombres"  => $nombres,
        "correo"   => $correo,
        "password" => $hash,
        "telefono" => $telefono,
    ]);

    $tiempo_expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $tipo = 'registro_pendiente';

    // 🔐 Insertar en tokens con id_usuario = NULL (aún no existe el usuario)
    $stmt = $con->prepare("
        INSERT INTO tokens (token, tipo, tiempo_expira, id_usuario)
        VALUES (?, ?, ?, NULL)
    ");
    $stmt->bind_param("sss", $payload, $tipo, $tiempo_expira);

    if (!$stmt->execute()) {
        $stmt->close();
        return [
            "success" => false,
            "message" => "Error al generar el código de verificación"
        ];
    }
    $stmt->close();

    return [
        "success" => true,
        "correo"  => $correo,
        "codigo"  => $codigo,   // el frontend lo usa para enviarlo por EmailJS
        "message" => "Código de verificación generado correctamente"
    ];
}
?>