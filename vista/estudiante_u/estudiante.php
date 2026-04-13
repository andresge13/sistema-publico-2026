<?php
session_start();
include_once '../../modelo/conexion.php';
include_once(__DIR__ . '/../../api/datos_estudiantes.php');

$conexion = new Conexion();
$pdo = $conexion->pdo;

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

// Leer configuración del sistema desde la base de datos
$modo_kiosko = false;
$config_sistema = [];
try {
  $stmt_config = $pdo->query("SELECT clave, valor FROM configuracion_sistema");
  if ($stmt_config) {
    while ($row = $stmt_config->fetch(PDO::FETCH_ASSOC)) {
      $config_sistema[$row['clave']] = $row['valor'];
    }
    $modo_kiosko = ($config_sistema['modo_kiosko'] ?? '0') === '1';
  }
} catch (Exception $e) {
  // Si la tabla no existe, usar valores por defecto
}

// ============================================
// FUNCIONES DE VALIDACIÓN
// ============================================

/**
 * Verifica si la biblioteca está dentro del horario de atención
 */
function verificarHorarioAtencion($config)
{
  if (($config['horario_atencion_activo'] ?? '0') !== '1') {
    return ['permitido' => true, 'mensaje' => ''];
  }

  $dia_semana = date('w'); // 0=Dom, 1=Lun, 2=Mar, ... 6=Sab
  $hora_actual = date('H:i');

  // Domingo
  if ($dia_semana == 0) {
    if (($config['atencion_domingo'] ?? '0') !== '1') {
      return [
        'permitido' => false,
        'mensaje' => $config['mensaje_fuera_horario'] ?? 'La biblioteca está cerrada los domingos.'
      ];
    }
    return ['permitido' => true, 'mensaje' => ''];
  }

  // Sábado
  if ($dia_semana == 6) {
    $hora_apertura = $config['hora_apertura_sabado'] ?? '08:00';
    $hora_cierre = $config['hora_cierre_sabado'] ?? '13:00';
  } else {
    // Lunes a Viernes
    $hora_apertura = $config['hora_apertura_lun_vie'] ?? '07:00';
    $hora_cierre = $config['hora_cierre_lun_vie'] ?? '20:30';
  }

  if ($hora_actual < $hora_apertura || $hora_actual > $hora_cierre) {
    return [
      'permitido' => false,
      'mensaje' => $config['mensaje_fuera_horario'] ?? 'La biblioteca está cerrada en este momento.'
    ];
  }

  return ['permitido' => true, 'mensaje' => ''];
}

/**
 * Verifica si hay capacidad disponible en la biblioteca
 */
function verificarAforo($pdo, $config)
{
  if (($config['control_aforo_activo'] ?? '0') !== '1') {
    return ['permitido' => true, 'mensaje' => '', 'aforo_actual' => 0];
  }

  $capacidad_maxima = intval($config['capacidad_biblioteca'] ?? 500);

  // Calcular aforo actual
  $sql = "SELECT 
            (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Entrada' AND fecha = CURDATE()) -
            (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Salida' AND fecha = CURDATE()) 
          AS aforo_actual";
  $stmt = $pdo->query($sql);
  $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
  $aforo_actual = max(0, intval($resultado['aforo_actual']));

  if ($aforo_actual >= $capacidad_maxima) {
    return [
      'permitido' => false,
      'mensaje' => $config['mensaje_aforo_lleno'] ?? 'La biblioteca ha alcanzado su capacidad máxima.',
      'aforo_actual' => $aforo_actual
    ];
  }

  return ['permitido' => true, 'mensaje' => '', 'aforo_actual' => $aforo_actual];
}

// Variables para mostrar notificaciones en pantalla
$notificacion_horario = verificarHorarioAtencion($config_sistema);
$notificacion_aforo = verificarAforo($pdo, $config_sistema);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['dni'])) {
  $dni_buscado = trim($_POST['dni']);
  $mensaje = '';
  $tipo_mensaje = '';
  $datos_usuario = null;

  // Buscar usuario en la base de datos
  $sql_usuario = "SELECT u.*, esc.nombre_escuela AS escuela, ue.institucion_procedencia
                    FROM usuarios u
                    LEFT JOIN estudiantes_unheval eu ON u.id_usuario = eu.id_usuario
                    LEFT JOIN escuelas esc ON eu.id_escuela = esc.id_escuela
                    LEFT JOIN usuarios_externos ue ON u.id_usuario = ue.id_usuario
                    WHERE u.dni = :dni";

  $stmt = $pdo->prepare($sql_usuario);
  $stmt->execute([':dni' => $dni_buscado]);
  $usuario_bd = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($usuario_bd) {
    $id_usuario = $usuario_bd['id_usuario'];

    // ==================== PUNTO 6: VERIFICAR SI ESTÁ BLOQUEADO/SANCIONADO ====================
    if ($usuario_bd['id_estado'] != 1) {
      $tipo_mensaje = 'error';
      $mensaje = 'TU CUENTA ESTÁ BLOQUEADA. Por favor, acércate a la oficina de administración de la biblioteca.';

      // Registrar alerta para el administrador
      $sql_alerta = "INSERT INTO alertas_asistencia (id_usuario, dni, mensaje, fecha, hora) 
                     VALUES (:id, :dni, 'Intento de acceso - Usuario bloqueado/sancionado', CURDATE(), CURTIME())";
      $stmt_alerta = $pdo->prepare($sql_alerta);
      $stmt_alerta->execute([':id' => $id_usuario, ':dni' => $dni_buscado]);

      // ==================== VALIDAR HORARIO DE ATENCIÓN ====================
    } elseif (!$notificacion_horario['permitido']) {
      $tipo_mensaje = 'warning';
      $mensaje = $notificacion_horario['mensaje'];

      // ==================== VALIDAR AFORO DISPONIBLE (solo para entradas) ====================
    } elseif (!$notificacion_aforo['permitido']) {
      // Verificar si el usuario va a marcar entrada o salida
      $sql_check = "SELECT tipo_registro FROM asistencias WHERE id_usuario = :id ORDER BY id_asistencia DESC LIMIT 1";
      $stmt_check = $pdo->prepare($sql_check);
      $stmt_check->execute([':id' => $id_usuario]);
      $ultimo_check = $stmt_check->fetch(PDO::FETCH_ASSOC);

      // Solo bloquear si es una ENTRADA (salidas siempre permitidas)
      if (!$ultimo_check || $ultimo_check['tipo_registro'] == 'Salida') {
        $tipo_mensaje = 'warning';
        $mensaje = $notificacion_aforo['mensaje'];
      } else {
        // Es una salida, continuar normalmente
        goto procesar_asistencia;
      }
    } else {
      procesar_asistencia:
      // =====================================================
      // LÓGICA DE REGISTRO DE ASISTENCIA (ENTRADA/SALIDA)
      // CON MANEJO INTELIGENTE DE OLVIDO DE SALIDA
      // =====================================================

      // Obtener el último registro de asistencia de este usuario
      $sql_ultimo = "SELECT id_asistencia, tipo_registro, fecha, hora
                     FROM asistencias 
                     WHERE id_usuario = :id_usuario 
                     ORDER BY id_asistencia DESC 
                     LIMIT 1";
      $stmt_ultimo = $pdo->prepare($sql_ultimo);
      $stmt_ultimo->execute([':id_usuario' => $id_usuario]);
      $ultimo = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);

      $fecha_hoy = date('Y-m-d');
      $hora_actual = date('H:i:s');
      $puede_registrar = true;
      $tipo_registro = 'Entrada'; // Por defecto es Entrada

      // Constantes de configuración
      $HORA_CIERRE_AUTOMATICO = '20:30:00'; // 8:30 PM - cierre automático de sesiones
      $TIEMPO_MAXIMO_SESION_HORAS = 4; // 4 horas máximo de sesión activa

      if ($ultimo) {
        // Calcular segundos desde el último registro
        $datetime_ultimo = new DateTime($ultimo['fecha'] . ' ' . $ultimo['hora']);
        $datetime_ahora = new DateTime();
        $diferencia_seg = $datetime_ahora->getTimestamp() - $datetime_ultimo->getTimestamp();
        $diferencia_horas = $diferencia_seg / 3600;

        // BLOQUEO ANTI-SPAM: Si pasaron menos de 30 segundos, no permitir nuevo registro
        if ($diferencia_seg < 30 && $diferencia_seg >= 0) {
          $puede_registrar = false;
          $tipo_mensaje = 'warning';
          $falta = 30 - $diferencia_seg;
          $mensaje = "Ya registraste tu asistencia. Espera {$falta} segundos.";
        } else {
          // ===== LÓGICA DE DETERMINACIÓN ENTRADA/SALIDA =====
          
          // Punto clave: Verificar si el modo salida está activado en configuración
          if (($config_sistema['asistencia_modo_salida'] ?? '1') === '0') {
            // MODO SOLO INGRESO ACTIVADO: Siempre será entrada
            $tipo_registro = 'Entrada';
          } else {
            // MODO NORMAL: Alternar entre Entrada y Salida
            $es_mismo_dia = ($ultimo['fecha'] == $fecha_hoy);
            $ultimo_fue_entrada = ($ultimo['tipo_registro'] == 'Entrada');

            if ($ultimo_fue_entrada) {
              // El último registro fue ENTRADA

              if (!$es_mismo_dia) {
                // CASO 1: Entrada de día anterior → El usuario olvidó marcar salida ayer
                // Permitir nueva ENTRADA (el sistema asume que ya salió y no marcó)
                $tipo_registro = 'Entrada';
              } elseif ($ultimo['hora'] < $HORA_CIERRE_AUTOMATICO && date('H:i:s') >= $HORA_CIERRE_AUTOMATICO) {
                // CASO 2: Entrada del mismo día pero ya pasó la hora de cierre (8:30 PM)
                // Si entró antes de las 8:30 y ahora son después de las 8:30, permitir nueva entrada
                $tipo_registro = 'Entrada';
              } elseif ($diferencia_horas >= $TIEMPO_MAXIMO_SESION_HORAS) {
                // CASO 3: Entrada del mismo día pero más de 4 horas atrás
                // El usuario probablemente olvidó marcar salida y vuelve a entrar
                $tipo_registro = 'Entrada';
              } else {
                // CASO NORMAL: Entrada reciente del mismo día → Espera SALIDA
                $tipo_registro = 'Salida';
              }
            } else {
              // El último registro fue SALIDA → Ahora toca ENTRADA
              $tipo_registro = 'Entrada';
            }
          }
        }
      }
      // Si no hay registro previo, será Entrada (ya está por defecto)

      if ($puede_registrar) {
        try {
          $sql_insert = "INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro)
                         VALUES (:id_usuario, :tipo_registro, :fecha, :hora, 'SISTEMA_PUBLICO')";
          $stmt_insert = $pdo->prepare($sql_insert);
          $stmt_insert->execute([
            ':id_usuario' => $id_usuario,
            ':tipo_registro' => $tipo_registro,
            ':fecha' => $fecha_hoy,
            ':hora' => $hora_actual
          ]);

          $tipo_mensaje = 'success';
          if ($tipo_registro === 'Entrada') {
            $mensaje = '¡Bienvenido a la Biblioteca!';
          } else {
            $mensaje = '¡Hasta pronto! Gracias por tu visita.';
          }

          $datos_usuario = [
            'nombre_completo' => $usuario_bd['apellidos'] . ', ' . $usuario_bd['nombres'],
            'tipo_registro' => $tipo_registro,
            'fecha_hora' => date('d/m/Y H:i:s')
          ];
        } catch (PDOException $e) {
          $tipo_mensaje = 'error';
          $mensaje = 'Error al registrar asistencia: ' . $e->getMessage();
        }
      }
    }
  } else {
    // Buscar en API externa
    $respuesta = obtenerDatosAPI($dni_buscado);
    if (isset($respuesta['datos'])) {
      $datos = $respuesta['datos'];
      try {
        $pdo->beginTransaction();

        // Insertar usuario (SOLO registrar, NO marcar asistencia)
        // Se establece por defecto el 30 de diciembre del año actual para estudiantes UNHEVAL
        $sql = "INSERT INTO usuarios (nombres, apellidos, dni, genero, id_tipo_usuario, id_estado, fecha_registro, fecha_fin_registro, usuario_creacion)
                VALUES (:nombres, :apellidos, :dni, 'M', 1, 1, CURDATE(), CONCAT(YEAR(CURDATE()), '-12-30'), 'SISTEMA_PUBLICO')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':nombres' => $datos['Nombres'],
          ':apellidos' => $datos['Paterno'] . ' ' . $datos['Materno'],
          ':dni' => $datos['Nro_Doc']
        ]);
        $id_usuario = $pdo->lastInsertId();

        // 1. Obtener o crear ID de Facultad
        $stmt_f = $pdo->prepare("SELECT id_facultad FROM facultades WHERE nombre_facultad = ?");
        $stmt_f->execute([$datos['Facultad']]);
        $id_facultad = $stmt_f->fetchColumn();
        if (!$id_facultad && !empty($datos['Facultad'])) {
          $stmt_fi = $pdo->prepare("INSERT INTO facultades (nombre_facultad) VALUES (?)");
          $stmt_fi->execute([$datos['Facultad']]);
          $id_facultad = $pdo->lastInsertId();
        }

        // 2. Obtener o crear ID de Escuela
        $stmt_e = $pdo->prepare("SELECT id_escuela FROM escuelas WHERE nombre_escuela = ? AND id_facultad = ?");
        $stmt_e->execute([$datos['Escuela'], $id_facultad]);
        $id_escuela = $stmt_e->fetchColumn();
        if (!$id_escuela && !empty($datos['Escuela']) && $id_facultad) {
          $stmt_ei = $pdo->prepare("INSERT INTO escuelas (id_facultad, nombre_escuela) VALUES (?, ?)");
          $stmt_ei->execute([$id_facultad, $datos['Escuela']]);
          $id_escuela = $pdo->lastInsertId();
        }

        // 3. Insertar datos de estudiante
        $sql = "INSERT INTO estudiantes_unheval (id_usuario, codigo_universitario, id_facultad, id_escuela, nivel_academico, anio_estudio)
                VALUES (:id_usuario, :codigo, :id_facultad, :id_escuela, :nivel, :anio)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':id_usuario' => $id_usuario,
          ':codigo' => $datos['Codigo'] ?? '',
          ':id_facultad' => $id_facultad,
          ':id_escuela' => $id_escuela,
          ':nivel' => $datos['Niv_Acad'],
          ':anio' => $datos['anio_estudio'] ?? ''
        ]);

        // NO registrar asistencia automáticamente al crear el usuario
        // El estudiante debe pasar su carnet nuevamente para registrar su primera entrada

        $pdo->commit();

        $tipo_mensaje = 'success';
        $mensaje = '¡Te hemos registrado en el sistema! Pasa tu DNI nuevamente para marcar tu entrada.';
        $datos_usuario = [
          'nombre_completo' => $datos['Paterno'] . ' ' . $datos['Materno'] . ', ' . $datos['Nombres'],
          'tipo_registro' => 'Registro',
          'fecha_hora' => date('d/m/Y H:i:s')
        ];
      } catch (PDOException $e) {
        $pdo->rollBack();
        $tipo_mensaje = 'error';
        $mensaje = 'Error al registrar. Por favor, acércate a administración.';
      }
    } else {
      $tipo_mensaje = 'info';
      $mensaje = 'DNI no encontrado. Por favor, regístrate en la oficina de administración.';
    }
  }

  $_SESSION['mensaje'] = $mensaje;
  $_SESSION['tipo_mensaje'] = $tipo_mensaje;
  $_SESSION['datos_usuario'] = $datos_usuario;
  header("Location: estudiante.php");
  exit();
}

$mensaje = $_SESSION['mensaje'] ?? '';
$tipo_mensaje = $_SESSION['tipo_mensaje'] ?? '';
$datos_usuario = $_SESSION['datos_usuario'] ?? null;
unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje'], $_SESSION['datos_usuario']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Control de Asistencia - Biblioteca UNHEVAL</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --primary-color: #0c2340;
      --secondary-color: #c5a059;
      --card-bg: rgba(255, 255, 255, 0.98);
      --text-color: #2d3748;
    }

    html,
    body {
      height: 100%;
      margin: 0;
      overflow: hidden;
      font-family: 'Outfit', sans-serif;
    }

    .bg-animated {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(rgba(12, 35, 64, 0.5), rgba(12, 35, 64, 0.5)), url('../../img/biblioteca.png');
      background-size: cover;
      background-position: center;
      z-index: -2;
      animation: panLeft 60s linear infinite alternate;
      filter: brightness(0.9);
    }

    @keyframes panLeft {
      from {
        transform: scale(1.1) translateX(0);
      }

      to {
        transform: scale(1.2) translateX(-30px);
      }
    }

    .main-wrapper {
      height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 15px;
    }

    .content-box {
      width: 100%;
      max-width: 900px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    #liveClock {
      font-size: 4.2rem;
      /* Reducido de 5.5rem */
      font-weight: 700;
      color: #fff;
      line-height: 1;
      text-shadow: 0 5px 15px rgba(0, 0, 0, 0.7);
      text-align: center;
      margin-top: 20px;
    }

    #liveDate {
      color: var(--secondary-color);
      font-size: 1.4rem;
      font-weight: 500;
      text-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
      text-align: center;
      margin-bottom: 15px;
    }

    .uni-header {
      background: var(--card-bg);
      border-radius: 18px;
      padding: 12px 25px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      border-bottom: 5px solid var(--secondary-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .logo-img {
      height: 70px;
    }

    .header-text h1 {
      font-size: 20px;
      /* Reducido de 24px */
      font-weight: 800;
      color: var(--primary-color);
      margin: 0;
      text-transform: uppercase;
    }

    .header-text h2 {
      font-size: 16px;
      /* Aumentado de 14px */
      font-weight: 700;
      color: var(--text-color);
      margin: 0;
      display: inline-block;
    }

    .header-text .subtitle {
      margin-left: 5px;
      color: var(--secondary-color);
      font-weight: 600;
      font-size: 14px;
      /* Aumentado de 13px */
      letter-spacing: 0.5px;
    }

    .register-card {
      background: var(--card-bg);
      border-radius: 22px;
      padding: 25px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
      text-align: center;
    }

    .instruction-text {
      font-size: 16px;
      color: #555;
      margin-bottom: 15px;
      font-weight: 500;
    }

    .dni-input {
      font-size: 42px;
      text-align: center;
      padding: 12px;
      border: 4px solid #cbd5e0;
      border-radius: 18px;
      font-weight: 700;
      letter-spacing: 10px;
      color: var(--primary-color);
      background: #edf2f7;
      max-width: 480px;
      margin: 0 auto;
      transition: all 0.3s ease;
    }

    .dni-input:focus {
      border-color: var(--secondary-color);
      background: #fff;
      box-shadow: 0 0 20px rgba(197, 160, 89, 0.3);
      outline: none;
    }

    .user-feedback {
      margin-top: 20px;
      padding: 18px;
      background: rgba(16, 185, 129, 0.1);
      border: 2px solid #10B981;
      border-radius: 16px;
      animation: zoomIn 0.3s ease-out;
    }

    .user-feedback.error {
      background: rgba(220, 53, 69, 0.1);
      border-color: #dc3545;
    }

    .success-badge {
      color: #10B981;
      font-weight: 800;
      font-size: 18px;
      text-transform: uppercase;
      margin-bottom: 5px;
    }

    .user-name {
      font-size: 22px;
      font-weight: 700;
      color: var(--text-color);
    }

    .footer {
      text-align: center;
      color: rgba(255, 255, 255, 0.8);
      font-size: 11px;
      margin-top: 10px;
    }

    @keyframes zoomIn {
      from {
        transform: scale(0.95);
        opacity: 0;
      }

      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    /* ========== RESPONSIVIDAD ========== */
    @media (max-width: 991.98px) {
      #liveClock {
        font-size: 4rem;
      }

      #liveDate {
        font-size: 1.2rem;
      }

      .header-text h1 {
        font-size: 20px;
      }

      .logo-img {
        height: 55px;
      }

      .dni-input {
        font-size: 32px;
        letter-spacing: 6px;
        max-width: 400px;
      }
    }

    @media (max-width: 767.98px) {
      .main-wrapper {
        padding: 10px;
      }

      #liveClock {
        font-size: 3rem;
      }

      #liveDate {
        font-size: 1rem;
        margin-bottom: 10px;
      }

      .uni-header {
        padding: 10px 15px;
        flex-direction: column;
        text-align: center;
      }

      .logo-img {
        height: 45px;
        margin-bottom: 8px;
      }

      .header-text h1 {
        font-size: 16px;
      }

      .header-text p {
        font-size: 11px;
      }

      .register-card {
        padding: 15px;
      }

      .instruction-text {
        font-size: 14px;
      }

      .dni-input {
        font-size: 24px;
        letter-spacing: 4px;
        padding: 10px;
        max-width: 100%;
      }

      .user-feedback {
        padding: 12px;
      }

      .success-badge {
        font-size: 15px;
      }

      .user-name {
        font-size: 18px;
      }
    }

    @media (max-width: 575.98px) {
      #liveClock {
        font-size: 2.5rem;
      }

      .logo-img:first-child {
        display: none;
      }

      .header-text h1 {
        font-size: 14px;
      }

      .dni-input {
        font-size: 20px;
        letter-spacing: 3px;
      }
    }
  </style>
</head>

<body>
  <div class="bg-animated"></div>
  <div class="main-wrapper">
    <div class="content-box">
      <div id="liveClock">00:00:00</div>
      <div id="liveDate">Cargando...</div>

      <header class="uni-header text-center">
        <img src="../../img/inicio_biblio.png" alt="Biblio" class="logo-img">
        <div class="header-text">
          <div style="font-size: 15px; color: #718096; font-weight: 600;">Universidad Nacional Hermilio Valdizán</div>
          <h1>SISTEMA DE CONTROL DE ASISTENCIA - UNHEVAL</h1>
          <div class="mt-1">
            <h2>BIBLIOTECA CENTRAL</h2>
            <span class="subtitle">"Javier Pulgar Vidal"</span>
          </div>
        </div>
        <img src="../../img/unheval - copia.png" alt="UNHEVAL" class="logo-img">
      </header>

      <?php if (!$notificacion_horario['permitido']): ?>
        <!-- Banner de Biblioteca Cerrada -->
        <div style="background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); color: white; padding: 15px 25px; border-radius: 12px; margin: 15px 0; text-align: center; box-shadow: 0 5px 20px rgba(239, 68, 68, 0.4);">
          <i class="fas fa-clock fa-2x" style="margin-bottom: 10px;"></i>
          <h3 style="margin: 0; font-weight: 700;">BIBLIOTECA CERRADA</h3>
          <p style="margin: 10px 0 0 0; opacity: 0.9;"><?= htmlspecialchars($notificacion_horario['mensaje']) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($notificacion_horario['permitido'] && !$notificacion_aforo['permitido']): ?>
        <!-- Banner de Aforo Lleno -->
        <div style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: white; padding: 15px 25px; border-radius: 12px; margin: 15px 0; text-align: center; box-shadow: 0 5px 20px rgba(245, 158, 11, 0.4);">
          <i class="fas fa-users-slash fa-2x" style="margin-bottom: 10px;"></i>
          <h3 style="margin: 0; font-weight: 700;">AFORO COMPLETO</h3>
          <p style="margin: 10px 0 0 0; opacity: 0.9;"><?= htmlspecialchars($notificacion_aforo['mensaje']) ?></p>
          <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.8;">Si ya te encuentras dentro, puedes marcar tu salida normalmente.</p>
        </div>
      <?php endif; ?>

      <main class="register-card">
        <div class="instruction-text">
          <i class="fas fa-barcode me-2"></i> PASE SU CARNET O INGRESE SU DNI
        </div>
        <form method="POST" id="formAsistencia">
          <input type="text" class="form-control dni-input" name="dni" id="dniInput"
            placeholder="DNI" maxlength="8" pattern="[0-9]{8}" autocomplete="off" required autofocus>
        </form>

        <?php if ($datos_usuario): ?>
          <div class="user-feedback <?= $tipo_mensaje === 'error' ? 'error' : '' ?>">
            <div class="success-badge">
              <i class="fas fa-<?= $tipo_mensaje === 'success' ? 'check-circle' : ($datos_usuario['tipo_registro'] === 'Registro' ? 'user-plus' : 'exclamation-circle') ?>"></i>
              <?php
              if ($datos_usuario['tipo_registro'] === 'Entrada') echo 'BIENVENIDO';
              elseif ($datos_usuario['tipo_registro'] === 'Salida') echo 'HASTA PRONTO';
              elseif ($datos_usuario['tipo_registro'] === 'Registro') echo 'REGISTRADO';
              else echo 'AVISO';
              ?>
            </div>
            <div class="user-name"><?= htmlspecialchars($datos_usuario['nombre_completo']) ?></div>
            <div style="font-size: 14px; opacity: 0.8; margin-top: 5px;">
              <?php if ($datos_usuario['tipo_registro'] === 'Registro'): ?>
                <strong>Registrado en el sistema</strong> — Pasa tu DNI nuevamente para marcar tu entrada
              <?php else: ?>
                <strong><?= $datos_usuario['tipo_registro'] ?></strong> registrada a las <?= $datos_usuario['fecha_hora'] ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </main>

      <div class="footer">
        © <?= date('Y') ?> Dirección de Biblioteca Central • UNHEVAL • Huánuco, Perú
      </div>
    </div>
  </div>

  <script>
    function updateClock() {
      const now = new Date();
      document.getElementById('liveClock').textContent = now.toLocaleTimeString('es-ES', {
        hour12: false
      });
      const options = {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      };
      let dateStr = now.toLocaleDateString('es-ES', options);
      document.getElementById('liveDate').textContent = dateStr.charAt(0).toUpperCase() + dateStr.slice(1);
    }
    setInterval(updateClock, 1000);
    updateClock();

    const input = document.getElementById('dniInput');
    const form = document.getElementById('formAsistencia');

    // === SISTEMA DE AUTO-ENFOQUE PERMANENTE ===
    // Mantener el input siempre enfocado para escaneo de carnet
    function mantenerEnfoque() {
      if (document.activeElement !== input) {
        input.focus();
      }
    }

    // Re-enfocar inmediatamente cuando pierde el foco
    input.addEventListener('blur', function() {
      setTimeout(mantenerEnfoque, 50);
    });

    // Enfocar al hacer clic en cualquier parte de la página
    document.addEventListener('click', function(e) {
      if (e.target !== input) {
        setTimeout(mantenerEnfoque, 50);
      }
    });

    // Enfocar al tocar la pantalla (para tablets/kioscos táctiles)
    document.addEventListener('touchstart', function() {
      setTimeout(mantenerEnfoque, 50);
    });

    // Verificar enfoque cada 2 segundos como respaldo
    setInterval(mantenerEnfoque, 2000);

    // Enfoque inicial
    mantenerEnfoque();

    input.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '');
      if (this.value.length === 8) {
        form.submit();
      }
    });

    <?php if (!empty($mensaje)): ?>
      Swal.fire({
        icon: '<?= $tipo_mensaje ?>',
        title: '<?= $tipo_mensaje == "success" ? "¡Registro Exitoso!" : "Aviso" ?>',
        text: '<?= addslashes($mensaje) ?>',
        background: '#fff',
        color: '#2d3748',
        confirmButtonColor: '#0c2340',
        timer: 2000,
        timerProgressBar: true
      });
    <?php endif; ?>

    // Auto-ocultar datos del usuario después de 4 segundos
    <?php if ($datos_usuario): ?>
      setTimeout(function() {
        var feedback = document.querySelector('.user-feedback');
        if (feedback) {
          feedback.style.transition = 'opacity 0.5s ease, max-height 0.5s ease';
          feedback.style.opacity = '0';
          setTimeout(function() { feedback.style.display = 'none'; }, 500);
        }
      }, 4000);
    <?php endif; ?>

    <?php if ($modo_kiosko): ?>
      // === MODO KIOSKO ===
      // Pantalla completa automática
      function enterFullscreen() {
        const elem = document.documentElement;
        if (elem.requestFullscreen) {
          elem.requestFullscreen();
        } else if (elem.webkitRequestFullscreen) {
          elem.webkitRequestFullscreen();
        } else if (elem.msRequestFullscreen) {
          elem.msRequestFullscreen();
        }
      }

      // Intentar entrar en pantalla completa al cargar
      document.addEventListener('click', function activateFullscreen() {
        enterFullscreen();
        document.removeEventListener('click', activateFullscreen);
      }, {
        once: true
      });

      // Bloquear teclas de navegación
      document.addEventListener('keydown', function(e) {
        // Bloquear F5, F11, Ctrl+R, Ctrl+W, Alt+F4
        if (e.key === 'F5' || e.key === 'F11' ||
          (e.ctrlKey && (e.key === 'r' || e.key === 'w')) ||
          (e.altKey && e.key === 'F4')) {
          e.preventDefault();
          return false;
        }
        // Bloquear Escape
        if (e.key === 'Escape') {
          e.preventDefault();
          enterFullscreen();
          return false;
        }
      });

      // Bloquear clic derecho
      document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
      });

      // Re-entrar a pantalla completa si se sale
      document.addEventListener('fullscreenchange', function() {
        if (!document.fullscreenElement) {
          setTimeout(enterFullscreen, 100);
        }
      });

      // Mostrar indicador de modo kiosko
      console.log('%c🔒 MODO KIOSKO ACTIVO', 'background: #0c2340; color: #c5a059; font-size: 16px; padding: 10px;');
    <?php endif; ?>
  </script>
</body>

</html>