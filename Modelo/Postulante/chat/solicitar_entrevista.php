<?php

/*
|--------------------------------------------------------------------------
| Endpoint: Solicitar entrevista para un candidato (Inclusive Job)
|--------------------------------------------------------------------------
| Ubicacion sugerida: Modelo/Reclutador/chat/solicitar_entrevista.php
| (misma carpeta que recomendar_candidatos.php, para reutilizar
| chat_store.php).
|
| El reclutador, tras ver la recomendacion de "mejor candidato", puede
| pedirle una entrevista REAL (proceso de seleccion, no practica). Este
| endpoint NO ejecuta la entrevista (eso lo maneja
| Postulante/chat/entrevista_real.php via EntrevistaRealModal). Su unico
| trabajo es dejar una "notificacion" para el postulante.
|
| COMO FUNCIONA LA NOTIFICACION (sin tabla nueva):
|   La tabla `chabots` es compartida entre Postulante y Reclutador (ver
|   chat_store.php). Este endpoint guarda una fila usando el `id_usuario`
|   del POSTULANTE (no del reclutador), con resultado =
|   'entrevista_real_solicitada' y un payload JSON en `respuesta_ia`.
|   Cuando el postulante abra su chat, su propio historial
|   (usePostulanteChatHistorial + mapHistoryToMessages en ChatBubble.jsx)
|   va a traer esa fila y renderizar la notificacion tipo
|   "entrevista-real-solicitada", con el boton "Iniciar entrevista" que
|   abre EntrevistaRealModal (NO el EntrevistaModal de practica).
|
| SEGURIDAD (misma filosofia que recomendar_candidatos.php):
|   - Requiere sesion activa + rol de reclutador verificado en BD.
|   - Se valida que la postulacion (id_postulacion) pertenezca a una
|     vacante de ESTE reclutador antes de notificar a nadie. Un reclutador
|     nunca puede solicitar una entrevista sobre una postulacion ajena.
|
| NUEVO (candidato que ya completo su entrevista real): antes de notificar
| al postulante, se verifica en `chabots` si ya existe una fila con
| resultado = 'entrevista_real_realizada' para ese usuario y esa vacante
| (misma consulta/patron que ya usa Postulante/chat/entrevista_real.php en
| ya_realizo_entrevista_real(), que es justo lo que bloquea con 409 que el
| postulante la repita). Si ya existe:
|   - NO se guarda ninguna notificacion nueva para el postulante (ya no
|     tendria sentido, ademas entrevista_real.php lo rechazaria de todas
|     formas con un 409 si intentara iniciarla de nuevo).
|   - Se responde al reclutador con "ya_completada": true y la evaluacion
|     ya guardada (puntuacion, resumen, etc.), para que el chat del
|     reclutador pueda mostrarle un mensaje con la calificacion y, del
|     lado del frontend, pedir automaticamente un siguiente candidato.
|
| FIX (500 sin JSON): con mysqli en modo de excepciones (default desde
| PHP 8.1), un mysqli_prepare() que falla NO regresa false: lanza una
| mysqli_sql_exception. El codigo original solo revisaba "=== false" y
| dejaba esas excepciones sin capturar, lo que tumbaba el script entero
| con un 500 crudo (sin cuerpo JSON) apenas algo en el SQL no coincidia
| con el esquema real. Ahora toda la logica de BD esta envuelta en un
| try/catch(Throwable), igual que ya hace entrevista_iniciar.php, para
| que cualquier error real de SQL responda con JSON en vez de romper la
| respuesta.
|
| FIX (entrevista equivocada): antes este endpoint guardaba la fila con
| resultado = 'entrevista_solicitada', que es el mismo valor que usa la
| notificacion de entrevista SIMULADA (practica) del lado del postulante.
| Como ChatBubble.jsx del postulante decide que tarjeta/modal mostrar
| leyendo exactamente ese campo `resultado`, la notificacion caia
| siempre en la rama de "entrevista-solicitada" (simulada) y abria
| EntrevistaModal en vez de EntrevistaRealModal. Ahora se guarda con
| resultado = 'entrevista_real_solicitada', que es lo que ChatBubble.jsx
| ya esperaba para la rama "entrevista-real-solicitada". Tambien se
| corrigio el texto de la notificacion, que decia "entrevista simulada"
| por error.
*/

session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict",
]);
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../../Conexion.php";
require_once __DIR__ . "/../../Reclutador/chat/chat_store.php";

const ROL_RECLUTADOR = 3;


function responderJSON(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}


function registrarEvento(string $tipo, string $detalle, $idUsuario = null): void
{
    $carpeta = __DIR__ . "/logs";
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }

    $fecha = date("Y-m-d H:i:s");
    $usuario = $idUsuario ?? ($_SESSION["user"]["id"] ?? "anonimo");

    $linea = "[$fecha] Usuario:$usuario Tipo:$tipo Detalle:$detalle" . PHP_EOL;

    file_put_contents($carpeta . "/solicitar_entrevista_seguridad.log", $linea, FILE_APPEND);
}


/*
|--------------------------------------------------------------------------
| NUEVO: verifica si el candidato de esta postulacion YA completo una
| entrevista REAL para esta vacante y, de ser asi, regresa la evaluacion
| ya guardada.
|--------------------------------------------------------------------------
| Mismo patron/consulta que ya_realizo_entrevista_real() en
| Postulante/chat/entrevista_real.php (misma tabla `chabots`, mismo
| resultado = 'entrevista_real_realizada', mismo JSON_EXTRACT sobre
| vacante.id_vacante). Aqui, ademas de confirmar que existe, se devuelve
| el objeto "evaluacion" ya guardado en el payload, para no tener que
| volver a calcularlo ni llamar a la IA de nuevo.
*/
function obtenerEvaluacionEntrevistaReal(mysqli $con, int $idUsuarioPostulante, int $idVacante): ?array
{
    $stmt = mysqli_prepare($con, "
        SELECT respuesta_ia
        FROM chabots
        WHERE id_usuario = ?
          AND resultado = 'entrevista_real_realizada'
          AND JSON_EXTRACT(respuesta_ia, '$.vacante.id_vacante') = ?
        LIMIT 1
    ");
    if ($stmt === false) {
        throw new RuntimeException("No se pudo preparar la verificacion de entrevista previa: " . mysqli_error($con));
    }
    mysqli_stmt_bind_param($stmt, "ii", $idUsuarioPostulante, $idVacante);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }

    $payload = json_decode((string)$row["respuesta_ia"], true);
    $evaluacion = is_array($payload["evaluacion"] ?? null) ? $payload["evaluacion"] : null;

    // Si por algun motivo el payload no traia "evaluacion" (no deberia
    // pasar, entrevista_real.php siempre la guarda), se regresa una
    // estructura vacia en vez de null, para que el llamador sepa que SI
    // ya se completo la entrevista aunque no haya detalle que mostrar.
    return $evaluacion ?? [
        "puntuacion" => 0,
        "resumen" => "",
        "fortalezas" => [],
        "areas_mejora" => [],
        "consejo_final" => "",
    ];
}


/*
|--------------------------------------------------------------------------
| 1. Solo POST
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJSON(405, ["ok" => false, "message" => "Metodo no permitido."]);
}

/*
|--------------------------------------------------------------------------
| 2. Sesion activa
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user"]["id"])) {
    registrarEvento("ACCESO_DENEGADO", "Usuario sin sesion activa");
    responderJSON(401, ["ok" => false, "message" => "Debe iniciar sesion para usar esta funcion."]);
}

$id_usuario_reclutador = (int)$_SESSION["user"]["id"];

/*
|--------------------------------------------------------------------------
| 3. Body: id_postulacion es obligatorio (se valida antes de tocar BD)
|--------------------------------------------------------------------------
*/
$body = json_decode(file_get_contents("php://input"), true);
$id_postulacion = is_array($body) ? (int)($body["id_postulacion"] ?? 0) : 0;

if ($id_postulacion <= 0) {
    responderJSON(422, ["ok" => false, "message" => "Falta indicar la postulacion."]);
}

/*
|--------------------------------------------------------------------------
| 4. Toda la logica de BD envuelta en try/catch, para que CUALQUIER error
|    de SQL (tabla/columna que no coincide con tu esquema real, etc.)
|    responda con JSON en vez de tumbar el script con un 500 sin cuerpo.
|--------------------------------------------------------------------------
*/
$con = null;

try {
    $con = conectarbd();
    mysqli_set_charset($con, "utf8mb4");

    // ── Verificar rol de reclutador (contra BD) ──────────────
    $stmtRol = mysqli_prepare($con,
        "SELECT u.id_rol, r.id_reclutador
         FROM usuarios u
         LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
         WHERE u.id_usuario = ? AND u.estado = 1
         LIMIT 1"
    );
    if ($stmtRol === false) {
        throw new RuntimeException("No se pudo preparar la verificacion de rol: " . mysqli_error($con));
    }
    mysqli_stmt_bind_param($stmtRol, "i", $id_usuario_reclutador);
    mysqli_stmt_execute($stmtRol);
    $rowRol = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtRol));
    mysqli_stmt_close($stmtRol);

    if (!$rowRol || (int)$rowRol["id_rol"] !== ROL_RECLUTADOR || empty($rowRol["id_reclutador"])) {
        registrarEvento("ACCESO_DENEGADO", "Rol no autorizado", $id_usuario_reclutador);
        mysqli_close($con);
        responderJSON(403, ["ok" => false, "message" => "No tienes autorizacion para usar esta funcion."]);
    }
    $id_reclutador = (int)$rowRol["id_reclutador"];

    // ── Obtener la postulacion + validar que pertenece a ESTE reclutador ──
    // AJUSTA: si quieres mostrar el nombre real de la empresa en el
    // mensaje de notificacion, agrega aqui el JOIN a tu tabla de empresas
    // una vez confirmado su nombre/columnas reales, por ejemplo:
    //   LEFT JOIN empresas e ON e.id_reclutador = v.id_reclutador
    // y agrega "COALESCE(e.nombre_empresa, '') AS empresa" al SELECT.
    $sql = "SELECT p.id_postulacion, p.id_vacante,
                   pf.id_postulante, u.id_usuario AS id_usuario_postulante,
                   CONCAT(u.nombres, ' ', u.apellidos) AS nombre_completo,
                   v.titulo_puesto, v.modalidad, v.id_reclutador
            FROM postulaciones p
            JOIN postulantes pf ON pf.id_postulante = p.id_postulante
            JOIN usuarios u ON u.id_usuario = pf.id_usuario
            JOIN vacantes v ON v.id_vacante = p.id_vacante
            WHERE p.id_postulacion = ?
            LIMIT 1";

    $stmt = mysqli_prepare($con, $sql);
    if ($stmt === false) {
        throw new RuntimeException("No se pudo preparar la consulta de postulacion: " . mysqli_error($con));
    }

    mysqli_stmt_bind_param($stmt, "i", $id_postulacion);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        mysqli_close($con);
        responderJSON(404, ["ok" => false, "message" => "No se encontro esa postulacion."]);
    }

    // Ownership: la vacante de esta postulacion debe pertenecer a ESTE reclutador.
    if ((int)$row["id_reclutador"] !== $id_reclutador) {
        registrarEvento("ACCESO_DENEGADO", "Postulacion no pertenece a este reclutador (id_postulacion=$id_postulacion)", $id_usuario_reclutador);
        mysqli_close($con);
        responderJSON(403, ["ok" => false, "message" => "No tienes autorizacion sobre esta postulacion."]);
    }

    $idUsuarioPostulante = (int)$row["id_usuario_postulante"];
    $tituloPuesto = (string)$row["titulo_puesto"];
    $modalidad = (string)$row["modalidad"];
    $idVacante = (int)$row["id_vacante"];
    $nombreCompleto = (string)$row["nombre_completo"];

    // ── NUEVO: si el candidato YA completo su entrevista real para esta
    // vacante, no se le vuelve a notificar (ademas, entrevista_real.php
    // ya bloqueria repetirla con un 409 si igual se le mandara). En vez
    // de eso, se le informa aqui mismo al reclutador con la calificacion
    // real, para que el chat pueda mostrarsela y pedir otro candidato.
    $evaluacionPrevia = obtenerEvaluacionEntrevistaReal($con, $idUsuarioPostulante, $idVacante);
    if ($evaluacionPrevia !== null) {
        mysqli_close($con);
        $con = null;

        registrarEvento(
            "YA_COMPLETADA",
            "Postulacion:$id_postulacion Vacante:$idVacante Postulante(usuario):$idUsuarioPostulante",
            $id_usuario_reclutador
        );

        responderJSON(200, [
            "ok" => true,
            "ya_completada" => true,
            "mensaje" => "{$nombreCompleto} ya completo su entrevista para \"{$tituloPuesto}\" con una calificacion de {$evaluacionPrevia['puntuacion']}/10.",
            "candidato" => [
                "id_postulacion" => $id_postulacion,
                "nombre_completo" => $nombreCompleto,
            ],
            "vacante" => [
                "id_vacante" => $idVacante,
                "titulo_puesto" => $tituloPuesto,
            ],
            "evaluacion" => $evaluacionPrevia,
        ]);
    }

    // ── Guardar la notificacion en el chat del POSTULANTE (chabots compartida) ──
    // FIX: el texto decia "entrevista simulada" por error. Esta
    // notificacion es para una entrevista REAL del proceso de seleccion.
    $textoNotificacion = "📋 Un reclutador te invito a una entrevista para el puesto de \"{$tituloPuesto}\". ¿Quieres realizarla ahora?";

    $payload = [
        "text" => $textoNotificacion,
        "vacante" => [
            "id_vacante" => $idVacante,
            "titulo_puesto" => $tituloPuesto,
            "empresa" => "",
            "modalidad" => $modalidad,
        ],
    ];

    // FIX: tipo_interaccion Y resultado deben ser 'entrevista_real_solicitada',
    // no 'entrevista_solicitada' (ese valor es el que usa la notificacion de
    // entrevista SIMULADA y hace que el frontend del postulante abra el modal
    // equivocado). Ver ChatBubble.jsx (postulante) -> mapHistoryToMessages().
    save_chat_interaction(
        $con,
        $idUsuarioPostulante,
        'entrevista_real_solicitada',
        'Solicitud de entrevista de un reclutador',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        'entrevista_real_solicitada',
        null,
        null
    );

    mysqli_close($con);
    $con = null;

    registrarEvento(
        "SOLICITUD_OK",
        "Postulacion:$id_postulacion Vacante:$idVacante Postulante(usuario):$idUsuarioPostulante",
        $id_usuario_reclutador
    );

    responderJSON(200, [
        "ok" => true,
        "mensaje" => "Se envio la solicitud de entrevista a {$nombreCompleto}. Le llegara como notificacion en su chat.",
    ]);
} catch (Throwable $e) {
    if ($con instanceof mysqli) {
        mysqli_close($con);
    }
    error_log("[RECLUTADOR_SOLICITAR_ENTREVISTA] " . $e->getMessage());
    registrarEvento("ERROR_INTERNO", $e->getMessage(), $id_usuario_reclutador);
    responderJSON(500, [
        "ok" => false,
        "message" => "Error interno al solicitar la entrevista. Revisa el log del servidor para mas detalle.",
    ]);
}