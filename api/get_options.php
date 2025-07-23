<?php
// get_options.php
// Este archivo proporciona datos para los selects dinámicos (facultades y carreras)
// mediante solicitudes AJAX.

// Ajusta la ruta a tu archivo de conexión.
// Si get_options.php está en 'api/' y conexion.php está en 'modelo/',
// la ruta relativa desde 'api/' a 'modelo/conexion.php' es '../modelo/conexion.php'.
require_once '../modelo/conexion.php'; 

header('Content-Type: application/json');

$conexion = new Conexion();
$pdo = $conexion->pdo;

$action = $_GET['action'] ?? '';
// Asegurarse de que el ID sea un entero válido
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

$response = ['success' => false, 'message' => 'Acción no válida.', 'data' => []];

try {
    if ($action === 'get_faculties_by_university' && $id !== null) {
        // Carga facultades por ID de universidad
        // Incluye facultades sin id_universidad asignado (para UNHEVAL si aplica)
        $stmt = $pdo->prepare("SELECT id_facultad, nombre_facultad FROM facultades WHERE id_universidad = :id_universidad OR id_universidad IS NULL ORDER BY nombre_facultad");
        $stmt->bindParam(':id_universidad', $id, PDO::PARAM_INT);
        $stmt->execute();
        $facultades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response = ['success' => true, 'data' => $facultades];
    } elseif ($action === 'get_careers_by_faculty' && $id !== null) {
        // Carga carreras por ID de facultad
        $stmt = $pdo->prepare("SELECT id_carrera, nombre_carrera FROM carreras WHERE id_facultad = :id_facultad ORDER BY nombre_carrera");
        $stmt->bindParam(':id_facultad', $id, PDO::PARAM_INT);
        $stmt->execute();
        $carreras = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response = ['success' => true, 'data' => $carreras];
    } else {
        $response['message'] = 'Parámetros de solicitud no válidos o ID no proporcionado.';
    }
} catch (PDOException $e) {
    $response['message'] = 'Error en la base de datos: ' . $e->getMessage();
    error_log("Error en get_options.php: " . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = 'Error inesperado: ' . $e->getMessage();
    error_log("Error general en get_options.php: " . $e->getMessage());
}

echo json_encode($response);
?>
