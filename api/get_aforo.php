<?php
header('Content-Type: application/json');
include_once '../modelo/conexion.php';

$conexion = new Conexion();
$pdo = $conexion->pdo;

try {
    // Configuración
    $stmt_config = $pdo->query("SELECT clave, valor FROM configuracion_sistema WHERE clave IN ('capacidad_biblioteca', 'control_aforo_activo')");
    $config = [];
    while ($row = $stmt_config->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['clave']] = $row['valor'];
    }

    // Calcular aforo hoy
    $sql = "SELECT 
                (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Entrada' AND fecha = CURDATE()) -
                (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Salida' AND fecha = CURDATE()) 
              AS aforo_actual";
    $stmt = $pdo->query($sql);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    $aforo_actual = max(0, intval($resultado['aforo_actual']));
    $capacidad = intval($config['capacidad_biblioteca'] ?? 500);

    echo json_encode([
        'success' => true,
        'aforo_actual' => $aforo_actual,
        'capacidad' => $capacidad,
        'porcentaje' => round(($aforo_actual / $capacidad) * 100, 1),
        'activo' => ($config['control_aforo_activo'] ?? '0') === '1'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
