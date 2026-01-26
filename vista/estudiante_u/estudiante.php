<?php
session_start();
include_once '../../modelo/conexion.php';
include_once(__DIR__ . '/../../api/datos_estudiantes.php');

$conexion = new Conexion();
$pdo = $conexion->pdo;

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['dni'])) {
  $dni_buscado = trim($_POST['dni']);
  $mensaje = '';
  $tipo_mensaje = '';
  $datos_usuario = null;

  // Buscar usuario en la base de datos
  $sql_usuario = "SELECT u.*, eu.escuela, ue.institucion_procedencia
                    FROM usuarios u
                    LEFT JOIN estudiantes_unheval eu ON u.id_usuario = eu.id_usuario
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
    } else {
      // =====================================================
      // LÓGICA DE REGISTRO DE ASISTENCIA (ENTRADA/SALIDA)
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

      if ($ultimo) {
        // Calcular segundos desde el último registro
        $datetime_ultimo = new DateTime($ultimo['fecha'] . ' ' . $ultimo['hora']);
        $datetime_ahora = new DateTime();
        $diferencia = $datetime_ahora->getTimestamp() - $datetime_ultimo->getTimestamp();

        // BLOQUEO: Si pasaron menos de 30 segundos, no permitir nuevo registro
        if ($diferencia < 30) {
          $puede_registrar = false;
          $tipo_mensaje = 'warning';
          $falta = 30 - $diferencia;
          $mensaje = "Ya registraste tu asistencia. Espera {$falta} segundos.";
        } else {
          // ALTERNANCIA: Determinar si es Entrada o Salida
          // Si el último fue Entrada -> ahora es Salida
          // Si el último fue Salida -> ahora es Entrada
          if ($ultimo['tipo_registro'] == 'Entrada') {
            $tipo_registro = 'Salida';
          } else {
            $tipo_registro = 'Entrada';
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

        // Insertar usuario
        $sql = "INSERT INTO usuarios (nombres, apellidos, dni, genero, id_tipo_usuario, id_estado, fecha_registro, fecha_fin_registro, usuario_creacion)
                VALUES (:nombres, :apellidos, :dni, 'M', 1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 'SISTEMA_PUBLICO')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':nombres' => $datos['Nombres'],
          ':apellidos' => $datos['Paterno'] . ' ' . $datos['Materno'],
          ':dni' => $datos['Nro_Doc']
        ]);
        $id_usuario = $pdo->lastInsertId();

        // Insertar datos de estudiante
        $sql = "INSERT INTO estudiantes_unheval (id_usuario, codigo_universitario, facultad, escuela, nivel_academico, anio_estudio)
                VALUES (:id_usuario, :codigo, :facultad, :escuela, :nivel, :anio)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':id_usuario' => $id_usuario,
          ':codigo' => $datos['Codigo'] ?? '',
          ':facultad' => $datos['Facultad'],
          ':escuela' => $datos['Escuela'],
          ':nivel' => $datos['Niv_Acad'],
          ':anio' => $datos['anio_estudio'] ?? ''
        ]);

        // Registrar entrada
        $sql = "INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro)
                VALUES (:id_usuario, 'Entrada', CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_usuario' => $id_usuario]);

        $pdo->commit();

        $tipo_mensaje = 'success';
        $mensaje = '¡Bienvenido! Te hemos registrado en el sistema.';
        $datos_usuario = [
          'nombre_completo' => $datos['Paterno'] . ' ' . $datos['Materno'] . ', ' . $datos['Nombres'],
          'tipo_registro' => 'Entrada',
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
      font-size: 5.5rem;
      font-weight: 700;
      color: #fff;
      line-height: 1;
      text-shadow: 0 5px 15px rgba(0, 0, 0, 0.7);
      text-align: center;
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
      font-size: 24px;
      font-weight: 800;
      color: var(--primary-color);
      margin: 0;
    }

    .header-text p {
      margin: 0;
      color: var(--secondary-color);
      font-weight: 700;
      font-size: 14px;
      letter-spacing: 2px;
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
      background: rgba(56, 161, 105, 0.1);
      border: 2px solid #38a169;
      border-radius: 16px;
      animation: zoomIn 0.3s ease-out;
    }

    .user-feedback.error {
      background: rgba(220, 53, 69, 0.1);
      border-color: #dc3545;
    }

    .success-badge {
      color: #38a169;
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
          <div style="font-size: 11px; color: #718096; font-weight: 600;">Universidad Nacional Hermilio Valdizán</div>
          <h1>BIBLIOTECA CENTRAL</h1>
          <p>"Javier Pulgar Vidal"</p>
        </div>
        <img src="../../img/logo_uni.jpg" alt="UNHEVAL" class="logo-img">
      </header>

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
              <i class="fas fa-<?= $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
              <?= $datos_usuario['tipo_registro'] === 'Entrada' ? 'BIENVENIDO' : 'HASTA PRONTO' ?>
            </div>
            <div class="user-name"><?= htmlspecialchars($datos_usuario['nombre_completo']) ?></div>
            <div style="font-size: 14px; opacity: 0.8; margin-top: 5px;">
              <strong><?= $datos_usuario['tipo_registro'] ?></strong> registrada a las <?= $datos_usuario['fecha_hora'] ?>
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
        timer: 5000,
        timerProgressBar: true
      });
    <?php endif; ?>
  </script>
</body>

</html>