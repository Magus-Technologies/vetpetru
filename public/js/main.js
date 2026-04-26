// VetPro - main.js

// Modal helpers
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex';
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.style.display = 'none';
  }
  if (e.target.classList.contains('modal-close')) {
    e.target.closest('.modal-overlay').style.display = 'none';
  }
});

// Confirm delete
function confirmDelete(msg, url) {
  if (confirm(msg || '¿Estás seguro de eliminar este registro?')) {
    window.location.href = url;
  }
}

// WhatsApp Web URL generator
function sendWhatsApp(phone, message) {
  const tel = phone.replace(/[\s+\-()\[\]]/g, '');
  const url = 'https://wa.me/' + tel + '?text=' + encodeURIComponent(message);
  window.open(url, '_blank');
  return false;
}

// Copy to clipboard
function copyToClipboard(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    if (btn) {
      const orig = btn.textContent;
      btn.textContent = '✓ Copiado';
      setTimeout(() => { btn.textContent = orig; }, 2000);
    }
  });
}

// Format money
function formatMoney(n) {
  return 'S/. ' + parseFloat(n).toFixed(2);
}

// Auto-dismiss alerts
document.querySelectorAll('.alert-dismiss').forEach(el => {
  setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
});

// Tab switcher
function switchTab(tabGroup, tabId) {
  document.querySelectorAll('[data-tabgroup="' + tabGroup + '"]').forEach(el => {
    el.classList.toggle('active', el.dataset.tab === tabId);
  });
  document.querySelectorAll('[data-tabcontent="' + tabGroup + '"]').forEach(el => {
    el.style.display = el.dataset.id === tabId ? 'block' : 'none';
  });
}

// Venta: calcular total
function calcVentaTotal() {
  let sub = 0;
  document.querySelectorAll('.venta-item-row').forEach(row => {
    const qty = parseFloat(row.querySelector('.item-qty')?.value || 1);
    const price = parseFloat(row.querySelector('.item-price')?.value || 0);
    sub += qty * price;
  });
  const desc = parseFloat(document.getElementById('inp-descuento')?.value || 0);
  const igv = (sub - desc) * 0.18;
  const total = sub - desc + igv;
  const elSub = document.getElementById('venta-subtotal');
  const elIgv = document.getElementById('venta-igv');
  const elTotal = document.getElementById('venta-total');
  if (elSub) elSub.textContent = formatMoney(sub);
  if (elIgv) elIgv.textContent = formatMoney(igv);
  if (elTotal) elTotal.textContent = formatMoney(total);
}

// WhatsApp message builder
const WA_TEMPLATES = {
  cita: (data) => `🐾 *VetPro Veterinaria*\n\nHola ${data.cliente} 👋\n\nTe confirmamos la cita:\n\n📅 *Fecha:* ${data.fecha}\n🕐 *Hora:* ${data.hora}\n🐶 *Paciente:* ${data.mascota}\n👨‍⚕️ *Veterinario:* ${data.veterinario}\n\n📍 Av. Principal 234, Miraflores\n\nLlega 10 min antes. Responde si necesitas reprogramar.\n\n✅ VetPro — Cuidamos a tus mascotas`,
  recordatorio: (data) => `⏰ *Recordatorio VetPro*\n\nHola ${data.cliente} 👋\n\nTe recordamos que *mañana* es la cita de *${data.mascota}*:\n📅 ${data.fecha} a las ${data.hora}\n👨‍⚕️ ${data.veterinario}\n\n¿Confirmas tu asistencia? Responde *SÍ* o *NO*\n\nVetPro 🐾`,
  recibo: (data) => `🧾 *Boleta VetPro*\nN° ${data.numero}\n\nCliente: ${data.cliente}\nMascota: ${data.mascota}\nFecha: ${data.fecha}\n\n💰 *Total: S/. ${data.total}*\nMétodo: ${data.metodo} ✅\n\nGracias por confiar en VetPro 🐾`,
  vacuna: (data) => `💉 *Alerta de Vacuna — VetPro*\n\nHola ${data.cliente} 👋\n\nLa vacuna de *${data.mascota}* vence pronto:\n🗓️ *Vencimiento:* ${data.proxima}\n💉 ${data.vacuna}\n\n👉 Agenda su cita respondiendo aquí.\n\nVetPro 🐾`,
  informe: (data) => `🏥 *Informe Médico — VetPro*\n\nPaciente: *${data.mascota}*\nDueño: ${data.cliente}\nFecha: ${data.fecha}\nVet.: ${data.veterinario}\n\n🔍 *Diagnóstico:*\n${data.diagnostico}\n\n💊 *Tratamiento:*\n${data.tratamiento}\n\nVetPro 🐾`,
  cumple: (data) => `🎂 *¡Feliz cumpleaños, ${data.mascota}!*\n\nHola ${data.cliente} 👋 🎉\n\n¡Hoy ${data.mascota} cumple un año más! 🐾\n\nComo regalo: *10% de descuento* en su próxima consulta este mes.\n\nPresenta este mensaje al visitar la clínica.\n\nVetPro 🐾`
};

window.buildWAUrl = function(type, data, phone) {
  const fn = WA_TEMPLATES[type];
  if (!fn) return '#';
  const msg = fn(data);
  const tel = (phone || '').replace(/[\s+\-()\[\]]/g, '');
  return 'https://wa.me/' + tel + '?text=' + encodeURIComponent(msg);
};
