<?php

function conectarbd(){
    $con = mysqli_connect("localhost", "root", "", "Inclusive");

    if(!$con){
        die("Error de conexión: " . mysqli_connect_error());
    }

    return $con;
}