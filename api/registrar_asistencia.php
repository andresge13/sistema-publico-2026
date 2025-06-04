<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['alumno_id']) || !isset($input['tipo'])) {
  echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
  exit;
}

// Simula éxito
echo json_encode(['success' => true]);
