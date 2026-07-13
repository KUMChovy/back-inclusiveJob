<?php

require_once __DIR__ . '/../../config/cors.php';
// ============================================================
//  obtener_vacantes_debug.php
//  VERSIÓN TEMPORAL DE DEPURACIÓN — borra este archivo después.
//  Muestra paso a paso qué está pasando con los datos.
// ============================================================

inclusijob_session_cookie_params();

session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");

if (!isset($_SESSION["user"]["id"])) {
    echo json_encode(["debug" => "no_session"]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

require_once "../../conexion.php";
$con = conectarbd();

$debug = ["id_usuario" => $id_usuario];

// ── id_postulante ──
$stmtP = mysqli_prepare($con, "SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1");
mysqli_stmt_bind_param($stmtP, "i", $id_usuario);
mysqli_stmt_execute($stmtP);
$resP = mysqli_stmt_get_result($stmtP);
$postulante = mysqli_fetch_assoc($resP);
mysqli_stmt_close($stmtP);

$debug["postulante"] = $postulante;

if (!$postulante) {
    $debug["motivo"] = "sin_postulante";
    echo json_encode($debug);
    exit;
}

$id_postulante = (int)$postulante["id_postulante"];

// ── tipos de discapacidad del postulante ──
$stmtD = mysqli_prepare($con, "SELECT id_tipo_discapacidad FROM postulante_discapacidad WHERE id_postulante = ?");
mysqli_stmt_bind_param($stmtD, "i", $id_postulante);
mysqli_stmt_execute($stmtD);
$resD = mysqli_stmt_get_result($stmtD);
$tipos = [];
while ($row = mysqli_fetch_assoc($resD)) {
    $tipos[] = (int)$row["id_tipo_discapacidad"];
}
mysqli_stmt_close($stmtD);

$debug["id_postulante"] = $id_postulante;
$debug["tipos_discapacidad_postulante"] = $tipos;

if (empty($tipos)) {
    $debug["motivo"] = "sin_tipos_discapacidad";
    echo json_encode($debug);
    exit;
}

// ── vacantes activas (sin filtro de discapacidad, para comparar) ──
$resTodas = mysqli_query($con, "SELECT id_vacante, titulo_puesto, estado FROM vacantes");
$todasVacantes = [];
while ($row = mysqli_fetch_assoc($resTodas)) {
    $todasVacantes[] = $row;
}
$debug["todas_las_vacantes"] = $todasVacantes;

// ── todas las relaciones vacante_discapacidad ──
$resRel = mysqli_query($con, "SELECT * FROM vacante_discapacidad");
$todasRelaciones = [];
while ($row = mysqli_fetch_assoc($resRel)) {
    $todasRelaciones[] = $row;
}
$debug["todas_las_relaciones_vacante_discapacidad"] = $todasRelaciones;

// ── ahora sí, la query real con el IN ──
$placeholders = implode(",", array_fill(0, count($tipos), "?"));
$types        = str_repeat("i", count($tipos));

$sqlVacantes = "
    SELECT DISTINCT v.id_vacante, v.titulo_puesto, v.estado
    FROM vacantes v
    INNER JOIN vacante_discapacidad vd ON v.id_vacante = vd.id_vacante
    WHERE v.estado = 'activa'
      AND vd.id_tipo_discapacidad IN ($placeholders)
";

$debug["sql_generado"] = $sqlVacantes;
$debug["placeholders"] = $placeholders;
$debug["types"] = $types;

$stmtV = mysqli_prepare($con, $sqlVacantes);

if (!$stmtV) {
    $debug["error_prepare"] = mysqli_error($con);
    echo json_encode($debug);
    exit;
}

mysqli_stmt_bind_param($stmtV, $types, ...$tipos);
mysqli_stmt_execute($stmtV);
$resV = mysqli_stmt_get_result($stmtV);
$vacantesResultado = [];
while ($v = mysqli_fetch_assoc($resV)) {
    $vacantesResultado[] = $v;
}

$debug["vacantes_resultado_final"] = $vacantesResultado;

mysqli_close($con);

echo json_encode($debug, JSON_PRETTY_PRINT);