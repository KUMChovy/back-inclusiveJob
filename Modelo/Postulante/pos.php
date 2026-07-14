<?php

require_once __DIR__ . '/../usuarios_telefono.php';

if (!function_exists('prepararConsulta')) {
    function prepararConsulta($con, string $sql) {
        $stmt = $con->prepare($sql);

        if (!$stmt) {
            throw new Exception("Error al preparar consulta: " . $con->error);
        }

        return $stmt;
    }
}

function registrarPostulante($con, $nombres, $correo, $password, $telefono) {
    // Verifica si el correo ya existe en usuarios.
    $check = prepararConsulta($con, "SELECT id_usuario FROM usuarios WHERE correo = ?");
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

    if (usuario_telefono_duplicado($con, $telefono)) {
        return [
            "success" => false,
            "message" => "Este numero de telefono ya esta registrado"
        ];
    }

    // La cuenta real se crea hasta que el usuario valida el codigo por correo.
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // Borra cualquier registro pendiente anterior con este correo.
    $stmtDel = prepararConsulta($con, "DELETE FROM tokens WHERE tipo = 'registro_pendiente' AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.correo')) = ?");
    $stmtDel->bind_param("s", $correo);
    $stmtDel->execute();
    $stmtDel->close();

    $codigo = sprintf('%06d', random_int(0, 999999));

    $payload = json_encode([
        "codigo"       => $codigo,
        "nombres"      => $nombres,
        "correo"       => $correo,
        "password"     => $hash,
        "telefono"     => $telefono,
        "id_rol"       => 2,
        "tipo_usuario" => "postulante"
    ]);

    $tiempo_expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $tipo = 'registro_pendiente';

    $stmt = prepararConsulta($con, "
        INSERT INTO tokens (token, tipo, tiempo_expira, id_usuario)
        VALUES (?, ?, ?, NULL)
    ");
    $stmt->bind_param("sss", $payload, $tipo, $tiempo_expira);

    if (!$stmt->execute()) {
        $stmt->close();
        return [
            "success" => false,
            "message" => "Error al generar el codigo de verificacion"
        ];
    }
    $stmt->close();

    return [
        "success" => true,
        "correo"  => $correo,
        "codigo"  => $codigo,
        "message" => "Codigo de verificacion generado correctamente"
    ];
}
?>
