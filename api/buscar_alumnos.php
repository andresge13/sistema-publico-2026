<?php
header('Content-Type: application/json');

$dni = $_GET['dni'] ?? '';
if ($dni === '12345678') {
  echo json_encode([
    'success' => true,
    'alumno' => [
      'id' => 1,
      'nombre' => 'Juan',
      'apellido' => 'Perez',
      'dni' => '12345678',
      'carrera' => 'Ingeniería de Sistemas'
    ]
  ]);
} else {
  echo json_encode(['success' => false]);
}
