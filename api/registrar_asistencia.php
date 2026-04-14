<?php
session_start();
header('Content-Type: application/json');
include_once '../modelo/conexion.php';
include_once(__DIR__ . '/datos_estudiantes.php');

$conexion = new Conexion();
$pdo = $conexion->pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['dni'])) {
    echo json_encode(['success' => false, 'message' => 'DNI no proporcionado']);
    exit();
}

$dni_buscado = trim($_POST['dni']);
$mensaje = '';
$tipo_mensaje = '';
$datos_usuario = null;

try {
    // Leer configuración del sistema
    $stmt_config = $pdo->query("SELECT clave, valor FROM configuracion_sistema");
    $config_sistema = [];
    if ($stmt_config) {
        while ($row = $stmt_config->fetch(PDO::FETCH_ASSOC)) {
            $config_sistema[$row['clave']] = $row['valor'];
        }
    }

    // Funciones de validación locales (basadas en estudiante.php)
    function checkHorario($config) {
        if (($config['horario_atencion_activo'] ?? '0') !== '1') return ['permitido' => true];
        $dia_semana = date('w');
        $hora_actual = date('H:i');
        if ($dia_semana == 0) {
            if (($config['atencion_domingo'] ?? '0') !== '1') return ['permitido' => false, 'mensaje' => $config['mensaje_fuera_horario'] ?? 'Cerrado domingos'];
            return ['permitido' => true];
        }
        if ($dia_semana == 6) {
            $h_ap = $config['hora_apertura_sabado'] ?? '08:00';
            $h_ci = $config['hora_cierre_sabado'] ?? '13:00';
        } else {
            $h_ap = $config['hora_apertura_lun_vie'] ?? '07:00';
            $h_ci = $config['hora_cierre_lun_vie'] ?? '20:30';
        }
        if ($hora_actual < $h_ap || $hora_actual > $h_ci) return ['permitido' => false, 'mensaje' => $config['mensaje_fuera_horario'] ?? 'Fuera de horario'];
        return ['permitido' => true];
    }

    function checkAforo($pdo, $config) {
        if (($config['control_aforo_activo'] ?? '0') !== '1') return ['permitido' => true];
        $cap_max = intval($config['capacidad_biblioteca'] ?? 500);
        $sql = "SELECT (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Entrada' AND fecha = CURDATE()) -
                       (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Salida' AND fecha = CURDATE()) AS aforo";
        $stmt = $pdo->query($sql);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $aforo = max(0, intval($res['aforo']));
        if ($aforo >= $cap_max) return ['permitido' => false, 'mensaje' => $config['mensaje_aforo_lleno'] ?? 'Aforo lleno'];
        return ['permitido' => true];
    }

    $val_horario = checkHorario($config_sistema);
    $val_aforo = checkAforo($pdo, $config_sistema);

    // Buscar usuario
    $sql_u = "SELECT u.* FROM usuarios u WHERE u.dni = :dni";
    $stmt = $pdo->prepare($sql_u);
    $stmt->execute([':dni' => $dni_buscado]);
    $usuario_bd = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario_bd) {
        $id_usuario = $usuario_bd['id_usuario'];

        if ($usuario_bd['id_estado'] != 1) {
            echo json_encode(['success' => false, 'type' => 'error', 'message' => 'CUENTA BLOQUEADA. Acércate a administración.']);
            // Registrar alerta
            $stmt_al = $pdo->prepare("INSERT INTO alertas_asistencia (id_usuario, dni, mensaje, fecha, hora) VALUES (?, ?, 'Intento acceso bloqueado', CURDATE(), CURTIME())");
            $stmt_al->execute([$id_usuario, $dni_buscado]);
            exit();
        }

        if (!$val_horario['permitido']) {
            echo json_encode(['success' => false, 'type' => 'warning', 'message' => $val_horario['mensaje']]);
            exit();
        }

        // Determinar tipo_registro
        $stmt_ult = $pdo->prepare("SELECT tipo_registro, fecha, hora FROM asistencias WHERE id_usuario = ? ORDER BY id_asistencia DESC LIMIT 1");
        $stmt_ult->execute([$id_usuario]);
        $ultimo = $stmt_ult->fetch(PDO::FETCH_ASSOC);

        if (!$val_aforo['permitido']) {
            if (!$ultimo || $ultimo['tipo_registro'] == 'Salida') {
                echo json_encode(['success' => false, 'type' => 'warning', 'message' => $val_aforo['mensaje']]);
                exit();
            }
        }

        $fecha_hoy = date('Y-m-d');
        $hora_act = date('H:i:s');
        $tipo_reg = 'Entrada';
        $H_CIERRE = '20:30:00';

        if ($ultimo) {
            $dt_u = new DateTime($ultimo['fecha'] . ' ' . $ultimo['hora']);
            $dt_a = new DateTime();
            $diff = $dt_a->getTimestamp() - $dt_u->getTimestamp();
            $reentrada_espera = intval($config_sistema['asistencia_reentrada_segundos'] ?? 30);
            
            if ($diff < $reentrada_espera && $diff >= 0) {
                echo json_encode(['success' => false, 'type' => 'warning', 'message' => "Ya registraste. Espera " . ($reentrada_espera - $diff) . " s."]);
                exit();
            }

            if (($config_sistema['asistencia_modo_salida'] ?? '1') === '0') {
                $tipo_reg = 'Entrada';
            } else {
                $mismo_dia = ($ultimo['fecha'] == $fecha_hoy);
                if ($ultimo['tipo_registro'] == 'Entrada') {
                    if (!$mismo_dia || ($ultimo['hora'] < $H_CIERRE && $hora_act >= $H_CIERRE) || ($diff / 3600 >= 4)) {
                        $tipo_reg = 'Entrada';
                    } else {
                        $tipo_reg = 'Salida';
                    }
                } else {
                    $tipo_reg = 'Entrada';
                }
            }
        }

        $stmt_ins = $pdo->prepare("INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro) VALUES (?, ?, CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')");
        $stmt_ins->execute([$id_usuario, $tipo_reg]);

        echo json_encode([
            'success' => true,
            'type' => 'success',
            'message' => ($tipo_reg == 'Entrada' ? ($config_sistema['mensaje_bienvenida'] ?? '¡Bienvenido!') : ($config_sistema['mensaje_despedida'] ?? '¡Hasta pronto!')),
            'userData' => [
                'nombre_completo' => $usuario_bd['apellidos'] . ', ' . $usuario_bd['nombres'],
                'tipo_registro' => $tipo_reg,
                'fecha_hora' => date('d/m/Y H:i:s')
            ]
        ]);
    } else {
        // API externa
        $resp = obtenerDatosAPI($dni_buscado);
        if (isset($resp['datos'])) {
            $d = $resp['datos'];
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombres, apellidos, dni, genero, id_tipo_usuario, id_estado, fecha_registro, fecha_fin_registro, usuario_creacion) 
                                   VALUES (?, ?, ?, 'M', 1, 1, CURDATE(), CONCAT(YEAR(CURDATE()), '-12-30'), 'SISTEMA_PUBLICO')");
            $stmt->execute([$d['Nombres'], $d['Paterno'].' '.$d['Materno'], $d['Nro_Doc']]);
            $id_u = $pdo->lastInsertId();
            
            // Facultades/Escuelas simplified for this context
            $stmt_f = $pdo->prepare("SELECT id_facultad FROM facultades WHERE nombre_facultad = ?");
            $stmt_f->execute([$d['Facultad']]);
            $id_f = $stmt_f->fetchColumn() ?: null;
            
            $stmt_e = $pdo->prepare("SELECT id_escuela FROM escuelas WHERE nombre_escuela = ?");
            $stmt_e->execute([$d['Escuela']]);
            $id_esc = $stmt_e->fetchColumn() ?: null;

            $stmt_est = $pdo->prepare("INSERT INTO estudiantes_unheval (id_usuario, codigo_universitario, id_facultad, id_escuela, nivel_academico, anio_estudio) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_est->execute([$id_u, $d['Codigo']??'', $id_f, $id_esc, $d['Niv_Acad'], $d['anio_estudio']??'']);
            
            // --- NUEVO: Registro de asistencia inmediata ---
            $tipo_reg = 'Entrada';
            $stmt_ins = $pdo->prepare("INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro) VALUES (?, ?, CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')");
            $stmt_ins->execute([$id_u, $tipo_reg]);
            // ----------------------------------------------

            $pdo->commit();

            $msg_bienvenida = ($config_sistema['mensaje_bienvenida'] ?? '¡Bienvenido!');
            echo json_encode([
                'success' => true,
                'type' => 'success',
                'message' => '¡Registro exitoso! ' . $msg_bienvenida,
                'userData' => [
                    'nombre_completo' => $d['Paterno'].' '.$d['Materno'].', '.$d['Nombres'],
                    'tipo_registro' => $tipo_reg,
                    'fecha_hora' => date('d/m/Y H:i:s')
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'type' => 'info', 'message' => 'DNI no encontrado. Regístrate en administración.']);
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'type' => 'error', 'message' => 'Error: '.$e->getMessage()]);
}
