<?php
session_start();
include_once '../../modelo/conexion.php';

$conexion = new Conexion();
$pdo = $conexion->pdo;

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

// Leer configuración del sistema para validar estado inicial (banners)
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
} catch (Exception $e) {}

// Funciones mínimas para banners iniciales
function verificarHorarioAtencion($config) {
  if (($config['horario_atencion_activo'] ?? '0') !== '1') return ['permitido' => true];
  $dia_semana = date('w');
  $hora_actual = date('H:i');
  if ($dia_semana == 0 && ($config['atencion_domingo'] ?? '0') !== '1') {
      return ['permitido' => false, 'mensaje' => $config['mensaje_fuera_horario'] ?? 'Cerrado domingos'];
  }
  if ($dia_semana == 6) {
    $h_ap = $config['hora_apertura_sabado'] ?? '08:00';
    $h_ci = $config['hora_cierre_sabado'] ?? '13:00';
  } else {
    $h_ap = $config['hora_apertura_lun_vie'] ?? '07:00';
    $h_ci = $config['hora_cierre_lun_vie'] ?? '20:30';
  }
  if ($hora_actual < $h_ap || $hora_actual > $h_ci) return ['permitido' => false, 'mensaje' => $config['mensaje_fuera_horario'] ?? 'Cerrado'];
  return ['permitido' => true];
}

function verificarAforo($pdo, $config) {
  if (($config['control_aforo_activo'] ?? '0') !== '1') return ['permitido' => true];
  $cap_max = intval($config['capacidad_biblioteca'] ?? 500);
  $sql = "SELECT (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Entrada' AND fecha = CURDATE()) -
                 (SELECT COUNT(*) FROM asistencias WHERE tipo_registro = 'Salida' AND fecha = CURDATE()) AS aforo";
  $stmt = $pdo->query($sql);
  $res = $stmt->fetch(PDO::FETCH_ASSOC);
  $a=$res?$res['aforo']:0;
  if ($a >= $cap_max) return ['permitido' => false, 'mensaje' => $config['mensaje_aforo_lleno'] ?? 'Aforo completo'];
  return ['permitido' => true];
}

$notificacion_horario = verificarHorarioAtencion($config_sistema);
$notificacion_aforo = verificarAforo($pdo, $config_sistema);
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

    html, body {
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
      from { transform: scale(1.1) translateX(0); }
      to { transform: scale(1.2) translateX(-30px); }
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
      max-width: 1100px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    #liveClock {
      font-size: 5.5rem;
      font-weight: 700;
      color: #fff;
      line-height: 1;
      text-shadow: 0 5px 20px rgba(0, 0, 0, 0.8);
      text-align: center;
      margin-top: 10px;
    }

    #liveDate {
      color: var(--secondary-color);
      font-size: 1.8rem;
      font-weight: 500;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.6);
      text-align: center;
      margin-bottom: 20px;
    }

    .uni-header {
      background: var(--card-bg);
      border-radius: 24px;
      padding: 20px 40px;
      box-shadow: 0 15px 45px rgba(0, 0, 0, 0.6);
      border-bottom: 8px solid var(--secondary-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .logo-img { height: 85px; }

    .header-text h1 {
      font-size: 31px;
      font-weight: 800;
      color: var(--primary-color);
      margin: 0;
      text-transform: uppercase;
      letter-spacing: -0.5px;
    }

    .header-text h2 {
      font-size: 22px;
      font-weight: 700;
      color: var(--text-color);
      margin: 0;
      display: inline-block;
    }

    .header-text .subtitle {
      margin-left: 8px;
      color: var(--secondary-color);
      font-weight: 600;
      font-size: 18px;
      letter-spacing: 0.8px;
    }

    .register-card {
      background: var(--card-bg);
      border-radius: 28px;
      padding: 40px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
      text-align: center;
    }

    .instruction-text {
      font-size: 20px;
      color: #555;
      margin-bottom: 25px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .dni-input {
      font-size: 64px;
      text-align: center;
      padding: 20px;
      border: 5px solid #cbd5e0;
      border-radius: 22px;
      font-weight: 800;
      letter-spacing: 15px;
      color: var(--primary-color);
      background: #f8fafc;
      max-width: 650px;
      margin: 0 auto;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .dni-input:focus {
      border-color: var(--secondary-color);
      background: #fff;
      box-shadow: 0 0 30px rgba(197, 160, 89, 0.4);
      transform: scale(1.02);
      outline: none;
    }

    .user-feedback {
      margin-top: 30px;
      padding: 25px;
      background: rgba(16, 185, 129, 0.1);
      border: 3px solid #10B981;
      border-radius: 20px;
      animation: zoomIn 0.4s ease-out;
    }

    .user-feedback.error {
      background: rgba(220, 53, 69, 0.1);
      border-color: #dc3545;
    }

    .success-badge {
      color: #10B981;
      font-weight: 900;
      font-size: 24px;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .user-name {
      font-size: 28px;
      font-weight: 800;
      color: var(--text-color);
    }

    .footer {
      text-align: center;
      color: rgba(255, 255, 255, 0.9);
      font-size: 14px;
      font-weight: 500;
      margin-top: 20px;
      text-shadow: 0 2px 4px rgba(0,0,0,0.5);
    }

    @keyframes zoomIn {
      from { transform: scale(0.95); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }

    /* ========== MEJORAS MODO KIOSKO ========== */
    .kiosk-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(12, 35, 64, 0.95);
      z-index: 9999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
      text-align: center;
      cursor: pointer;
    }
    .kiosk-badge {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 8px 15px;
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(10px);
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: white;
      font-size: 14px;
      z-index: 1000;
    }
    .live-pulse {
      display: inline-block;
      width: 8px;
      height: 8px;
      background: #10B981;
      border-radius: 50%;
      margin-right: 8px;
      box-shadow: 0 0 8px #10B981;
      animation: pulse 2s infinite;
    }
    @keyframes pulse {
      0% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.5); opacity: 0.5; }
      100% { transform: scale(1); opacity: 1; }
    }
    .no-cursor { cursor: none !important; }
    body.kiosk-active { user-select: none; -webkit-user-select: none; }
    
    .aforo-widget {
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: white;
        padding: 15px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 100;
        min-width: 180px;
    }
    .aforo-icon {
        width: 45px;
        height: 45px;
        background: var(--primary-color);
        color: var(--secondary-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    .aforo-info { text-align: left; }
    .aforo-label { font-size: 10px; font-weight: 700; color: #718096; text-transform: uppercase; }
    .aforo-value { font-size: 18px; font-weight: 800; color: var(--primary-color); line-height: 1; }

    /* ========== RESPONSIVIDAD ========== */
    @media (max-width: 991.98px) {
      #liveClock { font-size: 4rem; }
      #liveDate { font-size: 1.2rem; }
      .header-text h1 { font-size: 20px; }
      .dni-input { font-size: 32px; letter-spacing: 6px; max-width: 400px; }
    }

    @media (max-width: 767.98px) {
      .main-wrapper { padding: 10px; }
      #liveClock { font-size: 3rem; }
      #liveDate { font-size: 1rem; margin-bottom: 10px; }
      .uni-header { padding: 10px 15px; flex-direction: column; text-align: center; }
      .header-text h1 { font-size: 16px; }
      .register-card { padding: 15px; }
      .dni-input { font-size: 24px; letter-spacing: 4px; padding: 10px; max-width: 100%; }
    }
  </style>
</head>

<body class="<?= $modo_kiosko ? 'kiosk-active' : '' ?>">
  <?php if ($modo_kiosko): ?>
    <div id="kioskOverlay" class="kiosk-overlay" onclick="activarModoKiosko()">
        <div class="mb-4">
            <i class="fas fa-desktop fa-5x text-secondary mb-3"></i>
            <h1 class="display-4 font-weight-bold">MODO KIOSKO ACTIVO</h1>
            <p class="lead">Haga clic aquí para iniciar el terminal a pantalla completa</p>
        </div>
        <div class="badge badge-warning p-2">
            <i class="fas fa-lock mr-2"></i> Protegido contra navegación externa
        </div>
    </div>
    
    <div class="kiosk-badge">
        <span class="live-pulse"></span> Terminal Biblioteca 01 - UNHEVAL
    </div>

    <div class="aforo-widget" id="aforoWidget" style="display: none;">
        <div class="aforo-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="aforo-info">
            <div class="aforo-label">Ocupación Actual</div>
            <div class="aforo-value"><span id="aforoNow">0</span> / <span id="aforoMax">0</span></div>
        </div>
    </div>
  <?php endif; ?>

  <div class="bg-animated"></div>
  <div class="main-wrapper">
    <div class="content-box">
      <div id="liveClock">00:00:00</div>
      <div id="liveDate">Cargando...</div>

      <header class="uni-header text-center">
        <img src="../../img/inicio_biblio.png" alt="Biblio" class="logo-img">
        <div class="header-text">
          <div style="font-size: 15px; color: #718096; font-weight: 600;">Universidad Nacional Hermilio Valdizán</div>
          <h1>SISTEMA DE CONTROL DE ASISTENCIA</h1>
          <div class="mt-1">
            <h2>BIBLIOTECA CENTRAL</h2>
            <span class="subtitle">"Javier Pulgar Vidal"</span>
          </div>
        </div>
        <img src="../../img/unheval - copia.png" alt="UNHEVAL" class="logo-img">
      </header>

      <?php if (!$notificacion_horario['permitido']): ?>
        <div style="background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); color: white; padding: 15px 25px; border-radius: 12px; margin: 15px 0; text-align: center; box-shadow: 0 5px 20px rgba(239, 68, 68, 0.4);">
          <i class="fas fa-clock fa-2x" style="margin-bottom: 10px;"></i>
          <h3 style="margin: 0; font-weight: 700;">BIBLIOTECA CERRADA</h3>
          <p style="margin: 10px 0 0 0; opacity: 0.9;"><?= htmlspecialchars($notificacion_horario['mensaje']) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($notificacion_horario['permitido'] && !$notificacion_aforo['permitido']): ?>
        <div style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: white; padding: 15px 25px; border-radius: 12px; margin: 15px 0; text-align: center; box-shadow: 0 5px 20px rgba(245, 158, 11, 0.4);">
          <i class="fas fa-users-slash fa-2x" style="margin-bottom: 10px;"></i>
          <h3 style="margin: 0; font-weight: 700;">AFORO COMPLETO</h3>
          <p style="margin: 10px 0 0 0; opacity: 0.9;"><?= htmlspecialchars($notificacion_aforo['mensaje']) ?></p>
        </div>
      <?php endif; ?>

      <main class="register-card">
        <div class="instruction-text">
          <i class="fas fa-barcode me-2"></i> INGRESE SU DNI
        </div>
        <form id="formAsistencia">
          <input type="text" class="form-control dni-input" name="dni" id="dniInput"
            placeholder="DNI" maxlength="8" pattern="[0-9]{8}" autocomplete="off" required autofocus>
        </form>

        <div id="feedbackContainer" style="display: none;"></div>
      </main>

      <div class="footer">
        © <?= date('Y') ?> Dirección de Biblioteca Central • UNHEVAL • Huánuco, Perú
      </div>
    </div>
  </div>

  <script>
    function updateClock() {
      const now = new Date();
      document.getElementById('liveClock').textContent = now.toLocaleTimeString('es-ES', { hour12: false });
      const options = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
      let dateStr = now.toLocaleDateString('es-ES', options);
      document.getElementById('liveDate').textContent = dateStr.charAt(0).toUpperCase() + dateStr.slice(1);
    }
    setInterval(updateClock, 1000);
    updateClock();

    const input = document.getElementById('dniInput');
    const form = document.getElementById('formAsistencia');

    function mantenerEnfoque() {
      if (document.activeElement !== input) input.focus();
    }
    input.addEventListener('blur', () => setTimeout(mantenerEnfoque, 50));
    document.addEventListener('click', (e) => { if (e.target !== input) setTimeout(mantenerEnfoque, 50); });
    setInterval(mantenerEnfoque, 2000);
    mantenerEnfoque();

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      procesarAsistencia();
    });

    input.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '');
      if (this.value.length === 8) {
          procesarAsistencia();
      }
    });

    let isProcessing = false;

    function procesarAsistencia() {
        if (isProcessing) return;
        
        const dni = input.value;
        if (dni.length !== 8) return;

        isProcessing = true;
        //input.value = ''; // Despejar inmediatamente para evitar disparos dobles

        const formData = new FormData();
        formData.append('dni', dni);

        fetch('../../api/registrar_asistencia.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            
            // Mostrar alerta flotante solo si hay algún error/advertencia (ej: no encontrado)
            if (!data.success) {
                Swal.fire({
                    icon: data.type || 'info',
                    title: 'Aviso',
                    text: data.message,
                    background: '#fff',
                    color: '#2d3748',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            }

            if (data.userData) {
                const container = document.getElementById('feedbackContainer');
                const isError = data.type === 'error';
                const icon = data.type === 'success' ? 'check-circle' : (data.userData.tipo_registro === 'Registro' ? 'user-plus' : 'exclamation-circle');
                const badgeText = data.userData.tipo_registro.toUpperCase();
                
                container.innerHTML = `
                    <div class="user-feedback ${isError ? 'error' : ''}">
                        <div class="success-badge">
                            <i class="fas fa-${icon}"></i> ${badgeText}
                        </div>
                        <div class="user-name">${data.userData.nombre_completo}</div>
                        <div style="font-size: 14px; opacity: 0.8; margin-top: 5px;">
                            ${data.userData.tipo_registro === 'Registro' ? 
                              '<strong>Registrado</strong> — Pasa tu DNI de nuevo para entrar' : 
                              '<strong>' + data.userData.tipo_registro + '</strong> registrada a las ' + data.userData.fecha_hora}
                        </div>
                    </div>
                `;
                container.style.display = 'block';
                container.style.opacity = '1';

                setTimeout(() => {
                    container.style.transition = 'opacity 0.5s ease';
                    container.style.opacity = '0';
                    setTimeout(() => container.style.display = 'none', 500);
                }, 5000);
            }
            
            if (typeof updateAforoLive === 'function' && <?php echo $modo_kiosko ? 'true' : 'false'; ?>) {
                updateAforoLive();
            }
        })
        .catch(err => {
            console.error('Error:', err);
            Swal.fire('Error', 'No se pudo procesar la solicitud', 'error');
        })
        .finally(() => {
            input.value = ''; // Limpiar el input una vez finalizado el proceso
            isProcessing = false;
        });
    }

    <?php if ($modo_kiosko): ?>
      let idleTimer;
      const overlay = document.getElementById('kioskOverlay');

      function activarModoKiosko() {
        const elem = document.documentElement;
        if (elem.requestFullscreen) elem.requestFullscreen();
        else if (elem.webkitRequestFullscreen) elem.webkitRequestFullscreen();
        overlay.style.display = 'none';
        document.body.classList.add('kiosk-active');
        mantenerEnfoque();
      }

      document.addEventListener('fullscreenchange', function() {
        if (document.fullscreenElement) {
            overlay.style.display = 'none';
            document.body.classList.add('no-cursor');
        } else {
            overlay.style.display = 'flex';
            document.body.classList.remove('no-cursor');
        }
      });

      function resetIdleTimer() {
        document.body.classList.remove('no-cursor');
        clearTimeout(idleTimer);
        if (document.fullscreenElement) {
            idleTimer = setTimeout(() => {
                document.body.classList.add('no-cursor');
            }, 3000);
        }
      }
      document.addEventListener('mousemove', resetIdleTimer);
      document.addEventListener('keydown', resetIdleTimer);

      document.addEventListener('contextmenu', e => e.preventDefault());
      document.addEventListener('keydown', function(e) {
        const blockedKeys = ['F5', 'F11', 'F12', 'Escape'];
        if (blockedKeys.includes(e.key) || (e.ctrlKey && ['r', 'w', 'u', 'i'].includes(e.key.toLowerCase())) || (e.altKey && e.key === 'F4')) {
          e.preventDefault();
        }
      });

      function updateAforoLive() {
        fetch('../../api/get_aforo.php')
          .then(res => res.json())
          .then(data => {
            if (data.success && data.activo) {
              document.getElementById('aforoWidget').style.display = 'flex';
              document.getElementById('aforoNow').textContent = data.aforo_actual;
              document.getElementById('aforoMax').textContent = data.capacidad;
            }
          })
          .catch(err => console.error('Error fetching aforo:', err));
      }
      setInterval(updateAforoLive, 30000);
      updateAforoLive();
    <?php endif; ?>
  </script>
</body>
</html>