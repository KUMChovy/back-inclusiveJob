<?php
session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin:http://localhost:5173");
header("Access-Control-Allow-Credentials: true");


if (!isset($_SESSION["user"])) {
    echo json_encode([
        "auth" => false
    ]);
    exit;
}

echo json_encode([
    "auth" => true,
    "user" => $_SESSION["user"]
]);