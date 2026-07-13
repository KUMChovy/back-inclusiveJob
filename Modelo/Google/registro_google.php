<?php

require_once __DIR__ . '/../../config/cors.php';
header("Access-Control-Allow-Credentials: true"); 
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
header("Content-Type: application/json; charset=UTF-8");
if($_SERVER["REQUEST_METHOD"]==="OPTIONS"){http_response_code(200);exit;}

function reply(bool $ok, string $msg, array $extra=[], int $code=200):void{
    http_response_code($code);
    echo json_encode(array_merge(["success"=>$ok,"message"=>$msg],$extra));
    exit;
}

function decodeGoogleJWT(string $jwt):?array{
    $parts=explode(".",$jwt);
    if(count($parts)!==3)return null;
    $payload=base64_decode(str_replace(["-","_"],["+","/"],$parts[1]));
    if(!$payload)return null;
    return json_decode($payload,true)?:null;
}

if($_SERVER["REQUEST_METHOD"]!=="POST"){
    reply(false,"Método no permitido",[],405);
}

$body=json_decode(file_get_contents("php://input"),true);
$credential=trim($body["credential"]??"");
$tipo=trim($body["tipo"]??"");

if($credential===""||$tipo===""){
    reply(false,"Datos incompletos",[],400);
}

$roles=["postulante"=>2,"reclutador"=>3];
if(!isset($roles[$tipo])){
    reply(false,"Tipo de usuario inválido",[],422);
}
$idRol=$roles[$tipo];

const GOOGLE_CLIENT_ID="128233628072-7ds5mnsr3od6unpukot51dco8o4lstog.apps.googleusercontent.com";

$payload=decodeGoogleJWT($credential);
if(!$payload||($payload["aud"]??"")!==GOOGLE_CLIENT_ID||($payload["exp"]??0)<time()){
    reply(false,"Token de Google inválido o expirado",[],401);
}

$email=strtolower(trim($payload["email"]??""));
$nombre=trim($payload["name"]??"Usuario");
$foto=$payload["picture"]??null;

if($email===""){
    reply(false,"Google no devolvió un correo válido",[],422);
}

$partes=explode(" ",$nombre,2);
$nombres=$partes[0];
$apellidos=$partes[1]??"Google";

$conexionFile=__DIR__."/../../Conexion.php";
if(!file_exists($conexionFile)){
    reply(false,"Archivo de conexión no encontrado",[],500);
}
require_once $conexionFile;

if(!function_exists("conectarbd")){
    reply(false,"Función conectarbd no definida",[],500);
}

$con=conectarbd();
if(!$con){
    reply(false,"No se pudo conectar a la base de datos",[],500);
}

// ── ¿Ya existe? ──
$stmtCheck=mysqli_prepare($con,"SELECT id_usuario,id_rol FROM usuarios WHERE correo=?");
mysqli_stmt_bind_param($stmtCheck,"s",$email);
mysqli_stmt_execute($stmtCheck);
$row=mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck));

if($row){
    reply(true,"El usuario ya se encuentra registrado",[
        "exists"=>true,
        "id_usuario"=>(int)$row["id_usuario"],
        "rol"=>$tipo,
        "requiere_password"=>true, // flag para que el front redirija a cambio de contraseña
    ]);
}

// ── Contraseña mock — nunca coincidirá con un hash real ──
$passHash = password_hash("GOOGLE_AUTH_".bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

$sqlUser="INSERT INTO usuarios (nombres,apellidos,correo,contraseña,foto_perfil,id_rol,correo_validado) VALUES (?,?,?,?,?,?,1)";
$stmtUser=mysqli_prepare($con,$sqlUser);
mysqli_stmt_bind_param($stmtUser,"sssssi",$nombres,$apellidos,$email,$passHash,$foto,$idRol);

if(!mysqli_stmt_execute($stmtUser)){
    reply(false,"Error al registrar: ".mysqli_error($con),[],500);
}

$idUsuario=(int)mysqli_insert_id($con);

if($tipo==="postulante"){
    $stmtPerfil=mysqli_prepare($con,"INSERT INTO postulantes (id_usuario) VALUES (?)");
}else{
    $stmtPerfil=mysqli_prepare($con,"INSERT INTO reclutadores (id_usuario,id_empresas) VALUES (?,NULL)");
}
mysqli_stmt_bind_param($stmtPerfil,"i",$idUsuario);

if(!mysqli_stmt_execute($stmtPerfil)){
    $stmtRollback=mysqli_prepare($con,"DELETE FROM usuarios WHERE id_usuario=?");
    mysqli_stmt_bind_param($stmtRollback,"i",$idUsuario);
    mysqli_stmt_execute($stmtRollback);
    reply(false,"Error al crear perfil: ".mysqli_error($con),[],500);
}

reply(true,"Registro exitoso",[
    "exists"=>false,
    "id_usuario"=>$idUsuario,
    "rol"=>$tipo,
    "requiere_password"=>true, // flag para redirigir al cambio de contraseña
],201);