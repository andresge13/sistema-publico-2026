<?php
function obtenerDatosAPI($dni = '')
{
  if (empty($dni)) {
    return ['error' => 'No se especificó DNI'];
  }

  $ch = curl_init();
  $url = 'https://ws.unheval.edu.pe/api/v1/consulta-informacion?search=' . urlencode($dni) . '&type=alumno';
  $token = 'IpW8zwzNkRXlUNerRsH8iPG344r2y10HXrpiqYiS'; // Reemplaza por tu token si cambia

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
  ]);

  $response = curl_exec($ch);

  if (curl_errno($ch)) {
    curl_close($ch);
    return ['error' => 'Error al conectarse a la API: ' . curl_error($ch)];
  }

  curl_close($ch);

  return json_decode($response, true);
}
?>