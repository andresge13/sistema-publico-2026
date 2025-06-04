<?php
date_default_timezone_set('America/Lima');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Registro de Asistencia - UNHEVAL</title>
  <link rel="stylesheet" href="css/style.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <header style="background-color: #003366; color: white; padding: 10px 0; text-align: center;">
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px;">
        <img src="ruta/logo-unheval.png" alt="UNHEVAL" height="60">
        <h1 style="margin: 0; font-size: 1.5em;">UNHEVAL - Universidad Nacional Hermilio Valdizán</h1>
        <img src="ruta/logo-biblioteca.png" alt="Biblioteca" height="60">
    </div>
</header>
  <div class="container">
    <div class="header">
      <img src="assets/images/logobiblioteca.png" alt="Logo Biblioteca" class="logo" />
      <h1>SISTEMA DE CONTROL DE ASISTENCIA DE USUARIOS BIBLIOTECA - UNHEVAL</h1>
      <img src="assets/images/logoUnheval.png" alt="Logo UNHEVAL" class="logo" />
    </div>

    <div id="clock" class="clock"></div>

    <div class="subtitle">REGISTRE SU ASISTENCIA</div>

    <div class="dni-container">
      <input type="text" id="dni" placeholder="Ingrese su DNI" />
      <button onclick="handleSearch()">Registrar</button>
    </div>

    <div id="result" class="resultado"></div>
    <div id="mensaje" class="mensaje"></div>
    <div id="error" class="error"></div>
  </div>

  <script src="js/main.js"></script>
  <footer style="background-color: #003366; color: white; text-align: center; padding: 15px; position: fixed; width: 100%; bottom: 0;">
    © Biblioteca Central Javier Pulgar Vidal - UNHEVAL
</footer>
</body>
</html>
