function updateClock() {
  const now = new Date();
  document.getElementById('clock').innerText = now.toLocaleTimeString();
}
setInterval(updateClock, 1000);
updateClock();

function handleSearch() {
  const dni = document.getElementById('dni').value.trim();
  const result = document.getElementById('result');
  const mensaje = document.getElementById('mensaje');
  const error = document.getElementById('error');

  result.innerHTML = '';
  mensaje.innerHTML = '';
  error.innerHTML = '';

  if (dni === '') {
    error.innerText = 'Ingrese su DNI por favor.';
    return;
  }

  fetch(`api/buscar_alumnos.php?dni=${dni}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.alumno) {
        const alumno = data.alumno;
        result.innerHTML = `${alumno.nombre} ${alumno.apellido}<br>${alumno.carrera}<br>${alumno.dni}`;

        registrarAsistencia(alumno.id, dni);
      } else {
        result.innerHTML = 'No se encontró su registro...';
      }
    })
    .catch(err => {
      console.error(err);
      error.innerText = 'Hubo un error al buscar el alumno.';
    });
}

function registrarAsistencia(alumnoId, dni) {
  const registros = JSON.parse(localStorage.getItem(`registros_${dni}`)) || [];
  const now = Date.now();
  const esEntrada = (registros.length + 1) % 2 !== 0;
  const mensajeRegistro = esEntrada ? "Asistencia registrada exitosamente" : "Salida registrada exitosamente";

  if (registros.length > 0) {
    const tiempoDesdeUltimo = (now - registros[registros.length - 1]) / 1000 / 60;
    if (tiempoDesdeUltimo < 0.3) {
      Swal.fire({
        position: "center",
        icon: "warning",
        title: "Sus datos ya están registrados. ¡Gracias!",
        showConfirmButton: false,
        timer: 2000,
      });
      return;
    }
  }

  fetch('api/registrar_asistencia.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ alumno_id: alumnoId, tipo: esEntrada ? "entrada" : "salida" })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        Swal.fire({
          position: "center",
          icon: "success",
          title: mensajeRegistro,
          text: "Su salida ya fue registrada correctamente",
          showConfirmButton: false,
          timer: 2000,
        });
        document.getElementById('mensaje').innerText = mensajeRegistro;
        registros.push(now);
        localStorage.setItem(`registros_${dni}`, JSON.stringify(registros));
      } else {
        document.getElementById('error').innerText = "No se pudo registrar la asistencia.";
      }
    })
    .catch(err => {
      console.error(err);
      document.getElementById('error').innerText = "SUS DATOS HAN SIDO REGISTRADOS. POR FAVOR, ESPERE UN MOMENTO.";
    });

  document.getElementById('dni').value = "";
}
