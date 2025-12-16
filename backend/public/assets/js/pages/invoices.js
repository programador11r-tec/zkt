(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const { api, fetchJSON, escapeHtml, formatNumber, formatDateTime, loadSettings } = Core;
  const { app } = Core.elements;

  async function renderInvoices() {
    // === Carga de settings + helpers ===
    const settings = await loadSettings();

    function parseMoneyLike(x){
      if (x == null) return null;
      if (typeof x === 'number') return Number.isFinite(x) ? x : null;
      const s = String(x).trim(); if (!s) return null;
      const cleaned = s.replace(/[^\d.,-]/g,'').replace(/,/g,'.');
      const n = Number(cleaned);
      return Number.isFinite(n) ? n : null;
    }

    async function getHourlyRateFromOverview(){
      try {
        const res = await fetchJSON(api('settings'));
        const raw = res?.settings?.billing?.hourly_rate ?? null;
        const v = parseMoneyLike(raw);
        return (v && v > 0) ? v : null;
      } catch { return null; }
    }

    const hourlyFromOverview = await getHourlyRateFromOverview();
    const hourlyFromLoad     = parseMoneyLike(settings?.billing?.hourly_rate ?? null);
    const hourlyRateResolved = hourlyFromOverview ?? hourlyFromLoad; // puede ser null

    const debounce = (fn, ms = 500) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };

    async function lookupNit(nitRaw) {
      const nit = (nitRaw ?? '').toString().trim().toUpperCase();
      const q = nit === 'CF' ? 'CF' : nit.replace(/\D+/g, '');
      return await fetchJSON(api(`g4s/lookup-nit?nit=${encodeURIComponent(q)}`), { method: 'GET' });
    }

    const escapeHtml = (v) => String(v ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');

    const formatCurrency = (value) => {
      if (value === null || value === undefined || value === '') return '—';
      const num = Number(value);
      if (!Number.isFinite(num)) return '—';
      try { return num.toLocaleString('es-GT', { style: 'currency', currency: 'GTQ' }); }
      catch { return `Q${num.toFixed(2)}`; }
    };

    const formatDateTime = (isoLike) => {
      try { return new Date(isoLike).toLocaleString('es-GT'); } catch { return String(isoLike ?? ''); }
    };

    const formatRelativeTime = (isoLike) => {
      try {
        const d = new Date(isoLike).getTime();
        const now = Date.now();
        const diff = Math.round((now - d) / 1000);
        if (!Number.isFinite(diff)) return '';
        if (diff < 60) return `hace ${diff}s`;
        const m = Math.round(diff/60); if (m < 60) return `hace ${m}m`;
        const h = Math.round(m/60); if (h < 24) return `hace ${h}h`;
        const dd = Math.round(h/24); return `hace ${dd}d`;
      } catch { return ''; }
    };

    const app = document.getElementById('app') || document.body;
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
            <div class="flex-grow-1">
              <h5 class="card-title mb-1">Facturación (BD → G4S)</h5>
              <p class="text-muted small mb-1">
                Lista tickets <strong>CLOSED</strong> con pagos (o monto) y <strong>sin factura</strong>.
              </p>
              <div class="text-muted small" id="invoiceHourlyRateHint"></div>
            </div>

            <div class="ms-auto d-flex flex-wrap align-items-center gap-2" style="max-width: 520px;">
              <input type="search" id="invSearch" class="form-control form-control-sm" placeholder="Buscar ticket..." />
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-warning" id="btnManualOpen" title="Abrir SALIDA">
                  <i class="bi bi-box-arrow-right me-1"></i> Salida
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnManualOpenIn" title="Abrir ENTRADA">
                  <i class="bi bi-box-arrow-in-left me-1"></i> Entrada
                </button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Ticket / Placa</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th>UUID</th>
                  <th class="text-center">Acción</th>
                </tr>
              </thead>
              <tbody id="invRows">
                <tr>
                  <td colspan="5" class="text-muted text-center py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                    Cargando…
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3">
            <small class="text-muted" id="invMeta"></small>
            <div class="btn-group btn-group-sm" role="group">
              <button type="button" class="btn btn-outline-secondary" id="invPrev">
                <i class="bi bi-chevron-left"></i> Anterior
              </button>
              <button type="button" class="btn btn-outline-secondary" id="invNext">
                Siguiente <i class="bi bi-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ✅ Toast grandes -->
      <style>
        .toast.toast-lg{
          min-width: 420px;
          max-width: 520px;
          font-size: 1rem;
          border-radius: 14px;
        }
        .toast.toast-lg .toast-body{
          padding: 14px 16px;
        }
        .toast.toast-lg .fw-semibold{
          font-size: 1.1rem;
        }
        @media (max-width: 576px){
          .toast.toast-lg{ min-width: 92vw; max-width: 92vw; }
        }
      </style>
      <div class="toast-container position-fixed top-0 end-0 p-3"
          style="z-index:1080;" id="toastContainer"></div>

      <!-- Modal de confirmación -->
      <div class="modal fade" id="invoiceConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <form class="modal-content" id="invoiceConfirmForm">
            <div class="modal-header">
              <h5 class="modal-title">Confirmar cobro</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"
                      aria-label="Cerrar" data-action="cancel"></button>
            </div>
            <div class="modal-body">
              <div class="border rounded p-2 mb-3 bg-light">
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Ticket</span><span id="mSumTicket">—</span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Fecha</span><span id="mSumFecha">—</span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                  <span class="text-muted">Tiempo computado</span><span id="mSumHoras">—</span>
                </div>
                <div class="d-flex justify-content-between small" id="mSumTotalHourlyRow" hidden>
                  <span class="text-muted">Total por hora</span><span id="mSumTotalHourly">—</span>
                </div>
              </div>

              <div class="form-check">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeHourly" value="hourly">
                <label class="form-check-label" for="mModeHourly">
                  Cobro por hora <strong class="ms-1" id="mHourlyLabel"></strong>
                </label>
                <div class="form-text" id="mHourlyHelp">Tarifa × horas (ceil de minutos/60).</div>
              </div>

              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeGrace" value="grace">
                <label class="form-check-label" for="mModeGrace">
                  Ticket de gracia <strong class="ms-1">Q0.00</strong>
                </label>
                <div class="form-text">No se cobra ni se envía a FEL; se notifica a PayNotify.</div>
              </div>

              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="billingMode" id="mModeCustom" value="custom">
                <label class="form-check-label" for="mModeCustom">Cobro personalizado</label>
              </div>

              <div class="input-group input-group-sm mt-2">
                <span class="input-group-text">Q</span>
                <input type="number" step="0.01" min="0" class="form-control"
                      id="mCustomInput" placeholder="0.00" disabled>
              </div>

              <div class="mt-3">
                <label for="mDiscountCode" class="form-label mb-1">Código de descuento (QR)</label>
                <div class="input-group input-group-sm">
                  <input type="text" id="mDiscountCode" class="form-control" placeholder="Escanea o escribe el código">
                  <button type="button" class="btn btn-outline-secondary" id="mDiscountCheck">
                    <i class="bi bi-qr-code-scan me-1"></i> Validar
                  </button>
                </div>
                <div class="form-text">Cupones generados en el módulo Descuentos. Cada código se usa una sola vez.</div>
                <div class="small mt-1 text-muted" id="mDiscountStatus" aria-live="polite"></div>
              </div>

              <div class="d-flex justify-content-between small" id="mSumDiscountRow" hidden>
                <span class="text-muted">Descuento aplicado</span><span id="mSumDiscount">Q0.00</span>
              </div>

              <div class="mt-3">
                <label for="mNit" class="form-label mb-1">NIT del cliente</label>
                <input type="text" id="mNit" class="form-control"
                      placeholder='Escribe el NIT o "CF"' value="CF"
                      autocomplete="off" inputmode="numeric"
                      aria-describedby="mNitHelp mNitStatus">
                <div class="form-text" id="mNitHelp">
                  Escribe el NIT o “CF”. Si ingresas NIT, se consultará en SAT (G4S).
                </div>
                <div class="small mt-1 text-muted" id="mNitStatus" aria-live="polite"></div>
              </div>

              <div class="text-danger small mt-2" id="mError" hidden></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancel">Cancelar</button>
              <button type="submit" class="btn btn-primary btn-sm">Confirmar</button>
            </div>
          </form>
        </div>
      </div>
    `;

    const tbody = document.getElementById('invRows');
    const searchInput = document.getElementById('invSearch');
    const meta = document.getElementById('invMeta');
    const prevBtn = document.getElementById('invPrev');
    const nextBtn = document.getElementById('invNext');
    const hourlyRateHint = document.getElementById('invoiceHourlyRateHint');

    const state = { search: '', page: 1 };
    const pageSize = 20;
    let allRows = [];

    // ==========================
    // ✅ Toast helper (GRANDE, NO autocierra)
    // ==========================
    function showToast({ variant='success', title='Listo', message='' }) {
      const container = document.getElementById('toastContainer');

      if (!container || !window.bootstrap?.Toast) {
        console.log('[toast fallback]', title, message);
        return;
      }

      const icon = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
        primary: 'bi-info-circle-fill'
      }[variant] || 'bi-info-circle-fill';

      const el = document.createElement('div');
      el.className = `toast toast-lg align-items-center text-bg-${variant} border-0 shadow`;
      el.setAttribute('role', 'alert');
      el.setAttribute('aria-live', 'assertive');
      el.setAttribute('aria-atomic', 'true');

      // 🔒 NO autocierre
      el.setAttribute('data-bs-autohide', 'false');

      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">
            <div class="fw-semibold d-flex align-items-center gap-2 mb-1">
              <i class="bi ${icon}" style="font-size:1.25rem"></i>
              <span>${escapeHtml(title)}</span>
            </div>
            <div class="small" style="font-size:.98rem; line-height:1.35; white-space:pre-wrap;">
              ${escapeHtml(message)}
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto"
                  data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
      `;

      container.appendChild(el);
      const t = new bootstrap.Toast(el, { autohide: false });
      el.addEventListener('hidden.bs.toast', () => el.remove());
      t.show();
    }

    // Aperturas manuales
    const CHANNEL_SALIDA  = '40288048981adc4601981b7cb2660b05';
    const CHANNEL_ENTRADA = '40288048981adc4601981b7c2d010aff';

    function wireManualOpen(buttonEl, { title, channelId }) {
      if (!buttonEl || buttonEl.dataset.bound === '1') return;
      buttonEl.dataset.bound = '1';
      let busy = false;

      buttonEl.addEventListener('click', async () => {
        if (busy) return;
        const proceed = window.confirm(`¿Aperturar barrera manual (${title})?`);
        if (!proceed) return;

        let reason = window.prompt(`Motivo de apertura (${title}):`) || '';
        reason = reason.trim();
        if (reason.length < 5) {
          showToast({ variant:'warning', title:'Motivo muy corto', message:'Ingresa al menos 5 caracteres.' });
          return;
        }
        if (reason.length > 255) {
          showToast({ variant:'warning', title:'Motivo muy largo', message:'Máximo 255 caracteres.' });
          return;
        }

        busy = true;
        buttonEl.disabled = true;
        const original = buttonEl.innerHTML;
        buttonEl.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Aperturando…`;

        try {
          const res = await fetchJSON(api('gate/manual-open'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason, channel_id: channelId })
          });

          if (!res.ok) throw new Error(res.message || 'No se pudo aperturar');
          showToast({ variant:'success', title:'Barrera aperturada', message:`Apertura manual (${title}) ejecutada.` });
        } catch (e) {
          showToast({ variant:'danger', title:'Error al aperturar', message: e.message });
        } finally {
          buttonEl.disabled = false;
          buttonEl.innerHTML = original;
          busy = false;
        }
      });
    }

    wireManualOpen(document.getElementById('btnManualOpen'),   { title: 'Salida',  channelId: CHANNEL_SALIDA });
    wireManualOpen(document.getElementById('btnManualOpenIn'), { title: 'Entrada', channelId: CHANNEL_ENTRADA });

    if (hourlyRateHint) {
      hourlyRateHint.textContent =
        Number.isFinite(hourlyRateResolved) && hourlyRateResolved > 0
          ? `Tarifa por hora ${formatCurrency(hourlyRateResolved)}.`
          : 'Configura una tarifa por hora en Ajustes para habilitar los cálculos automáticos.';
    }

    function filterRows() {
      if (!state.search) return allRows;
      const term = state.search.toLowerCase();
      return allRows.filter((row) =>
        Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term))
      );
    }

    const buildPayload = (row) => ({
      ticket_no: row.ticket_no,
      plate: row.plate,
      receptor_nit: row.receptor || 'CF',
      serie: row.serie || 'A',
      numero: row.numero || null,
      fecha: row.fecha ?? null,
      total: row.total ?? null,
      hours: row.hours ?? row.duration_minutes ?? null,
      duration_minutes: row.duration_minutes ?? null,
      entry_at: row.entry_at ?? null,
      exit_at: row.exit_at ?? null,
    });

    // ---- Modal de confirmación
    function openInvoiceConfirmation(ticket, billingConfig = {}) {
      return new Promise((resolve) => {
        // minutos
        let minutes = null;
        const dm = Number(ticket.duration_minutes);
        if (Number.isFinite(dm) && dm > 0) minutes = Math.round(dm);
        else {
          const rawH = Number(ticket.hours);
          if (Number.isFinite(rawH) && rawH > 0) minutes = Math.round(rawH > 12 ? rawH : rawH * 60);
        }

        const hoursBilled = Number.isFinite(minutes) && minutes > 0 ? Math.ceil(minutes / 60) : null;

        const hourlyRateNumber = Number(billingConfig?.hourly_rate ?? null);
        const hourlyRate = Number.isFinite(hourlyRateNumber) && hourlyRateNumber > 0 ? hourlyRateNumber : null;

        const canHourly   = hourlyRate !== null && Number.isFinite(hoursBilled) && hoursBilled > 0;
        const hourlyTotal = canHourly ? Math.round(hoursBilled * hourlyRate * 100) / 100 : null;

        const dateCandidate = ticket.exit_at || ticket.entry_at || ticket.fecha || null;

        // Refs modal
        const modalEl  = document.getElementById('invoiceConfirmModal');
        const form     = document.getElementById('invoiceConfirmForm');
        const mErr     = document.getElementById('mError');
        const mCustom  = document.getElementById('mCustomInput');
        const mHourly  = document.getElementById('mModeHourly');
        const mGrace   = document.getElementById('mModeGrace');
        const mCustomR = document.getElementById('mModeCustom');
        const mNit     = document.getElementById('mNit');
        const mNitStatus = document.getElementById('mNitStatus');
        const mHourlyLabel = document.getElementById('mHourlyLabel');
        const mHourlyHelp  = document.getElementById('mHourlyHelp');
        const mDiscountCode = document.getElementById('mDiscountCode');
        const mDiscountCheck = document.getElementById('mDiscountCheck');
        const mDiscountStatus = document.getElementById('mDiscountStatus');
        const mSumDiscountRow = document.getElementById('mSumDiscountRow');
        const mSumDiscount = document.getElementById('mSumDiscount');
        let appliedDiscount = null;

        // Resumen
        document.getElementById('mSumTicket').textContent = String(ticket.ticket_no ?? '');
        document.getElementById('mSumFecha').textContent  =
          dateCandidate ? `${formatDateTime(dateCandidate)} (${formatRelativeTime(dateCandidate)})` : '—';
        document.getElementById('mSumHoras').textContent  =
          Number.isFinite(minutes) && minutes > 0 ? `${hoursBilled} h (ceil), ${minutes} min` : '—';

        const totalHourlyRow = document.getElementById('mSumTotalHourlyRow');
        if (hourlyTotal != null) {
          totalHourlyRow.hidden = false;
          document.getElementById('mSumTotalHourly').textContent = formatCurrency(hourlyTotal);
          mHourlyLabel.textContent = formatCurrency(hourlyTotal);
          mHourlyHelp.textContent  = `Tarifa ${formatCurrency(hourlyRate)} × ${hoursBilled} h (ceil).`;
        } else {
          totalHourlyRow.hidden = true;
          mHourlyLabel.textContent = '';
          mHourlyHelp.textContent  = 'Define una tarifa por hora válida.';
        }
        // Estado inicial
        mHourly.disabled = !canHourly;
        if (canHourly) { mHourly.checked = true; mCustom.disabled = true; }
        else           { mGrace.checked = true; mCustom.disabled = true; }

        // NIT
        mNit.value = 'CF';
        mErr.hidden = true; mErr.textContent = '';
        mNitStatus.className = 'small mt-1 text-muted';
        mNitStatus.textContent = 'Consumidor final (CF).';

        const normalizeNit = (v) => {
          v = (v || '').toUpperCase().trim();
          if (v === 'CF' || v === 'C') return v.length === 1 ? 'C' : 'CF';
          return v.replace(/\D+/g, '');
        };

        const setNitStatus = (text, cls = 'text-muted') => {
          mNitStatus.className = `small mt-1 ${cls}`;
          mNitStatus.textContent = text || '';
        };

        const setDiscountStatus = (text, cls = 'text-muted') => {
          if (!mDiscountStatus) return;
          mDiscountStatus.className = `small mt-1 ${cls}`;
          mDiscountStatus.textContent = text || '';
        };

        const updateDiscountSummary = (baseTotal) => {
          if (!mSumDiscountRow) return;
          if (appliedDiscount && appliedDiscount.amount > 0) {
            mSumDiscountRow.hidden = false;
            mSumDiscount.textContent = formatCurrency(appliedDiscount.amount);
          } else {
            mSumDiscountRow.hidden = true;
            mSumDiscount.textContent = 'Q0.00';
          }
          if (Number.isFinite(baseTotal) && canHourly && mHourlyLabel) {
            const net = Math.max(0, baseTotal - (appliedDiscount?.amount || 0));
            mHourlyLabel.textContent = formatCurrency(net);
          }
        };

        const validateDiscount = async () => {
          if (!mDiscountCode) return null;
          const code = mDiscountCode.value.trim();
          if (!code) {
            appliedDiscount = null;
            updateDiscountSummary(hourlyTotal ?? 0);
            setDiscountStatus('Ingresa o escanea un código.', 'text-muted');
            return null;
          }
          try {
            setDiscountStatus('Validando…', 'text-info');
            const res = await fetchJSON(api(`discounts/lookup?code=${encodeURIComponent(code)}`));
            const status = (res?.status || '').toUpperCase();
            if (!res?.ok || status !== 'NEW') {
              appliedDiscount = null;
              setDiscountStatus(res?.error || 'No disponible', 'text-danger');
              updateDiscountSummary(hourlyTotal ?? 0);
              return null;
            }
            appliedDiscount = { code: res.code, amount: Number(res.amount) || 0, description: res.description || '' };
            setDiscountStatus(`Descuento válido: ${formatCurrency(appliedDiscount.amount)}${appliedDiscount.description ? ' · ' + appliedDiscount.description : ''}`, 'text-success');
            updateDiscountSummary(hourlyTotal ?? 0);
            return appliedDiscount;
          } catch (err) {
            appliedDiscount = null;
            setDiscountStatus(err.message || 'Error al validar', 'text-danger');
            updateDiscountSummary(hourlyTotal ?? 0);
            return null;
          }
        };

        updateDiscountSummary(hourlyTotal ?? 0);

        const doLookup = debounce(async () => {
          const v = mNit.value;
          if (!v || v.toUpperCase() === 'CF') { setNitStatus('Consumidor final (CF).', 'text-muted'); return; }
          if (!/\d{6,}/.test(v.replace(/\D+/g,''))) { setNitStatus('Ingresa al menos 6 dígitos para consultar.', 'text-muted'); return; }
          try {
            setNitStatus('Buscando en SAT…', 'text-info');
            const res = await lookupNit(v);
            if (res?.ok) {
              const nombre = (res.nombre || res.name || '').trim();
              const dir = (res.direccion || res.address || '').trim();
              setNitStatus(`Encontrado: ${nombre || '(sin nombre)'}${dir ? ' — ' + dir : ''}`, 'text-success');
            } else {
              setNitStatus(res?.error ? `No encontrado: ${res.error}` : 'NIT no encontrado.', 'text-warning');
            }
          } catch (err) {
            setNitStatus(`Error al consultar: ${err.message || err}`, 'text-danger');
          }
        }, 500);

        mNit.oninput = (e) => {
          const cur = e.target.value;
          const norm = normalizeNit(cur);
          if (norm !== cur) {
            e.target.value = norm;
            e.target.setSelectionRange(norm.length, norm.length);
          }
          doLookup();
        };

        function updateCustomState() {
          const isCustom = mCustomR.checked;
          mCustom.disabled = !isCustom;
          if (isCustom) { mCustom.focus(); mCustom.select(); }
          mErr.hidden = true; mErr.textContent = '';
        }
        mHourly.onchange = updateCustomState;
        mGrace.onchange  = updateCustomState;
        mCustomR.onchange= updateCustomState;

        if (mDiscountCheck) {
          mDiscountCheck.addEventListener('click', () => { void validateDiscount(); });
        }
        if (mDiscountCode) {
          mDiscountCode.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); void validateDiscount(); }
          });
          mDiscountCode.addEventListener('blur', () => { void validateDiscount(); });
        }

        function closeModal(retVal = null) {
          modalEl.classList.remove('show');
          modalEl.style.display = 'none';
          const oldBackdrop = document.querySelector('.modal-backdrop');
          if (oldBackdrop) oldBackdrop.remove();
          document.body.classList.remove('modal-open');
          document.body.style.removeProperty('padding-right');
          resolve(retVal);
        }

        form.querySelectorAll('[data-action="cancel"]').forEach((b) =>
          b.addEventListener('click', () => closeModal(null), { once: true })
        );
        modalEl.onclick = (ev) => { if (ev.target === modalEl) closeModal(null); };

        document.addEventListener('keydown', function onEsc(ev){
          if (ev.key === 'Escape') { ev.preventDefault(); closeModal(null); }
        }, { once: true });

        // submit
        form.onsubmit = async (e) => {
          e.preventDefault();
          mErr.hidden = true; mErr.textContent = '';
          const selected = form.querySelector('input[name="billingMode"]:checked')?.value;
          if (!selected) { mErr.textContent = 'Selecciona el tipo de cobro.'; mErr.hidden = false; return; }

          const rawNit = mNit.value.trim().toUpperCase();
          const receptorNit = (rawNit === 'CF' || rawNit === '') ? 'CF' : rawNit.replace(/\D+/g, '');
          if (receptorNit !== 'CF' && !/^\d{6,}$/.test(receptorNit)) {
            mErr.textContent = 'NIT inválido. Debe ser CF o solo dígitos (mín. 6).';
            mErr.hidden = false; return;
          }

          const discountInput = mDiscountCode ? mDiscountCode.value.trim() : '';
          if (discountInput && (!appliedDiscount || appliedDiscount.code !== discountInput)) {
            const validated = await validateDiscount();
            if (!validated) {
              mErr.textContent = 'El descuento no es válido o ya fue usado.';
              mErr.hidden = false; return;
            }
          }

          if (selected === 'hourly') {
            if (!canHourly || hourlyTotal == null) {
              mErr.textContent = 'Configura una tarifa por hora válida.';
              mErr.hidden = false; return;
            }
            const netTotal = appliedDiscount ? Math.max(0, hourlyTotal - (appliedDiscount.amount || 0)) : hourlyTotal;
            closeModal({
              mode: 'hourly',
              total: netTotal,
              label: `Cobro por hora ${formatCurrency(netTotal)}${appliedDiscount ? ' (con descuento)' : ''}`,
              receptor_nit: receptorNit,
              duration_minutes: minutes,
              hours_billed_used: hoursBilled,
              hourly_rate_used: hourlyRate,
              discount_code: appliedDiscount?.code || null,
              discount_amount: appliedDiscount?.amount || null,
            });
            return;
          }

          if (selected === 'grace') {
            closeModal({
              mode: 'grace',
              total: 0,
              label: 'Ticket de gracia (Q0.00, sin FEL)',
              receptor_nit: receptorNit,
              discount_code: appliedDiscount?.code || null,
              discount_amount: appliedDiscount?.amount || null,
            });
            return;
          }

          const customVal = parseFloat(mCustom.value);
          if (!Number.isFinite(customVal) || customVal <= 0) {
            mErr.textContent = 'Ingresa un total personalizado mayor a cero.';
            mErr.hidden = false; return;
          }
          const normalized = Math.round(customVal * 100) / 100;
          const netCustom = appliedDiscount ? Math.max(0, normalized - (appliedDiscount.amount || 0)) : normalized;
          closeModal({
            mode: 'custom',
            total: netCustom,
            label: `Cobro personalizado ${formatCurrency(netCustom)}${appliedDiscount ? ' (con descuento)' : ''}`,
            receptor_nit: receptorNit,
            discount_code: appliedDiscount?.code || null,
            discount_amount: appliedDiscount?.amount || null,
          });
        };

        // mostrar modal (manual)
        modalEl.style.display = 'block';
        setTimeout(() => modalEl.classList.add('show'), 10);
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');
      });
    }

    function attachInvoiceHandlers() {
      tbody.querySelectorAll('[data-action="invoice"]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
          btn.disabled = true;
          const originalHTML = btn.innerHTML;
          btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Confirmando…`;

          try {
            const settingsSnapshot = await loadSettings();
            const hourlySnapshotOverview = await getHourlyRateFromOverview();
            const hourlySnapshotLoad     = parseMoneyLike(settingsSnapshot?.billing?.hourly_rate);
            const hourlySnapshot         = hourlySnapshotOverview ?? hourlySnapshotLoad ?? null;

            const billingCfg = { ...(settingsSnapshot?.billing ?? {}), hourly_rate: hourlySnapshot };
            const confirmation = await openInvoiceConfirmation(payload, billingCfg);
            if (!confirmation) { btn.disabled = false; btn.innerHTML = originalHTML; return; }

            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Enviando…`;

            const receptorNit = (confirmation.receptor_nit || payload.receptor_nit || 'CF').toString().toUpperCase();

            const requestPayload = {
              ticket_no: payload.ticket_no,
              plate: payload.plate,
              receptor_nit: receptorNit,
              serie: payload.serie || 'A',
              numero: payload.numero || null,
              mode: confirmation.mode
            };

            if (confirmation.discount_code) {
              requestPayload.discount_code = confirmation.discount_code;
              requestPayload.discount_amount_client = confirmation.discount_amount ?? null;
            }

            if (confirmation.mode === 'hourly') {
              const tot = Number(confirmation.total);
              requestPayload.total = Number.isFinite(tot) ? Math.round(tot * 100) / 100 : 0;
              requestPayload.duration_minutes  = Number.isFinite(Number(confirmation.duration_minutes)) ? Number(confirmation.duration_minutes) : 0;
              requestPayload.hours_billed_used = Number.isFinite(Number(confirmation.hours_billed_used)) ? Number(confirmation.hours_billed_used) : 0;
              requestPayload.hourly_rate_used  = Number.isFinite(Number(confirmation.hourly_rate_used)) ? Number(confirmation.hourly_rate_used) : 0;

            } else if (confirmation.mode === 'custom') {
              const mCustomEl = document.getElementById('mCustomInput');
              const uiVal = mCustomEl ? Number(mCustomEl.value) : NaN;

              let val = Number(confirmation.total);
              if (!Number.isFinite(val) || val <= 0) val = uiVal;

              if (!Number.isFinite(val) || val <= 0) {
                showToast({ variant:'warning', title:'Monto inválido', message:'Debe ser mayor a 0.' });
                btn.disabled = false;
                btn.innerHTML = originalHTML;
                return;
              }
              const norm = Math.round(val * 100) / 100;
              requestPayload.custom_total = norm;
              requestPayload.total        = norm;

              requestPayload.duration_minutes  = 0;
              requestPayload.hours_billed_used = 0;
              requestPayload.hourly_rate_used  = 0;

            } else if (confirmation.mode === 'grace') {
              requestPayload.duration_minutes  = 0;
              requestPayload.hours_billed_used = 0;
              requestPayload.hourly_rate_used  = 0;
            }

            console.log('REQ → /api/fel/invoice', requestPayload);

            const js = await fetchJSON(api('fel/invoice'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(requestPayload),
            });

            console.log('RESP ← /api/fel/invoice', js);

            // ✅ Mostrar error real de FEL si falla
            if (!js.ok) {
              const errTxt =
                (js.error && String(js.error).trim()) ||
                (js.message && String(js.message).trim()) ||
                (js.fel_error && String(js.fel_error).trim()) ||
                (js.g4s_error && String(js.g4s_error).trim()) ||
                'No se pudo certificar.';
              throw new Error(errTxt);
            }

            const uuidTxt = js.uuid ? `UUID: ${js.uuid}` : 'Sin UUID';

            // toast principal de factura OK
            showToast({
              variant: 'success',
              title: 'Factura enviada',
              message: `${confirmation.label}. ${uuidTxt}`
            });

            // ============================
            // ✅ PAYNOTIFY: verde si OK, amarillo si falla
            // ============================
            const paySent = js.pay_notify_sent === true;
            const payAck  = js.pay_notify_ack === true;
            const payFail = !!js.pay_notify_error || js.manual_open || (paySent && !payAck);

            const pnJson = js.pay_notify_json && typeof js.pay_notify_json === 'object'
              ? js.pay_notify_json
              : null;

            const pnRaw  = (js.pay_notify_raw || '').toString().trim();
            const pnHttp = js.pay_notify_http_code ? `HTTP ${js.pay_notify_http_code}` : 'HTTP —';

            // 🔹 Texto “completo” de la respuesta del API:
            //    - Si es JSON, pretty-print
            //    - Si no, el raw tal cual
            const pnPretty = pnJson
              ? JSON.stringify(pnJson, null, 2)
              : (pnRaw || 'Sin detalle');

            if (payFail) {
              // ⚠️ Falla: mostramos TODO lo que devolvió el API
              showToast({
                variant: 'warning',
                title: 'PayNotify falló — Apertura manual requerida',
                message: `${js.pay_notify_error || 'No hubo ACK válido'}\n${pnHttp}\n${pnPretty}`
              });

              const tr = btn.closest('tr');
              if (tr) {
                const uuidCell = tr.querySelector('td:nth-child(4)');
                if (uuidCell) {
                  uuidCell.innerHTML = `
                    <div style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                      ${escapeHtml(js.uuid || '')}
                    </div>
                    <div class="small text-warning mt-1" style="white-space:normal;">
                      <i class="bi bi-exclamation-triangle me-1"></i>
                      Apertura manual requerida.<br/>
                      Respuesta API:<br/>
                      <pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">${escapeHtml(pnPretty)}</pre>
                    </div>
                  `;
                }
              }

            } else if (paySent && payAck) {
              // ✅ Éxito: también mostramos TODO el JSON/RAW
              showToast({
                variant: 'success',
                title: 'PayNotify OK',
                message: `${pnHttp}\n${pnPretty}`
              });

              const tr = btn.closest('tr');
              if (tr) {
                const uuidCell = tr.querySelector('td:nth-child(4)');
                if (uuidCell) {
                  uuidCell.innerHTML = `
                    <div style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                      ${escapeHtml(js.uuid || '')}
                    </div>
                    <div class="small text-success mt-1" style="white-space:normal;">
                      <i class="bi bi-check-circle me-1"></i>
                      PayNotify OK<br/>
                      <pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">${escapeHtml(pnPretty)}</pre>
                    </div>
                  `;
                }
              }
            }

            await loadList();

          } catch (e) {
            showToast({
              variant: 'danger',
              title: 'Error al facturar',
              message: e.message || String(e)
            });
          } finally {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
          }
        });
      });
    }

    function renderPage() {
      const filtered = filterRows();
      const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (state.page > totalPages) state.page = totalPages;

      const start = (state.page - 1) * pageSize;
      const pageRows = filtered.slice(start, start + pageSize);

      if (!pageRows.length) {
        const message = allRows.length && !filtered.length
          ? 'No se encontraron resultados'
          : 'No hay pendientes por facturar.';
        tbody.innerHTML = `
          <tr>
            <td colspan="5" class="text-muted text-center py-4">${escapeHtml(message)}</td>
          </tr>`;
      } else {
        tbody.innerHTML = pageRows.map((d) => {
          const totalFmt = formatCurrency(parseMoneyLike(d.total));
          const payload = encodeURIComponent(JSON.stringify(buildPayload(d)));
          const disabled = d.uuid ? 'disabled' : '';
          const ticketText = d.ticket_no
            ? `${d.ticket_no}${d.plate ? ' · ' + d.plate : ''}`
            : (d.plate ?? '(sin placa)');
          return `
            <tr>
              <td>${escapeHtml(ticketText)}</td>
              <td>${escapeHtml(d.fecha ?? '')}</td>
              <td class="text-end">${totalFmt}</td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                ${escapeHtml(d.uuid ?? '')}
              </td>
              <td class="text-center">
                <button class="btn btn-sm btn-outline-success" data-action="invoice"
                        data-payload="${payload}" ${disabled}>
                  <i class="bi bi-receipt me-1"></i> Facturar
                </button>
              </td>
            </tr>`;
        }).join('');
        attachInvoiceHandlers();
      }

      if (filtered.length) {
        meta.textContent =
          `Mostrando ${start + 1} - ${Math.min(start + pageRows.length, filtered.length)} de ${filtered.length} tickets`;
      } else if (allRows.length) {
        meta.textContent = 'No se encontraron resultados para la búsqueda actual';
      } else {
        meta.textContent = 'Sin tickets pendientes por facturar';
      }

      prevBtn.disabled = state.page <= 1 || !filtered.length;
      nextBtn.disabled = state.page >= totalPages || !filtered.length;
    }

    searchInput.addEventListener('input', (event) => {
      state.search = event.target.value.trim();
      state.page = 1;
      renderPage();
    });

    prevBtn.addEventListener('click', () => {
      if (state.page > 1) { state.page -= 1; renderPage(); }
    });

    nextBtn.addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(filterRows().length / pageSize));
      if (state.page < totalPages) { state.page += 1; renderPage(); }
    });

    async function loadList() {
      tbody.innerHTML = `
        <tr>
          <td colspan="5" class="text-muted text-center py-4">
            <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
            Consultando BD…
          </td>
        </tr>`;
      meta.textContent = '';
      try {
        const js = await fetchJSON(api('facturacion/list'));
        if (!js.ok && js.error) throw new Error(js.error);
        allRows = js.rows || [];
        state.page = 1;
        renderPage();
      } catch (e) {
        tbody.innerHTML = `
          <tr><td colspan="5" class="text-danger text-center">
            Error: ${escapeHtml(e.message)}
          </td></tr>`;
        meta.textContent = '';
      }
    }

    await loadList();
  }
  Core.registerPage('invoices', renderInvoices);
})();
