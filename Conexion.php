<?php

function conectarbd() {
    $con = mysqli_connect("localhost", "root", "", "inclusijob");

    if (!$con) {
        die("Error de conexion: " . mysqli_connect_error());
    }

    mysqli_set_charset($con, "utf8mb4");

    return $con;
}
?>