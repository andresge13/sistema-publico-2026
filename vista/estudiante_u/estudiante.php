<?php
session_start();

include_once '../../modelo/conexion.php';

$conexion = new Conexion();
$pdo = $conexion->pdo;

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SESSION['us_tipo'] != 1) {
  include_once '../layouts/header.php';
  ?>
  <div class="text-center my-4">
  <h1>Datos del Estudiante</h1>
  </div>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .logo-biblioteca {
      height: 70px;
    }
    .logo-unheval {
      height: 70px;
    }
    .titulo-principal {
      font-size: 20px;
      font-weight: bold;
      color: #003366;
    }
    .banner-titulo {
      background-color: #003366;
      color: white;
      padding: 10px;
      font-weight: bold;
      border-radius: 5px;
    }
    .form-busqueda input {
      max-width: 300px;
      margin-right: 10px;
    }
    .content-wrapper {
      background-color: #f4f6f9;
      padding: 20px;
    }
    .registro-box {
      border: 2px solid #003366;
      padding: 25px;
      background: #fff;
      border-radius: 10px;
    }
  </style>

  <?php include_once '../layouts/nav.php'; ?>

  <div class="content-wrapper">
    <div class="container mb-4 registro-box">
      <div class="row align-items-center text-center">
        <div class="col-md-2">
          <img src="../../assets/img/logo_biblioteca.png" alt="Biblioteca" class="logo-biblioteca">
        </div>
        <div class="col-md-8">
          <div class="titulo-principal">
            “SISTEMA DE CONTROL DE ASISTENCIA DE USUARIOS BIBLIOTECA - UNHEVAL”
          </div>
        </div>
        <div class="col-md-2">
          <img src="../../assets/img/logo_unheval.png" alt="UNHEVAL" class="logo-unheval">
        </div>
      </div>

      <div class="text-center mt-4 banner-titulo">
        REGISTRE SU ASISTENCIA
      </div>

      <section class="content-header mt-4">
        <form method="GET" class="d-flex justify-content-center form-busqueda" id="formDni">
          <input type="text" class="form-control text-center" name="dni" id="dniInput" placeholder="Ingrese tu DNI" maxlength="8"
            value="<?php echo isset($_GET['dni']) ? htmlspecialchars($_GET['dni']) : ''; ?>" required>
          <button class="btn btn-primary" type="submit">
            <i class="fas fa-search"></i> Registrar
          </button>
        </form>
      </section>
    </div>

    <section class="content">
      <div class="container">
        <div class="card">
          <div class="card-header bg-primary text-white">
            <h3 class="card-title">Datos del estudiante</h3>
          </div>
          <div class="card-body">
            <?php
            include_once(__DIR__ . '/../../api/datos_estudiantes.php');
            include_once '../../modelo/conexion.php';

            $conexion = new Conexion();
            $pdo = $conexion->pdo;

            $dni_buscado = isset($_GET['dni']) ? trim($_GET['dni']) : '';

            if ($dni_buscado !== '') {
              $respuesta = obtenerDatosAPI($dni_buscado);

              if (isset($respuesta['datos'])) {
                $datos = $respuesta['datos'];

                $sql_ultimo = "SELECT tipo_registro, fecha, hora 
                               FROM asistencias_registro 
                               WHERE dni = :dni 
                               ORDER BY id DESC LIMIT 1";
                $stmt_ultimo = $pdo->prepare($sql_ultimo);
                $stmt_ultimo->execute([':dni' => $datos['Nro_Doc']]);
                $ultimo = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);

                $tipo_registro = 'Entrada';

                if ($ultimo) {
                  $ultima_fecha_hora = $ultimo['fecha'] . ' ' . $ultimo['hora'];
                  $datetime_ultimo = new DateTime($ultima_fecha_hora);
                  $datetime_actual = new DateTime();
                  $intervalo = $datetime_ultimo->diff($datetime_actual);
                  $minutos_diferencia = ($intervalo->days * 24 * 60) + ($intervalo->h * 60) + $intervalo->i;

                  if ($minutos_diferencia < 1) {
                    echo "<script>
                      Swal.fire({
                        icon: 'warning',
                        title: 'Registro reciente',
                        text: '⏱️ Ya existe un registro reciente (menos de 1 minuto).'
                      });
                    </script>";
                    exit;
                  }

                  $tipo_registro = ($ultimo['tipo_registro'] === 'Entrada') ? 'Salida' : 'Entrada';
                }

                try {
                  $sql = "INSERT INTO asistencias_registro (
                      dni, nombres, paterno, materno, facultad, escuela, nivel_acad,
                      anio_estudio, tipo, tipo_registro
                    ) VALUES (
                      :dni, :nombres, :paterno, :materno, :facultad, :escuela,
                      :nivel_acad, :anio_estudio, :tipo, :tipo_registro
                    )";

                  $stmt = $pdo->prepare($sql);
                  $stmt->execute([
                    ':dni' => $datos['Nro_Doc'],
                    ':nombres' => $datos['Nombres'],
                    ':paterno' => $datos['Paterno'],
                    ':materno' => $datos['Materno'],
                    ':facultad' => $datos['Facultad'],
                    ':escuela' => $datos['Escuela'],
                    ':nivel_acad' => $datos['Niv_Acad'],
                    ':anio_estudio' => $datos['anio_estudio'],
                    ':tipo' => $datos['Tipo_Doc'],
                    ':tipo_registro' => $tipo_registro
                  ]);

                  echo "<script>
                    Swal.fire({
                      icon: 'success',
                      title: 'Registro exitoso',
                      text: 'Se registró correctamente la $tipo_registro.'
                    });
                  </script>";
                  echo "<script>document.getElementById('dniInput').value = '';</script>";
                } catch (PDOException $e) {
                  echo "<script>
                    Swal.fire({
                      icon: 'error',
                      title: 'Error de base de datos',
                      text: '" . htmlspecialchars($e->getMessage()) . "'
                    });
                  </script>";
                }

                echo '<div class="table-responsive">';
                echo '<table class="table table-bordered table-hover mt-4">';
                echo '<thead class="table-dark">
                        <tr>
                          <th>Apellidos</th>
                          <th>Nombres</th>
                          <th>DNI</th>
                          <th>Escuela</th>
                        </tr>
                      </thead>';
                echo '<tbody>';
                echo "<tr>";
                echo "<td>" . htmlspecialchars($datos['Paterno'] . ' ' . $datos['Materno']) . "</td>";
                echo "<td>" . htmlspecialchars($datos['Nombres']) . "</td>";
                echo "<td>" . htmlspecialchars($datos['Nro_Doc']) . "</td>";
                echo "<td>" . htmlspecialchars($datos['Escuela']) . "</td>";
                echo "</tr>";
                echo '</tbody>';
                echo '</table>';
                echo '</div>';
              } else {
                echo "<script>
                  Swal.fire({
                    icon: 'info',
                    title: 'DNI no encontrado',
                    text: '⚠️ No se encontraron datos para el DNI ingresado.'
                  });
                </script>";
              }
            }
            ?>
          </div>
          <div class="card-footer">
            Fecha y hora se guardan automáticamente.
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php include_once '../layouts/footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const dniInput = document.getElementById("dniInput");
      const form = document.getElementById("formDni");

      dniInput.addEventListener("keypress", function (event) {
        if (event.key === "Enter") {
          form.submit();
        }
      });
    });
  </script>
  <?php
} else {
  header('location: ../vista/login.php');
}
?>
