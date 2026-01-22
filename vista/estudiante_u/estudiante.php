<?php
session_start();
include_once '../../modelo/conexion.php';
include_once(__DIR__ . '/../../api/datos_estudiantes.php');

$conexion = new Conexion();
$pdo = $conexion->pdo;

ini_set('display_errors', 0);
error_reporting(E_ALL);

$mensaje = '';
$tipo_mensaje = '';
$datos_usuario = null;

if (isset($_GET['dni']) && !empty($_GET['dni'])) {
  $dni_buscado = trim($_GET['dni']);

  // Buscar en BD
  $sql_usuario = "SELECT u.*, eu.escuela, ue.institucion_procedencia
                    FROM usuarios u
                    LEFT JOIN estudiantes_unheval eu ON u.id_usuario = eu.id_usuario
                    LEFT JOIN usuarios_externos ue ON u.id_usuario = ue.id_usuario
                    WHERE u.dni = :dni AND u.id_estado = 1";

  $stmt = $pdo->prepare($sql_usuario);
  $stmt->execute([':dni' => $dni_buscado]);
  $usuario_bd = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($usuario_bd) {
    $id_usuario = $usuario_bd['id_usuario'];

    // Verificar último registro
    $sql_ultimo = "SELECT tipo_registro, fecha, hora FROM asistencias 
                       WHERE id_usuario = :id_usuario ORDER BY id_asistencia DESC LIMIT 1";
    $stmt_ultimo = $pdo->prepare($sql_ultimo);
    $stmt_ultimo->execute([':id_usuario' => $id_usuario]);
    $ultimo = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);

    $tipo_registro = 'Entrada';

    if ($ultimo) {
      $datetime_ultimo = new DateTime($ultimo['fecha'] . ' ' . $ultimo['hora']);
      $datetime_actual = new DateTime();
      $minutos = ($datetime_ultimo->diff($datetime_actual)->days * 1440) +
        ($datetime_ultimo->diff($datetime_actual)->h * 60) +
        $datetime_ultimo->diff($datetime_actual)->i;

      if ($minutos < 1) {
        $tipo_mensaje = 'warning';
        $mensaje = 'Ya existe un registro reciente (menos de 1 minuto).';
      } else {
        $tipo_registro = ($ultimo['tipo_registro'] === 'Entrada') ? 'Salida' : 'Entrada';
      }
    }

    if (empty($mensaje)) {
      try {
        $sql_insert = "INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro)
                               VALUES (:id_usuario, :tipo_registro, CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')";
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([':id_usuario' => $id_usuario, ':tipo_registro' => $tipo_registro]);

        $tipo_mensaje = 'success';
        $mensaje = "Se registró correctamente la {$tipo_registro}.";
        $datos_usuario = $usuario_bd;
      } catch (PDOException $e) {
        $tipo_mensaje = 'error';
        $mensaje = 'Error al registrar asistencia.';
      }
    }
  } else {
    // Buscar en API
    $respuesta = obtenerDatosAPI($dni_buscado);

    if (isset($respuesta['datos'])) {
      $datos = $respuesta['datos'];

      try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO usuarios (nombres, apellidos, dni, genero, id_tipo_usuario, fecha_registro, fecha_fin_registro, usuario_creacion)
                        VALUES (:nombres, :apellidos, :dni, 'M', 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 'SISTEMA_PUBLICO')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':nombres' => $datos['Nombres'],
          ':apellidos' => $datos['Paterno'] . ' ' . $datos['Materno'],
          ':dni' => $datos['Nro_Doc']
        ]);

        $id_usuario = $pdo->lastInsertId();

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

        $sql = "INSERT INTO asistencias (id_usuario, tipo_registro, fecha, hora, metodo_registro)
                        VALUES (:id_usuario, 'Entrada', CURDATE(), CURTIME(), 'SISTEMA_PUBLICO')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_usuario' => $id_usuario]);

        $pdo->commit();

        $tipo_mensaje = 'success';
        $mensaje = 'Estudiante registrado y asistencia marcada.';
        $datos_usuario = [
          'nombres' => $datos['Nombres'],
          'apellidos' => $datos['Paterno'] . ' ' . $datos['Materno'],
          'dni' => $datos['Nro_Doc'],
          'escuela' => $datos['Escuela'],
          'id_tipo_usuario' => 1
        ];
      } catch (PDOException $e) {
        $pdo->rollBack();
        $tipo_mensaje = 'error';
        $mensaje = 'Error al registrar estudiante.';
      }
    } else {
      $tipo_mensaje = 'info';
      $mensaje = 'DNI no encontrado. Si es usuario externo, debe registrarse primero.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro de Asistencia - Biblioteca UNHEVAL</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
      min-height: 100vh;
      padding: 20px 0;
      font-size: 14px;
    }

    .header-card {
      background: white;
      padding: 15px 25px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      margin-bottom: 20px;
    }

    .logo-img {
      height: 50px;
      object-fit: contain;
    }

    .main-title {
      font-size: 14px;
      font-weight: 600;
      color: #2d3748;
      text-align: center;
      line-height: 1.4;
    }

    .register-card {
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
      overflow: hidden;
    }

    .register-header {
      background: #2d3748;
      color: white;
      padding: 15px;
      text-align: center;
      font-size: 16px;
      font-weight: 500;
    }

    .register-body {
      padding: 30px;
    }

    .dni-input {
      font-size: 22px;
      text-align: center;
      padding: 12px;
      border: 2px solid #2d3748;
      border-radius: 8px;
      font-weight: 600;
      letter-spacing: 2px;
    }

    .dni-input:focus {
      border-color: #4a5568;
      outline: none;
      box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.2);
    }

    .btn-registrar {
      background: #38a169;
      border: none;
      padding: 12px 30px;
      font-size: 14px;
      font-weight: 600;
      border-radius: 8px;
      color: white;
      width: 100%;
    }

    .btn-registrar:hover {
      background: #2f855a;
      color: white;
    }

    .user-info-card {
      background: #f7fafc;
      padding: 20px;
      border-radius: 8px;
      margin-top: 20px;
      border-left: 4px solid #38a169;
    }

    .user-info-card h6 {
      color: #2d3748;
      font-weight: 600;
      margin-bottom: 15px;
    }

    .info-label {
      font-size: 11px;
      font-weight: 600;
      color: #718096;
      text-transform: uppercase;
    }

    .info-value {
      font-size: 13px;
      color: #2d3748;
    }

    .footer-text {
      text-align: center;
      color: rgba(255, 255, 255, 0.8);
      margin-top: 20px;
      font-size: 12px;
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="header-card">
      <div class="row align-items-center">
        <div class="col-2 text-center">
          <img src="../../img/inicio_biblio.png" alt="Biblioteca" class="logo-img">
        </div>
        <div class="col-8">
          <div class="main-title">
            SISTEMA DE CONTROL DE ASISTENCIA DE USUARIOS<br>
            BIBLIOTECA CENTRAL "JAVIER PULGAR VIDAL"
          </div>
        </div>
        <div class="col-2 text-center">
          <img src="../../img/logoUnheval.png" alt="UNHEVAL" class="logo-img">
        </div>
      </div>
    </div>

    <div class="register-card">
      <div class="register-header">
        <i class="fas fa-fingerprint me-2"></i> REGISTRE SU ASISTENCIA
      </div>

      <div class="register-body">
        <form method="GET" id="formDni">
          <div class="row justify-content-center g-3">
            <div class="col-md-5">
              <input type="text" class="form-control dni-input" name="dni" id="dniInput"
                placeholder="DNI" maxlength="8" pattern="[0-9]{8}" required autofocus>
            </div>
            <div class="col-md-3">
              <button class="btn btn-registrar" type="submit">
                <i class="fas fa-check-circle me-1"></i> REGISTRAR
              </button>
            </div>
          </div>
        </form>

        <?php if ($datos_usuario): ?>
          <div class="user-info-card">
            <h6><i class="fas fa-user-check text-success me-2"></i> Datos del Usuario</h6>
            <div class="row">
              <div class="col-md-4">
                <p class="info-label mb-0">Apellidos y Nombres</p>
                <p class="info-value"><?= htmlspecialchars($datos_usuario['apellidos'] . ', ' . $datos_usuario['nombres']) ?></p>
              </div>
              <div class="col-md-3">
                <p class="info-label mb-0">DNI</p>
                <p class="info-value"><?= htmlspecialchars($datos_usuario['dni']) ?></p>
              </div>
              <div class="col-md-2">
                <p class="info-label mb-0">Tipo</p>
                <span class="badge bg-primary" style="font-size: 11px;">
                  <?= $datos_usuario['id_tipo_usuario'] == 1 ? 'Estudiante' : 'Externo' ?>
                </span>
              </div>
              <div class="col-md-3">
                <p class="info-label mb-0"><?= $datos_usuario['id_tipo_usuario'] == 1 ? 'Escuela' : 'Institución' ?></p>
                <p class="info-value"><?= htmlspecialchars($datos_usuario['escuela'] ?? $datos_usuario['institucion_procedencia'] ?? 'N/A') ?></p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer-text">
      <strong>Universidad Nacional Hermilio Valdizán</strong><br>
      Biblioteca Central "Javier Pulgar Vidal"
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    <?php if (!empty($mensaje)): ?>
      Swal.fire({
        icon: '<?= $tipo_mensaje ?>',
        title: '<?= $tipo_mensaje == "success" ? "¡Éxito!" : ($tipo_mensaje == "warning" ? "Advertencia" : "Información") ?>',
        text: '<?= addslashes($mensaje) ?>',
        confirmButtonColor: '#2d3748',
        timer: 4000,
        timerProgressBar: true
      }).then(() => {
        document.getElementById('dniInput').value = '';
        document.getElementById('dniInput').focus();
      });
    <?php endif; ?>

    document.getElementById('dniInput').addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '');
    });
  </script>
</body>

</html>