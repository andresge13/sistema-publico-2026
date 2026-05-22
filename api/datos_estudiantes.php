<?php
/**
 * Consulta datos de estudiante UNHEVAL desde la API institucional.
 * Maneja respuestas tipo objeto y array, y normaliza nombres de campos.
 * Incluye reintentos automáticos y manejo robusto de errores de red.
 */
function obtenerDatosAPI($dni = '', $reintentos = 2)
{
  if (empty($dni)) {
    return ['error' => 'No se especificó DNI'];
  }

  $token = 'IpW8zwzNkRXlUNerRsH8iPG344r2y10HXrpiqYiS';
  $url = 'https://ws.unheval.edu.pe/api/v1/consulta-informacion?search=' . urlencode($dni) . '&type=alumno';

  $ultimo_error = '';

  for ($intento = 0; $intento <= $reintentos; $intento++) {
    if ($intento > 0) {
      usleep(500000);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
      ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
      $ultimo_error = curl_error($ch);
      curl_close($ch);
      error_log("API UNHEVAL intento {$intento}: Error cURL - {$ultimo_error}");
      continue;
    }

    curl_close($ch);

    if ($http_code >= 500) {
      $ultimo_error = "HTTP {$http_code}";
      error_log("API UNHEVAL intento {$intento}: Error HTTP {$http_code}");
      continue;
    }

    if ($http_code >= 400 && $http_code < 500) {
      return ['error' => "Error de autenticación con la API (HTTP {$http_code})"];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
      $ultimo_error = 'Respuesta no es JSON válido';
      continue;
    }

    // Procesar y normalizar
    if (!isset($decoded['datos'])) {
      return $decoded;
    }

    $datos = $decoded['datos'];

    if (is_array($datos) && isset($datos[0])) {
      $mejor = null;
      foreach ($datos as $alumno) {
        $estado = $alumno['Estado'] ?? $alumno['estado'] ?? $alumno['Des_Estado'] ?? '';
        $nivel = $alumno['Niv_Acad'] ?? $alumno['nivel_academico'] ?? '';
        if (stripos($estado, 'activ') !== false || stripos($estado, 'matric') !== false) {
          if ($mejor === null || stripos($nivel, 'pregrado') !== false) {
            $mejor = $alumno;
          }
        }
      }
      if ($mejor === null) {
        $mejor = $datos[0];
      }
      $decoded['datos'] = $mejor;
    }

    $d = &$decoded['datos'];
    if (is_array($d)) {
      if (!isset($d['Facultad']) || empty($d['Facultad'])) {
        $d['Facultad'] = $d['Des_Facultad'] ?? $d['des_facultad'] ?? $d['facultad'] ?? $d['FACULTAD'] ?? '';
      }
      if (!isset($d['Escuela']) || empty($d['Escuela'])) {
        $d['Escuela'] = $d['Des_Escuela'] ?? $d['Des_Programa'] ?? $d['Programa'] ?? $d['des_escuela'] ?? $d['programa'] ?? $d['escuela'] ?? $d['ESCUELA'] ?? '';
      }
      if (!isset($d['Id_Alumno']) || empty($d['Id_Alumno'])) {
        $d['Id_Alumno'] = $d['Cod_Alumno'] ?? $d['codigo'] ?? $d['codigo_alumno'] ?? $d['cod_alumno'] ?? '';
      }
      if (!isset($d['Nro_Doc']) || empty($d['Nro_Doc'])) {
        $d['Nro_Doc'] = $d['Dni'] ?? $d['dni'] ?? $d['DNI'] ?? $d['nro_doc'] ?? '';
      }
      if (!isset($d['Nombres']) || empty($d['Nombres'])) {
        $d['Nombres'] = $d['nombres'] ?? $d['NOMBRES'] ?? '';
      }
      if (!isset($d['Paterno']) || empty($d['Paterno'])) {
        $d['Paterno'] = $d['paterno'] ?? $d['Ap_Paterno'] ?? $d['ap_paterno'] ?? '';
      }
      if (!isset($d['Materno']) || empty($d['Materno'])) {
        $d['Materno'] = $d['materno'] ?? $d['Ap_Materno'] ?? $d['ap_materno'] ?? '';
      }
      if (!isset($d['Niv_Acad']) || empty($d['Niv_Acad'])) {
        $d['Niv_Acad'] = $d['nivel_academico'] ?? $d['Nivel'] ?? $d['nivel'] ?? 'Pregrado';
      }
    }

    return $decoded;
  }

  error_log("API UNHEVAL: TODOS los reintentos fallaron para DNI {$dni}");
  return ['error' => 'Error al conectarse a la API: ' . $ultimo_error];
}
?>