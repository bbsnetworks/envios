<?php
session_start();

if (!isset($_SESSION['username'])) {
  header("Location: ../menu/login/index.php");
  exit();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Avisos de servicio | BBSNetworks</title>

  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>
<body class="min-h-screen bg-[#071322] text-white">

  <main class="min-h-screen relative overflow-hidden">
    <!-- fondos -->
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
      <div class="absolute -top-24 left-[-6rem] h-72 w-72 rounded-full bg-cyan-400/10 blur-3xl"></div>
      <div class="absolute top-1/3 right-[-8rem] h-80 w-80 rounded-full bg-blue-500/10 blur-3xl"></div>
      <div class="absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-sky-400/10 blur-3xl"></div>
    </div>

    <!-- MENU -->
    <header class="relative z-20 border-b border-white/10 bg-[#081728]/80 backdrop-blur-xl">
      <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 class="text-2xl md:text-3xl font-bold tracking-tight">Avisos de servicio</h1>
          <p class="text-slate-300 text-sm mt-1">Notificaciones por cortes de fibra, fallas o mantenimiento</p>
        </div>

        <nav class="flex items-center gap-3">
          <a href="index.php"
             class="inline-flex items-center gap-2 rounded-2xl px-4 py-2.5 bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-semibold transition">
            Home
          </a>

          <a href="/../menu/index.php"
             class="inline-flex items-center gap-2 rounded-2xl px-4 py-2.5 border border-white/10 bg-white/5 hover:bg-white/10 text-white font-semibold transition">
            Regresar al menú
          </a>
        </nav>
      </div>
    </header>

    <!-- CONTENIDO -->
    <section class="relative z-10 max-w-7xl mx-auto px-4 py-8">
      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">

        <!-- formulario -->
        <div class="xl:col-span-7">
          <div class="rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl shadow-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-white/10 bg-white/5">
              <h2 class="text-xl font-semibold">Configuración del aviso</h2>
            </div>

            <div class="p-6 space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm text-slate-300 mb-2">Tipo de aviso</label>
                  <select id="tipoAviso"
                    class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 outline-none focus:ring-2 focus:ring-cyan-400">
                    <option value="Corte de fibra">Corte de fibra</option>
                    <option value="Caída de servicio">Caída de servicio</option>
                    <option value="Mantenimiento programado">Mantenimiento programado</option>
                    <option value="Intermitencia">Intermitencia</option>
                    <option value="Otro">Otro</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm text-slate-300 mb-2">Modo de envío</label>
                  <select id="modoEnvio"
                    class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 outline-none focus:ring-2 focus:ring-cyan-400">
                    <option value="localidades">Solo localidades seleccionadas</option>
                    <option value="todos">Todos los clientes activos</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="block text-sm text-slate-300 mb-2">Asunto</label>
                <input id="asunto" type="text"
                  class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 placeholder-slate-400 outline-none focus:ring-2 focus:ring-cyan-400"
                  placeholder="Ej. Aviso importante sobre interrupción de servicio" />
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm text-slate-300 mb-2">Fecha</label>
                  <input id="fechaAviso" type="date"
                    class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 outline-none focus:ring-2 focus:ring-cyan-400" />
                </div>

                <div>
                  <label class="block text-sm text-slate-300 mb-2">Hora estimada</label>
                  <input id="horaAviso" type="time"
                    class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 outline-none focus:ring-2 focus:ring-cyan-400" />
                </div>
              </div>

              <div>
                <label class="block text-sm text-slate-300 mb-3">Localidades afectadas</label>
                <div id="contenedorLocalidades"
                  class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 rounded-2xl border border-white/10 bg-[#081728] p-4 max-h-[320px] overflow-y-auto">
                  <div class="text-slate-400">Cargando localidades...</div>
                </div>
              </div>

              <div>
                <label class="block text-sm text-slate-300 mb-2">Mensaje</label>
                <textarea id="mensaje" rows="7"
                  class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 placeholder-slate-400 outline-none focus:ring-2 focus:ring-cyan-400"
                  placeholder="Describe la falla, zonas afectadas y tiempo estimado..."></textarea>
              </div>

              <div>
                <label class="block text-sm text-slate-300 mb-2">Mensaje adicional</label>
                <textarea id="mensajeExtra" rows="3"
                  class="w-full rounded-2xl bg-[#0b1d36] border border-white/10 text-white px-4 py-3 placeholder-slate-400 outline-none focus:ring-2 focus:ring-cyan-400"
                  placeholder="Ej. Nuestro personal ya se encuentra trabajando para restablecer el servicio."></textarea>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <button id="btnDestinatarios"
                  class="rounded-2xl px-4 py-3 font-semibold bg-blue-600 hover:bg-blue-500 transition">
                  Ver destinatarios
                </button>

                <button id="btnPDF"
                  class="rounded-2xl px-4 py-3 font-semibold bg-slate-700 hover:bg-slate-600 transition">
                  Generar PDF
                </button>

                <button id="btnEnviar"
                  class="rounded-2xl px-4 py-3 font-semibold bg-emerald-600 hover:bg-emerald-500 transition">
                  Enviar correo
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- preview -->
        <div class="xl:col-span-5">
          <div class="rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl shadow-2xl overflow-hidden sticky top-6">
            <div class="px-6 py-5 border-b border-white/10 bg-white/5">
              <h2 class="text-xl font-semibold">Vista previa</h2>
            </div>

            <div class="p-5">
              <div class="rounded-[28px] overflow-hidden border border-cyan-400/20 bg-[#081728] shadow-[0_0_40px_rgba(34,211,238,0.08)]">
                <div class="px-6 py-5 bg-[linear-gradient(135deg,#0ea5e9_0%,#1d4ed8_40%,#071322_100%)]">
                  <div class="text-2xl font-bold">BBSNetworks</div>
                  <div class="text-sm text-cyan-100/90 mt-1">Aviso de servicio</div>
                </div>

                <div class="p-6 space-y-4">
                  <h3 id="pvAsunto" class="text-xl font-semibold text-white">Asunto del aviso</h3>
                  <div id="pvMeta" class="text-sm text-slate-400">Fecha del aviso</div>

                  <div>
                    <div class="text-sm uppercase tracking-widest text-cyan-300 mb-2">Tipo</div>
                    <div id="pvTipo" class="text-slate-100">Corte de fibra</div>
                  </div>

                  <div>
                    <div class="text-sm uppercase tracking-widest text-cyan-300 mb-2">Localidades afectadas</div>
                    <div id="pvLocalidades" class="text-slate-100">Sin seleccionar</div>
                  </div>

                  <div>
                    <div class="text-sm uppercase tracking-widest text-cyan-300 mb-2">Mensaje</div>
                    <div id="pvMensaje" class="text-slate-200 whitespace-pre-line leading-7">
                      Aquí aparecerá el contenido del correo.
                    </div>
                  </div>

                  <div id="pvExtraBox" class="rounded-2xl border border-yellow-400/20 bg-yellow-400/10 p-4 hidden">
                    <div class="text-sm uppercase tracking-widest text-yellow-300 mb-2">Información adicional</div>
                    <div id="pvExtra" class="text-slate-200 whitespace-pre-line"></div>
                  </div>
                </div>

                <div class="px-6 py-4 border-t border-white/10 bg-white/5 text-xs text-slate-400">
                  © BBSNetworks · Aviso automático de servicio
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </main>

  <script src="js/index.js"></script>
</body>
</html>