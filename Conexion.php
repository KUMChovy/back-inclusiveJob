<?php

require_once __DIR__ . '/config/cors.php';

function conectarbd() {
    $con = mysqli_connect(INCLUSIJOB_DB_HOST, INCLUSIJOB_DB_USER, INCLUSIJOB_DB_PASSWORD, INCLUSIJOB_DB_NAME);

    if (!$con) {
        die("Error de conexion: " . mysqli_connect_error());
    }

    mysqli_set_charset($con, "utf8mb4");

    return $con;
}
?>
