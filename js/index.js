const el = (id) => document.getElementById(id);

document.addEventListener('DOMContentLoaded', async () => {
  const hoy = new Date();
  el('fechaAviso').value = hoy.toISOString().split('T')[0];
  el('horaAviso').value = hoy.toTimeString().slice(0, 5);

  await cargarLocalidades();
  actualizarPreview();

  ['tipoAviso', 'modoEnvio', 'asunto', 'fechaAviso', 'horaAviso', 'mensaje', 'mensajeExtra'].forEach(id => {
    el(id).addEventListener('input', actualizarPreview);
    el(id).addEventListener('change', actualizarPreview);
  });

  if (el('btnEnviar')) {
    el('btnEnviar').addEventListener('click', enviarAviso);
  }

  if (el('btnDestinatarios')) {
    el('btnDestinatarios').addEventListener('click', verDestinatarios);
  }

  if (el('btnPDF')) {
    el('btnPDF').addEventListener('click', generarPDF);
  }
});

async function cargarLocalidades() {
  try {
    const res = await fetch('php/obtener_localidades_aviso.php');
    const data = await res.json();

    const box = el('contenedorLocalidades');
    box.innerHTML = '';

    if (!data.ok || !data.localidades.length) {
      box.innerHTML = '<div class="text-slate-400">No hay localidades disponibles.</div>';
      return;
    }

    data.localidades.forEach(loc => {
      const item = document.createElement('label');
      item.className = 'flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 cursor-pointer hover:bg-white/10 transition';

      item.innerHTML = `
        <input type="checkbox" class="accent-cyan-400 localidadCheck" value="${escapeHtml(loc.nombrelocalidad)}">
        <span class="text-sm text-slate-200">${escapeHtml(loc.nombrelocalidad)}</span>
      `;

      box.appendChild(item);
    });

    document.querySelectorAll('.localidadCheck').forEach(chk => {
      chk.addEventListener('change', actualizarPreview);
    });

  } catch (e) {
    if (el('contenedorLocalidades')) {
      el('contenedorLocalidades').innerHTML = '<div class="text-red-300">Error al cargar localidades</div>';
    }
  }
}

function getLocalidadesSeleccionadas() {
  return [...document.querySelectorAll('.localidadCheck:checked')].map(x => x.value.trim());
}

function actualizarPreview() {
  const asunto = el('asunto')?.value.trim() || 'Aviso importante de servicio';
  const tipo = el('tipoAviso')?.value.trim() || '';
  const fecha = el('fechaAviso')?.value || '';
  const hora = el('horaAviso')?.value || '';
  const mensaje = el('mensaje')?.value.trim() || 'Sin mensaje.';
  const extra = el('mensajeExtra')?.value.trim() || '';
  const modo = el('modoEnvio')?.value || 'localidades';
  const localidades = getLocalidadesSeleccionadas();

  if (el('pvAsunto')) el('pvAsunto').textContent = asunto;
  if (el('pvTipo')) el('pvTipo').textContent = tipo;
  if (el('pvMeta')) el('pvMeta').textContent = `Fecha: ${fecha || '-'} ${hora ? '· Hora estimada: ' + hora : ''}`;
  if (el('pvLocalidades')) {
    el('pvLocalidades').textContent = modo === 'todos'
      ? 'Todos los clientes activos'
      : (localidades.length ? localidades.join(', ') : 'Sin seleccionar');
  }
  if (el('pvMensaje')) el('pvMensaje').textContent = mensaje;

  if (extra) {
    if (el('pvExtra')) el('pvExtra').textContent = extra;
    if (el('pvExtraBox')) el('pvExtraBox').classList.remove('hidden');
  } else {
    if (el('pvExtra')) el('pvExtra').textContent = '';
    if (el('pvExtraBox')) el('pvExtraBox').classList.add('hidden');
  }
}

function obtenerPayload() {
  return {
    tipo: el('tipoAviso')?.value.trim() || '',
    modo_envio: el('modoEnvio')?.value.trim() || 'localidades',
    asunto: el('asunto')?.value.trim() || '',
    fecha: el('fechaAviso')?.value.trim() || '',
    hora: el('horaAviso')?.value.trim() || '',
    mensaje: el('mensaje')?.value.trim() || '',
    mensaje_extra: el('mensajeExtra')?.value.trim() || '',
    localidades: getLocalidadesSeleccionadas()
  };
}

function validarFormulario(payload) {
  if (!payload.asunto) {
    Swal.fire({
      icon: 'warning',
      title: 'Falta el asunto',
      text: 'Debes escribir el asunto del aviso.',
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#f59e0b'
    });
    return false;
  }

  if (!payload.mensaje) {
    Swal.fire({
      icon: 'warning',
      title: 'Falta el mensaje',
      text: 'Debes escribir el mensaje del aviso.',
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#f59e0b'
    });
    return false;
  }

  if (payload.modo_envio === 'localidades' && payload.localidades.length === 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Selecciona localidades',
      text: 'Debes elegir al menos una localidad o cambiar el modo a "Todos los clientes activos".',
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#f59e0b'
    });
    return false;
  }

  return true;
}

async function verDestinatarios() {
  const payload = obtenerPayload();

  if (!validarFormulario(payload)) return;

  try {
    Swal.fire({
      title: 'Consultando destinatarios...',
      allowOutsideClick: false,
      background: '#071322',
      color: '#fff',
      didOpen: () => Swal.showLoading()
    });

    const res = await fetch('php/preview_destinatarios_aviso.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (!res.ok || !data.ok) {
      throw new Error(data.msg || 'No se pudo obtener la lista de destinatarios');
    }

    let html = `
      <div class="text-left text-sm">
        <div class="mb-3"><b>Destinatarios válidos:</b> ${data.total_validos}</div>
        <div class="max-h-72 overflow-y-auto rounded-xl border border-white/10 bg-[#081728] p-3">
    `;

    if (data.destinatarios?.length) {
      html += data.destinatarios.map(mail => `
        <div class="py-1 border-b border-white/5 text-slate-200">${escapeHtml(mail)}</div>
      `).join('');
    } else {
      html += `<div class="text-slate-400">No hay destinatarios válidos.</div>`;
    }

    html += `</div>`;

    if (data.excluidos?.length) {
      html += `
        <div class="mt-4 mb-2"><b>Excluidos:</b></div>
        <div class="max-h-56 overflow-y-auto rounded-xl border border-white/10 bg-[#081728] p-3">
          ${data.excluidos.map(item => `
            <div class="py-1 border-b border-white/5 text-slate-300">
              ${escapeHtml(item.email || '(sin correo)')} — ${escapeHtml(item.motivo || 'Excluido')}
            </div>
          `).join('')}
        </div>
      `;
    }

    html += `</div>`;

    Swal.fire({
      title: 'Vista previa de destinatarios',
      html,
      width: 800,
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#06b6d4'
    });

  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Error',
      text: err.message,
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#ef4444'
    });
  }
}

async function enviarAviso() {
  const payload = obtenerPayload();

  if (!validarFormulario(payload)) return;

  const localidadesTexto = payload.modo_envio === 'todos'
    ? 'Todos los clientes activos'
    : payload.localidades.join(', ');

  const confirmacion = await Swal.fire({
    title: '¿Enviar aviso?',
    html: `
      <div class="text-left text-sm leading-6">
        <div><b>Asunto:</b> ${escapeHtml(payload.asunto)}</div>
        <div><b>Tipo:</b> ${escapeHtml(payload.tipo)}</div>
        <div><b>Destino:</b> ${escapeHtml(localidadesTexto)}</div>
      </div>
    `,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Sí, enviar',
    cancelButtonText: 'Cancelar',
    background: '#071322',
    color: '#fff',
    confirmButtonColor: '#10b981',
    cancelButtonColor: '#334155'
  });

  if (!confirmacion.isConfirmed) return;

  try {
    Swal.fire({
      title: 'Enviando correos...',
      text: 'Por favor espera mientras se procesan los destinatarios.',
      allowOutsideClick: false,
      allowEscapeKey: false,
      background: '#071322',
      color: '#fff',
      didOpen: () => {
        Swal.showLoading();
      }
    });

    const res = await fetch('php/enviar_aviso_servicio.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (!res.ok || !data.ok) {
      throw new Error(data.msg || 'No se pudo enviar el aviso');
    }

    Swal.fire({
      icon: 'success',
      title: 'Aviso enviado',
      html: `
        <div class="text-left text-sm leading-6">
          <div><b>Correos enviados:</b> ${data.enviados}</div>
          <div><b>Excluidos:</b> ${data.excluidos}</div>
          <div><b>Fallidos:</b> ${data.fallidos}</div>
        </div>
      `,
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#10b981'
    });

  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Error al enviar',
      text: err.message,
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#ef4444'
    });
  }
}

function generarPDF() {
  const payload = obtenerPayload();

  if (!validarFormulario(payload)) return;

  if (!window.jspdf || !window.jspdf.jsPDF) {
    Swal.fire({
      icon: 'error',
      title: 'jsPDF no está cargado',
      background: '#071322',
      color: '#fff',
      confirmButtonColor: '#ef4444'
    });
    return;
  }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();

  const localidadesTexto = payload.modo_envio === 'todos'
    ? 'Todos los clientes activos'
    : payload.localidades.join(', ');

  let y = 20;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(18);
  doc.text('BBSNetworks - Aviso de servicio', 14, y);

  y += 10;
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(11);
  doc.text(`Asunto: ${payload.asunto}`, 14, y);

  y += 8;
  doc.text(`Tipo: ${payload.tipo}`, 14, y);

  y += 8;
  doc.text(`Fecha: ${payload.fecha}${payload.hora ? ' - Hora: ' + payload.hora : ''}`, 14, y);

  y += 8;
  const locLines = doc.splitTextToSize(`Localidades afectadas: ${localidadesTexto}`, 180);
  doc.text(locLines, 14, y);
  y += (locLines.length * 6) + 4;

  doc.setFont('helvetica', 'bold');
  doc.text('Mensaje:', 14, y);
  y += 8;

  doc.setFont('helvetica', 'normal');
  const msgLines = doc.splitTextToSize(payload.mensaje || 'Sin mensaje', 180);
  doc.text(msgLines, 14, y);
  y += (msgLines.length * 6) + 6;

  if (payload.mensaje_extra) {
    doc.setFont('helvetica', 'bold');
    doc.text('Información adicional:', 14, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    const extraLines = doc.splitTextToSize(payload.mensaje_extra, 180);
    doc.text(extraLines, 14, y);
  }

  doc.save('aviso_servicio_bbsnetworks.pdf');
}

function escapeHtml(text) {
  return String(text ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}