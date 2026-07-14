<?php

if (!function_exists('usuario_telefono_digitos')) {
    function usuario_telefono_digitos($telefono_raw) {
        return preg_replace('/\D+/', '', (string)$telefono_raw);
    }
}

if (!function_exists('usuario_telefono_nacional')) {
    function usuario_telefono_nacional($telefono_raw) {
        $digitos = usuario_telefono_digitos($telefono_raw);

        if ($digitos === '') {
            return '';
        }

        $ladas = [
            "502", "503", "504", "505", "506", "507",
            "591", "593", "595", "598",
            "52", "34", "54", "56", "57", "51", "58", "55",
            "1"
        ];

        if (strlen($digitos) > 10) {
            foreach ($ladas as $lada) {
                if (strpos($digitos, $lada) === 0 && strlen($digitos) > strlen($lada)) {
                    return substr($digitos, strlen($lada));
                }
            }
        }

        return $digitos;
    }
}

if (!function_exists('usuario_telefono_duplicado')) {
    function usuario_telefono_duplicado(mysqli $con, $telefono_raw, ?int $id_usuario_actual = null) {
        $telefono = usuario_telefono_digitos($telefono_raw);
        $telefono_nacional = usuario_telefono_nacional($telefono_raw);

        if ($telefono === '' || $telefono_nacional === '') {
            return false;
        }

        $telefonoSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefono, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
        $sql = "SELECT id_usuario
                  FROM usuarios
                 WHERE COALESCE(telefono, '') <> ''
                   AND ($telefonoSql = ? OR RIGHT($telefonoSql, 10) = ?)";

        if ($id_usuario_actual !== null) {
            $sql .= " AND id_usuario <> ?";
        }

        $sql .= " LIMIT 1";

        $stmt = mysqli_prepare($con, $sql);
        if (!$stmt) {
            throw new Exception("No se pudo validar el telefono: " . mysqli_error($con));
        }

        if ($id_usuario_actual !== null) {
            mysqli_stmt_bind_param($stmt, "ssi", $telefono, $telefono_nacional, $id_usuario_actual);
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $telefono, $telefono_nacional);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $duplicado = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        return $duplicado;
    }
}

?>
