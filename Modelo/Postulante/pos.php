<?php
//función para registrar un postulante
function registrarPostulante($con, $nombres, $correo, $password, $telefono) {

    // 🔐 Verificar si ya existe el correo
    $check = $con->prepare("SELECT id_usuario FROM usuarios WHERE correo = ?");
    $check->bind_param("s", $correo);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        return [
            "success" => false,
            "message" => "Datos ya existentes"
        ];
    }

    // 🔐 Hash de contraseña
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $id_rol = 2; // postulante

    // 🔐 Insert en usuarios
    $stmt = $con->prepare("
        INSERT INTO usuarios (nombres, correo, contraseña, telefono, id_rol)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("ssssi", $nombres, $correo, $hash, $telefono, $id_rol);

    if (!$stmt->execute()) {
        return [
            "success" => false,
            "message" => "Error al crear usuario"
        ];
    }

    $id_usuario = $stmt->insert_id;

    // 🔐 Insert en postulantes
    $stmt2 = $con->prepare("
        INSERT INTO postulantes (id_usuario)
        VALUES (?)
    ");

    $stmt2->bind_param("i", $id_usuario);

    if (!$stmt2->execute()) {
        return [
            "success" => false,
            "message" => "Error al crear postulante"
        ];
    }

    return [
        "success" => true,
        "id_usuario" => $id_usuario,
        "message" => "Postulante registrado correctamente"
    ];
}

?>