<?php

/*
|--------------------------------------------------------------------------
| Helper de almacenamiento para interacciones del chatbot
|--------------------------------------------------------------------------
| Usa la tabla `chabots` existente. El campo `resultado` se usa como
| identificador tecnico de accion:
| - chat_general
| - recomendar_vacantes
| - analizar_cv
*/

function save_chat_interaction(
    mysqli $con,
    int $idUsuario,
    string $tipoInteraccion,
    string $mensajeUsuario,
    string $respuestaIa,
    string $resultado,
    ?int $calificacion = null,
    ?string $archivoCv = null
): void {
    $sql = "
        INSERT INTO chabots
            (tipo_interaccion, mensaje_usuario, respuesta_ia, resultado, calificacion, archivo_cv, id_usuario)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($con));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ssssisi",
        $tipoInteraccion,
        $mensajeUsuario,
        $respuestaIa,
        $resultado,
        $calificacion,
        $archivoCv,
        $idUsuario
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

?>
