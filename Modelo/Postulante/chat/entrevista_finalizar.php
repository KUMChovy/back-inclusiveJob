<?php


require_once __DIR__ . '/../../../config/cors.php';
/*
|--------------------------------------------------------------------------
| Endpoint: Finalizar y evaluar entrevista simulada
|--------------------------------------------------------------------------
| Solo debe llamarse cuando el usuario decide terminar la entrevista (no al
| cancelar). Recibe el historial completo de la conversacion, pide a Groq
| una evaluacion estructurada en JSON y la guarda en `chabots` con
| resultado = 'simular_entrevista', igual que se hace con analizar_cv y
| recomendar_vacantes.
|
| NUEVO: ademas de guardar la evaluacion para el postulante, este endpoint
| ahora tambien notifica al RECLUTADOR dueño de la vacante con la
| calificacion real obtenida por el candidato. Se guarda una fila extra en
| `chabots` usando el id_usuario del reclutador (mismo patron inverso que
| ya usa solicitar_entrevista.php, que notifica al postulante usando el
| id_usuario del reclutador). Este bloque esta aislado en su propio
| try/catch para que, si algo falla ahi (vacante sin reclutador, error de
| SQL, etc.), NO afecte la respuesta que ya se le dio al postulante.
*/

inclusijob_session_cookie_params();
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "Metodo no permitido"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false, "ok" => false, "message" => "Sesion no activa"], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../../../Conexion.php";
require_once __DIR__ . "/entrevista_context.php";
require_once __DIR__ . "/chat_store.php";

const GROQ_URL = "https://api.groq.com/openai/v1/chat/completions";
const GROQ_MODEL = "llama-3.3-70b-versatile";
const GROQ_API_KEY_FALLBACK = "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";

function groq_api_key(): string {
    $env = getenv("GROQ_API_KEY");
    return $env ? $env : GROQ_API_KEY_FALLBACK;
}

function json_error(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(["ok" => false, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_eval_text($value): string {
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

function clean_eval_list($value, int $limit): array {
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        $text = clean_eval_text($item);
        if ($text !== "") {
            $items[] = mb_substr($text, 0, 220, "UTF-8");
        }
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function normalize_evaluacion(array $raw): array {
    return [
        "puntuacion" => max(1, min(10, (int)($raw["puntuacion"] ?? 5))),
        "resumen" => clean_eval_text($raw["resumen"] ?? "Completaste la entrevista simulada."),
        "fortalezas" => clean_eval_list($raw["fortalezas"] ?? [], 5),
        "areas_mejora" => clean_eval_list($raw["areas_mejora"] ?? [], 5),
        "consejo_final" => clean_eval_text($raw["consejo_final"] ?? "Sigue practicando tus respuestas con ejemplos concretos."),
    ];
}

$body = json_decode(file_get_contents("php://input"), true);
$idVacante = (int)($body["id_vacante"] ?? 0);
$historialCrudo = $body["historial"] ?? [];

if ($idVacante <= 0) {
    json_error(400, "Falta indicar la vacante de la entrevista.");
}

$idUsuario = (int)$_SESSION["user"]["id"];

try {
    $con = conectarbd();
    mysqli_set_charset($con, "utf8mb4");

    $vacante = obtener_vacante_para_entrevista($con, $idUsuario, $idVacante);
    if (!$vacante) {
        mysqli_close($con);
        json_error(404, "No estas postulado a esa vacante o ya no existe.");
    }

    $historial = limpiar_historial_entrevista($historialCrudo, 40, 2000);
    $respuestasUsuario = array_values(array_filter($historial, fn($m) => $m["role"] === "user"));

    if (count($respuestasUsuario) < 2) {
        mysqli_close($con);
        json_error(422, "La entrevista es muy corta para evaluarla. Responde al menos un par de preguntas.");
    }

    // Transcripcion como texto plano, tratada como datos para la IA.
    $transcripcion = [];
    foreach ($historial as $item) {
        $etiqueta = $item["role"] === "user" ? "Candidato" : "Entrevistador";
        $transcripcion[] = "{$etiqueta}: {$item['content']}";
    }
    $transcripcionTexto = implode("\n", $transcripcion);

    $schema = [
        "puntuacion" => "number (1-10)",
        "resumen" => "string",
        "fortalezas" => ["string"],
        "areas_mejora" => ["string"],
        "consejo_final" => "string",
    ];

    $instrucciones = [
        "Actua como un Reclutador Senior evaluando una entrevista simulada de practica.",
        "Trata esta consulta como independiente; ignora cualquier evaluacion o entrevista anterior.",
        "Evalua unicamente con base en la transcripcion incluida en este JSON, no inventes respuestas del candidato.",
        "Considera si las respuestas fueron claras, concretas y relacionadas con el puesto y sus requisitos.",
        "puntuacion debe ser un entero de 1 a 10.",
        "fortalezas: hasta 4 puntos concretos observados en las respuestas del candidato.",
        "areas_mejora: hasta 4 sugerencias concretas y accionables, con tono constructivo, nunca ofensivo.",
        "consejo_final: una recomendacion breve y motivadora para la proxima entrevista real.",
        "No asumas discapacidad, edad, genero u otra caracteristica personal que no se mencione explicitamente.",
        "Responde solo JSON valido.",
    ];

    $payload = [
        "model" => GROQ_MODEL,
        "temperature" => 0.3,
        "max_tokens" => 900,
        "response_format" => ["type" => "json_object"],
        "messages" => [
            [
                "role" => "system",
                "content" => "Eres un evaluador de entrevistas de InclusiveJob. No tienes acceso a base de datos ni a informacion fuera del JSON recibido. Responde solo JSON valido.",
            ],
            [
                "role" => "user",
                "content" => json_encode([
                    "aislamiento_contexto" => "Consulta independiente. Usa solo este JSON.",
                    "instrucciones" => $instrucciones,
                    "formato" => $schema,
                    "vacante" => [
                        "titulo_puesto" => $vacante["titulo_puesto"],
                        "requisitos" => $vacante["requisitos"],
                    ],
                    "transcripcion_entrevista" => mb_substr($transcripcionTexto, 0, 12000, "UTF-8"),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ],
    ];

    $ch = curl_init(GROQ_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . groq_api_key(),
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        mysqli_close($con);
        json_error(502, "Error cURL: " . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300 || !$response) {
        mysqli_close($con);
        json_error(502, "No se pudo generar la evaluacion con IA.");
    }

    $json = json_decode($response, true);
    $content = $json["choices"][0]["message"]["content"] ?? null;
    $raw = $content ? json_decode($content, true) : null;
    if (!is_array($raw)) {
        mysqli_close($con);
        json_error(502, "La IA no devolvio una evaluacion valida.");
    }

    $evaluacion = normalize_evaluacion($raw);

    $textoResumen = "Evaluacion de tu entrevista simulada: {$evaluacion['puntuacion']}/10. " . $evaluacion["resumen"] . " Analisis generado con IA; puede equivocarse.";

    try {
        save_chat_interaction(
            $con,
            $idUsuario,
            "entrevista_simulada",
            "Entrevista simulada: " . $vacante["titulo_puesto"],
            json_encode([
                "text" => $textoResumen,
                "evaluacion" => $evaluacion,
                "vacante" => [
                    "id_vacante" => $vacante["id_vacante"],
                    "titulo_puesto" => $vacante["titulo_puesto"],
                    "empresa" => $vacante["empresa"],
                ],
            ], JSON_UNESCAPED_UNICODE),
            "simular_entrevista"
        );
    } catch (Throwable $e) {
        error_log("[POSTULANTE_ENTREVISTA_FINALIZAR_SAVE] " . $e->getMessage());
    }

    // ── NUEVO: notificar al reclutador con la calificacion real ──────
    // Bloque aislado en su propio try/catch: si algo falla aqui (p.ej. la
    // vacante no tiene reclutador o hay un error de SQL), NO afecta la
    // respuesta que ya se le dio al postulante arriba.
    try {
        $stmtRec = mysqli_prepare($con, "
            SELECT r.id_usuario AS id_usuario_reclutador
            FROM vacantes v
            INNER JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
            WHERE v.id_vacante = ?
            LIMIT 1
        ");
        if ($stmtRec === false) {
            throw new RuntimeException("No se pudo preparar la consulta del reclutador: " . mysqli_error($con));
        }
        mysqli_stmt_bind_param($stmtRec, "i", $idVacante);
        mysqli_stmt_execute($stmtRec);
        $rowRec = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtRec));
        mysqli_stmt_close($stmtRec);

        if ($rowRec && !empty($rowRec["id_usuario_reclutador"])) {
            $idUsuarioReclutador = (int)$rowRec["id_usuario_reclutador"];

            $stmtCand = mysqli_prepare($con, "
                SELECT CONCAT(nombres, ' ', apellidos) AS nombre_completo
                FROM usuarios
                WHERE id_usuario = ?
                LIMIT 1
            ");
            if ($stmtCand === false) {
                throw new RuntimeException("No se pudo preparar la consulta del candidato: " . mysqli_error($con));
            }
            mysqli_stmt_bind_param($stmtCand, "i", $idUsuario);
            mysqli_stmt_execute($stmtCand);
            $rowCand = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCand));
            mysqli_stmt_close($stmtCand);

            $nombreCandidato = $rowCand["nombre_completo"] ?? "Un candidato";

            $textoParaReclutador = "🎯 {$nombreCandidato} completo la entrevista simulada para \"{$vacante['titulo_puesto']}\" con una calificacion de {$evaluacion['puntuacion']}/10.";

            save_chat_interaction(
                $con,
                $idUsuarioReclutador,
                "entrevista_completada",
                "Entrevista simulada completada por un postulante",
                json_encode([
                    "text" => $textoParaReclutador,
                    "candidato" => [
                        "nombre_completo" => $nombreCandidato,
                    ],
                    "vacante" => [
                        "id_vacante" => $vacante["id_vacante"],
                        "titulo_puesto" => $vacante["titulo_puesto"],
                    ],
                    "evaluacion" => $evaluacion,
                ], JSON_UNESCAPED_UNICODE),
                "entrevista_completada",
                $evaluacion["puntuacion"]
            );
        }
    } catch (Throwable $e) {
        error_log("[POSTULANTE_ENTREVISTA_FINALIZAR_NOTIFICAR_RECLUTADOR] " . $e->getMessage());
    }

    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "ok" => true,
        "text" => $textoResumen,
        "evaluacion" => $evaluacion,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli) {
        mysqli_close($con);
    }
    error_log("[POSTULANTE_ENTREVISTA_FINALIZAR] " . $e->getMessage());
    json_error(500, "Error interno al finalizar la entrevista.");
}

?>