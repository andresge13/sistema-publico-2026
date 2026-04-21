<?php
session_start();
header('Content-Type: application/json');
include_once '../modelo/conexion.php';
include_once(__DIR__ . '/datos_estudiantes.php');

$conexion = new Conexion();
$pdo = $conexion->pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['dni'])) {
    echo json_encode(['success' => false, 'message' => 'Identificación no proporcionada']);
    exit();
}

$dni_buscado = trim($_POST['dni']); // Puede ser DNI o Código Universitario
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

    // Buscar usuario por DNI o Código Universitario
    $sql_u = "SELECT u.* 
              FROM usuarios u 
              LEFT JOIN estudiantes_unheval e ON u.id_usuario = e.id_usuario 
              WHERE u.dni = :identificador1 OR e.codigo_universitario = :identificador2";
    $stmt = $pdo->prepare($sql_u);
    $stmt->execute([':identificador1' => $dni_buscado, ':identificador2' => $dni_buscado]);
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
            $dni_api = $d['Nro_Doc'];
            $codigo_u = $d['Id_Alumno'] ?? '';

            // Verificar si ya existe un usuario con el DNI devuelto por la API
            $stmt_chk = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE dni = ?");
            $stmt_chk->execute([$dni_api]);
            $id_existente = $stmt_chk->fetchColumn();

            $pdo->beginTransaction();

            if ($id_existente) {
                // El usuario ya existe (registrado por DNI). Solo vinculamos el código universitario si falta.
                $id_u = $id_existente;
                $stmt_chk_est = $pdo->prepare("SELECT id FROM estudiantes_unheval WHERE id_usuario = ?");
                $stmt_chk_est->execute([$id_u]);
                if (!$stmt_chk_est->fetchColumn()) {
                    // Insertar registro en estudiantes_unheval
                    $stmt_f = $pdo->prepare("SELECT id_facultad FROM facultades WHERE nombre_facultad = ?");
                    $stmt_f->execute([$d['Facultad']]);
                    $id_f = $stmt_f->fetchColumn() ?: null;
                    if (!$id_f && !empty($d['Facultad'])) {
                        $pdo->prepare("INSERT INTO facultades (nombre_facultad) VALUES (?)")->execute([$d['Facultad']]);
                        $id_f = $pdo->lastInsertId();
                    }
                    $stmt_e = $pdo->prepare("SELECT id_escuela FROM escuelas WHERE nombre_escuela = ?");
                    $stmt_e->execute([$d['Escuela']]);
                    $id_esc = $stmt_e->fetchColumn() ?: null;
                    if (!$id_esc && !empty($d['Escuela']) && $id_f) {
                        $pdo->prepare("INSERT INTO escuelas (id_facultad, nombre_escuela) VALUES (?, ?)")->execute([$id_f, $d['Escuela']]);
                        $id_esc = $pdo->lastInsertId();
                    }
                    $pdo->prepare("INSERT INTO estudiantes_unheval (id_usuario, codigo_universitario, id_facultad, id_escuela, nivel_academico, anio_estudio) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$id_u, $codigo_u, $id_f, $id_esc, $d['Niv_Acad'], $d['anio_estudio']??'']);
                } else {
                    // Ya tiene registro, actualizar código universitario si está vacío
                    $pdo->prepare("UPDATE estudiantes_unheval SET codigo_universitario = ? WHERE id_usuario = ? AND (codigo_universitario IS NULL OR codigo_universitario = '')")
                        ->execute([$codigo_u, $id_u]);
                }

                // Registrar asistencia para el usuario existente (usando la misma lógica de re-entrada)
                $stmt_ult = $pdo->prepare("SELECT tipo_registro, fecha, hora FROM asistencias WHERE id_usuario = ? ORDER BY id_asistencia DESC LIMIT 1");
                $stmt_ult->execute([$id_u]);
                $ultimo = $stmt_ult->fetch(PDO::FETCH_ASSOC);

                $fecha_hoy = date('Y-m-d');
                $hora_act  = date('H:i:s');
                $tipo_reg  = 'Entrada';
                $H_CIERRE  = '20:30:00';
                $reentrada_espera = intval($config_sistema['asistencia_reentrada_segundos'] ?? 30);

                if ($ultimo) {
                    $diff = (new DateTime())->getTimestamp() - (new DateTime($ultimo['fecha'].' '.$ultimo['hora']))->getTimestamp();
                    if ($diff < $reentrada_espera && $diff >= 0) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'type' => 'warning', 'message' => "Ya registraste. Espera " . ($reentrada_espera - $diff) . " s."]);
                        exit();
                    }
                    if (($config_sistema['asistencia_modo_salida'] ?? '1') !== '0') {
                        $mismo_dia = ($ultimo['fecha'] == $fecha_hoy);
                        $diff_h = $diff / 3600;
                        if ($ultimo['tipo_registro'] == 'Entrada' && $mismo_dia && !($ultimo['hora'] < $H_CIERRE && $hora_act >= $H_CIERRE) && $diff_h < 4) {
                            $tipo_reg = 'Salida';
                        }
                    }
                }

                $pdo->prepare("INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro) VALUES (?, ?, CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')")
                    ->execute([$id_u, $tipo_reg]);
                $pdo->commit();

                $stmt_u = $pdo->prepare("SELECT apellidos, nombres FROM usuarios WHERE id_usuario = ?");
                $stmt_u->execute([$id_u]);
                $u = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $msg = $tipo_reg == 'Entrada' ? ($config_sistema['mensaje_bienvenida'] ?? '¡Bienvenido!') : ($config_sistema['mensaje_despedida'] ?? '¡Hasta pronto!');
                echo json_encode([
                    'success' => true, 'type' => 'success', 'message' => $msg,
                    'userData' => ['nombre_completo' => $u['apellidos'].', '.$u['nombres'], 'tipo_registro' => $tipo_reg, 'fecha_hora' => date('d/m/Y H:i:s')]
                ]);
            } else {
                // Usuario totalmente nuevo: insertar y registrar asistencia
                $stmt_ins_u = $pdo->prepare("INSERT INTO usuarios (nombres, apellidos, dni, genero, id_tipo_usuario, id_estado, fecha_registro, fecha_fin_registro, usuario_creacion) 
                                             VALUES (?, ?, ?, 'M', 1, 1, CURDATE(), CONCAT(YEAR(CURDATE()), '-12-30'), 'SISTEMA_PUBLICO')");
                $stmt_ins_u->execute([$d['Nombres'], $d['Paterno'].' '.$d['Materno'], $dni_api]);
                $id_u = $pdo->lastInsertId();

                $stmt_f = $pdo->prepare("SELECT id_facultad FROM facultades WHERE nombre_facultad = ?");
                $stmt_f->execute([$d['Facultad']]);
                $id_f = $stmt_f->fetchColumn() ?: null;
                if (!$id_f && !empty($d['Facultad'])) {
                    $pdo->prepare("INSERT INTO facultades (nombre_facultad) VALUES (?)")->execute([$d['Facultad']]);
                    $id_f = $pdo->lastInsertId();
                }
                $stmt_e = $pdo->prepare("SELECT id_escuela FROM escuelas WHERE nombre_escuela = ?");
                $stmt_e->execute([$d['Escuela']]);
                $id_esc = $stmt_e->fetchColumn() ?: null;
                if (!$id_esc && !empty($d['Escuela']) && $id_f) {
                    $pdo->prepare("INSERT INTO escuelas (id_facultad, nombre_escuela) VALUES (?, ?)")->execute([$id_f, $d['Escuela']]);
                    $id_esc = $pdo->lastInsertId();
                }

                $pdo->prepare("INSERT INTO estudiantes_unheval (id_usuario, codigo_universitario, id_facultad, id_escuela, nivel_academico, anio_estudio) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$id_u, $codigo_u, $id_f, $id_esc, $d['Niv_Acad'], $d['anio_estudio']??'']);

                $pdo->prepare("INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro) VALUES (?, 'Entrada', CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')")
                    ->execute([$id_u]);
                $pdo->commit();

                $msg_bienvenida = ($config_sistema['mensaje_bienvenida'] ?? '¡Bienvenido!');
                echo json_encode([
                    'success' => true, 'type' => 'success', 'message' => '¡Registro exitoso! ' . $msg_bienvenida,
                    'userData' => ['nombre_completo' => $d['Paterno'].' '.$d['Materno'].', '.$d['Nombres'], 'tipo_registro' => 'Entrada', 'fecha_hora' => date('d/m/Y H:i:s')]
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'type' => 'info', 'message' => 'Usuario no encontrado. Regístrate en administración.']);
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'type' => 'error', 'message' => 'Error: '.$e->getMessage()]);
}
