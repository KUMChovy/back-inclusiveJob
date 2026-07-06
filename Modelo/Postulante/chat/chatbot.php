<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

$mensaje=$_POST["mensaje"] ?? "";

if(trim($mensaje)==""){

    die("Escribe un mensaje.");

}

$apiKey="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";

$url="https://api.groq.com/openai/v1/chat/completions";

$datos=[

    "model"=>"llama-3.3-70b-versatile",

    "messages"=>[

        [

            "role"=>"system",

            "content"=>"Eres el chatbot de InclusiveJob.
            Ayudas a personas con discapacidad
            a encontrar empleo."

        ],

        [

            "role"=>"user",

            "content"=>$mensaje

        ]

    ]

];

$ch=curl_init($url);

curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);

curl_setopt($ch,CURLOPT_POST,true);

curl_setopt($ch,CURLOPT_HTTPHEADER,[

"Authorization: Bearer ".$apiKey,

"Content-Type: application/json"

]);

curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($datos));

$respuesta = curl_exec($ch);

if (curl_errno($ch)) {
    die("Error cURL: " . curl_error($ch));
}

curl_close($ch);

$json = json_decode($respuesta, true);

if (isset($json["choices"][0]["message"]["content"])) {
    echo $json["choices"][0]["message"]["content"];
} else {
    echo "Ocurrió un error.";
}

?>