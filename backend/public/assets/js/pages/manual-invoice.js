(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const { api, fetchJSON, escapeHtml, formatNumber, loadSettings } = Core;
  const { app } = Core.elements;

  async function renderManualInvoiceModule() {
    // ===== Bootstrap-friendly Dialog shim =====
    const Dialog = (() => {
      function ensureHost() {
        let host = document.getElementById('app-toast-host');
        if (!host) {
          host = document.createElement('div');
          host.id = 'app-toast-host';
          host.style.position = 'fixed';
          host.style.zIndex = '1080';
          host.style.inset = 'auto 1rem 1rem auto';
          document.body.appendChild(host);
        }
        return host;
      }
      function bsToast(message, { title, variant = 'primary', delay = 4000 } = {}) {
        const host = ensureHost();
        const wrap = document.createElement('div');
        wrap.className = 'toast align-items-center text-bg-' + variant;
        wrap.role = 'alert';
        wrap.ariaLive = 'assertive';
        wrap.ariaAtomic = 'true';
        wrap.innerHTML = `
          <div class="d-flex">
            <div class="toast-body">
              ${title ? `<div class="fw-semibold mb-1">${title}</div>` : ''}
              ${message ?? ''}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>`;
        host.appendChild(wrap);
        try {
          if (window.bootstrap?.Toast) {
            const t = new bootstrap.Toast(wrap, { delay, autohide: true });
            t.show();
          } else {
            wrap.classList.add('show');
            setTimeout(() => wrap.remove(), delay);
          }
        } catch {
          wrap.classList.add('show');
          setTimeout(() => wrap.remove(), delay);
        }
      }
      function bsAlert({ title, message, variant = 'warning' } = {}) {
        const host = ensureHost();
        const div = document.createElement('div');
        div.className = `alert alert-${variant} alert-dismissible fade show`;
        div.role = 'alert';
        div.style.minWidth = '280px';
        div.innerHTML = `
          ${title ? `<div class="fw-semibold mb-1">${title}</div>` : ''}
          <div>${message ?? ''}</div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        host.appendChild(div);
        return { close: () => div.remove(), el: div };
      }
      function loading({ title, message } = {}) {
        const wrap = document.createElement('div');
        wrap.innerHTML = `
          <div class="modal-backdrop fade show"></div>
          <div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:1080">
            <div class="card p-3 shadow-sm">
              <div class="d-flex align-items-center gap-3">
                <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                <div>
                  <div class="fw-semibold">${title ?? 'Cargando'}</div>
                  <div class="small text-muted">${message ?? ''}</div>
                </div>
              </div>
            </div>
          </div>`;
        const el = document.createElement('div');
        el.style.zIndex = 1080;
        el.appendChild(wrap);
        document.body.appendChild(el);
        return { close() { el.remove(); } };
      }
      const base = {
        loading,
        toast: (msg, opts) => bsToast(msg, opts),
        alert: (opts) => bsAlert(opts),
        ok: (message, title = 'Listo') => bsToast(message, { title, variant: 'success' }),
        err: (e, title = 'Error') => bsAlert({ title, message: (e?.message ?? String(e)), variant: 'danger' }),
      };
      const ext = window.Dialog || {};
      return {
        ...base,
        ...ext,
        loading: ext.loading || base.loading,
        toast: ext.toast || base.toast,
        alert: ext.alert || base.alert,
        ok: ext.ok || base.ok,
        err: ext.err || base.err,
      };
    })();

    const escapeHtml = (v) =>
      String(v ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

    // ===== PDF helpers =====
    function b64ToBlobPdf(b64){
      const byteChars = atob(b64), byteArrays = []; const chunk = 65536;
      for (let off=0; off<byteChars.length; off+=chunk){
        const slice = byteChars.slice(off, off+chunk);
        const arr = new Uint8Array(slice.length);
        for (let i=0;i<slice.length;i++) arr[i] = slice.charCodeAt(i);
        byteArrays.push(arr);
      }
      return new Blob(byteArrays, { type:'application/pdf' });
    }
    function downloadBase64Pdf(filename, b64){
      const url = URL.createObjectURL(b64ToBlobPdf(b64));
      const a = document.createElement('a'); a.href = url; a.download = filename || 'documento.pdf';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url), 1000);
    }
    async function fetchPdfForManualInvoice({ id, uuid }){
      if (uuid) {
        try { const r1 = await fetchJSON(api('fel/document-pdf') + '?uuid=' + encodeURIComponent(uuid));
              if (r1?.ok && r1.pdf_base64) return r1.pdf_base64; } catch {}
      }
      if (id) {
        try { const r2 = await fetchJSON(api('fel/manual-invoice/pdf') + '?id=' + encodeURIComponent(id));
              if (r2?.ok && r2.pdf_base64) return r2.pdf_base64; } catch {}
        try { const r3 = await fetchJSON(api('fel/manual-invoice/one') + '?id=' + encodeURIComponent(id));
              const b64 = r3?.data?.fel_pdf_base64 || r3?.fel_pdf_base64; if (b64) return b64; } catch {}
      }
      throw new Error('No se pudo obtener el PDF.');
    }

    // ===== Helpers dinero =====
    function parseMoneyLike(x){
      if (x == null) return null;
      if (typeof x === 'number') return Number.isFinite(x) ? x : null;
      const s = String(x).trim(); if (!s) return null;
      const cleaned = s.replace(/[^\d.,-]/g,'').replace(/,/g,'.');
      const n = Number(cleaned);
      return Number.isFinite(n) ? n : null;
    }

    // ===== Cargar settings (tarifa mensual) =====
    const settings = await loadSettings().catch(()=>null);
    async function getMonthlyRateFromOverview(){
      try {
        const res = await fetchJSON(api('settings'));
        const raw = res?.settings?.billing?.monthly_rate ?? null;
        const v = parseMoneyLike(raw);
        return (v && v > 0) ? v : null;
      } catch { return null; }
    }
    const monthlyRateNumber = await getMonthlyRateFromOverview();
    const hasMonthly = monthlyRateNumber != null && monthlyRateNumber > 0;

    // ===== State para paginado =====
    const state = {
      rows: [],
      page: 1,
      perPage: 25,
      pages: 1,
    };

    // ===== Render estático =====
    const app = document.getElementById('app') || document.body;
    app.innerHTML = `
      <div class="card p-3">
        <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
          <div class="flex-grow-1">
            <h5 class="mb-1">Factura manual</h5>
            <p class="text-muted small mb-1">
              Indica <strong>motivo</strong> y <strong>NIT</strong>; elige <strong>mensual</strong> o <strong>personalizado</strong>. (Siempre se envía a FEL)
            </p>
            <div class="text-muted small" id="monthlyRateHint">
              ${hasMonthly ? `Tarifa mensual actual: Q ${monthlyRateNumber.toFixed(2)}` : 'No hay tarifa mensual configurada.'}
            </div>
          </div>
          <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-primary" id="btnNewManualInv">Nueva factura manual</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshManualInv">Actualizar</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm mb-0" id="tblManualInvoices">
            <thead>
              <tr>
                <th>#</th><th>Fecha</th><th>Motivo</th><th>NIT</th><th>Monto</th>
                <th>Usó mensual</th><th>Estado FEL</th><th>UUID</th>
                <th class="text-end">PDF</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="9" class="text-center py-4">
                  <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                  <span class="ms-2">Cargando…</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <nav class="mt-3" aria-label="Paginación facturas">
          <ul class="pagination pagination-sm mb-0" id="manualInvPager"></ul>
        </nav>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="manualInvModal" tabindex="-1" aria-labelledby="manualInvLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content manual-inv-modal">
            <form id="manualInvForm" autocomplete="off">
              <div class="modal-header">
                <h5 class="modal-title" id="manualInvLabel">Nueva factura manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">

                <!-- Motivo -->
                <div class="mb-3">
                  <label class="form-label">Motivo</label>
                  <input type="text" class="form-control" name="reason" id="mReason" minlength="3" maxlength="255" required />
                  <div class="form-text">Este motivo se guardará junto con la factura.</div>
                </div>

                <!-- Resumen mensual -->
                <div class="border rounded p-2 mb-3 bg-light">
                  <div class="d-flex justify-content-between small">
                    <span class="text-muted">Tarifa mensual</span>
                    <span id="mMonthlyPreview">${hasMonthly ? 'Q ' + monthlyRateNumber.toFixed(2) : '—'}</span>
                  </div>
                </div>

                <!-- Opciones: mensual / personalizado -->
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="billingMode" id="mModeMonthly" value="monthly" ${hasMonthly ? '' : 'disabled'}>
                  <label class="form-check-label" for="mModeMonthly">
                    Cobro mensual ${hasMonthly ? `<strong class="ms-1">Q ${monthlyRateNumber.toFixed(2)}</strong>` : ''}
                  </label>
                  <div class="form-text">${hasMonthly ? 'Se aplicará la tarifa mensual configurada.' : 'Configura una tarifa mensual en Ajustes para habilitar esta opción.'}</div>
                </div>

                <div class="form-check mt-2">
                  <input class="form-check-input" type="radio" name="billingMode" id="mModeCustom" value="custom">
                  <label class="form-check-label" for="mModeCustom">Cobro personalizado</label>
                  <div class="form-text">Indica el total que deseas facturar manualmente.</div>
                </div>

                <div class="input-group input-group-sm mt-2">
                  <span class="input-group-text">Q</span>
                  <input type="number" step="0.01" min="0" class="form-control" id="mCustomInput" placeholder="0.00" disabled>
                </div>

                <!-- NIT (con estado debajo) -->
                <div class="mt-3">
                  <label class="form-label" for="mNit">NIT</label>
                  <div class="input-group">
                    <input type="text" class="form-control" name="nit" id="mNit" placeholder="CF o NIT" inputmode="text" pattern="^(?:\\d+|[Cc][Ff])$" autocomplete="off"/>
                    <button class="btn btn-outline-secondary" type="button" id="btnLookupNit" title="Buscar NIT">
                      <i class="bi bi-search"></i>
                    </button>
                  </div>
                  <div class="small mt-1 text-muted" id="mNitStatus" aria-live="polite">Escribe el NIT o “CF”.</div>
                </div>

              </div>
              <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="button" id="btnSubmitManualInv">Crear</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    `;

    // ===== Modal helper =====
    let manualInvModalRef = null;
    function bsModal(id){
      const el = document.getElementById(id);
      if (!el) return null;
      if (window.bootstrap?.Modal) {
        const inst = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
        return inst;
      }
      return {
        show(){
          el.classList.add('show'); el.style.display='block'; el.removeAttribute('aria-hidden');
          document.body.classList.add('modal-open');
          if (!document.querySelector('.modal-backdrop')) {
            const bd = document.createElement('div');
            bd.className = 'modal-backdrop fade show';
            document.body.appendChild(bd);
          }
        },
        hide(){
          el.classList.remove('show'); el.style.display='none'; el.setAttribute('aria-hidden','true');
          document.body.classList.remove('modal-open');
          const bd = document.querySelector('.modal-backdrop'); if (bd) bd.remove();
        },
      };
    }

    // ===== NIT: sanitización + lookup =====
    const nitInput   = document.getElementById('mNit');
    const mNitStatus = document.getElementById('mNitStatus');
    function sanitizeNit(raw){
      const v = (raw || '').trim();
      if (/^[Cc][Ff]$/.test(v)) return 'CF';
      return v.replace(/\D+/g,'');
    }
    function validateNitInput(){
      const v = nitInput.value.trim();
      if (!v) { nitInput.setCustomValidity(''); return; }
      if (v.toUpperCase()==='CF' || /^\d+$/.test(v)) nitInput.setCustomValidity('');
      else nitInput.setCustomValidity('Escribe solo números o “CF”.');
    }
    function setNitStatus(t, cls='text-muted'){ mNitStatus.className = `small mt-1 ${cls}`; mNitStatus.textContent = t || ''; }
    async function tryLookupNit(){
      const val = nitInput.value.trim();
      if (!val || val.toUpperCase()==='CF'){ setNitStatus('Consumidor final (CF).'); return; }
      if (!(val.toUpperCase()==='CF' || /^\d+$/.test(val))) return;
      const dlg = Dialog.loading({ title:'Buscando NIT', message:'Consultando…' });
      try{
        const res = await fetchJSON(api('g4s/lookup-nit')+'?nit='+encodeURIComponent(val));
        if (res?.ok !== false){
          const nombre = (res?.nombre || res?.name || '').trim();
          const dir = (res?.direccion || res?.address || '').trim();
          setNitStatus(`Encontrado${nombre ? ': ' + nombre : ''}${dir ? ' — ' + dir : ''}.`, 'text-success');
        }else{
          setNitStatus(res?.error ? `No encontrado: ${res.error}` : 'NIT no encontrado.', 'text-warning');
        }
      }catch{ setNitStatus('Error al consultar.', 'text-danger'); }
      finally{ dlg.close(); }
    }
    nitInput.addEventListener('input', ()=>{
      const clean = sanitizeNit(nitInput.value);
      if (clean !== nitInput.value){ nitInput.value = clean; nitInput.setSelectionRange(clean.length, clean.length); }
      validateNitInput();
      if (/^\d{6,}$/.test(nitInput.value)) { setTimeout(tryLookupNit, 600); }
      else if (nitInput.value.toUpperCase()==='CF') setNitStatus('Consumidor final (CF).');
      else setNitStatus('Ingresa al menos 6 dígitos para consultar.');
    });
    nitInput.addEventListener('blur', ()=>{ validateNitInput(); tryLookupNit(); });
    document.getElementById('btnLookupNit')?.addEventListener('click', tryLookupNit);

    // ===== Abrir modal =====
    document.getElementById('btnNewManualInv')?.addEventListener('click', () => {
      manualInvModalRef = bsModal('manualInvModal');
      manualInvModalRef?.show();

      const reasonEl    = document.getElementById('mReason');
      const modeMonthly = document.getElementById('mModeMonthly');
      const modeCustom  = document.getElementById('mModeCustom');
      const customInput = document.getElementById('mCustomInput');

      if (hasMonthly) modeMonthly.removeAttribute('disabled');

      // Estado inicial
      if (hasMonthly) {
        modeMonthly.checked = true;
        customInput.value = monthlyRateNumber.toFixed(2);
        customInput.disabled = true;
      } else {
        modeCustom.checked = true;
        customInput.value = '';
        customInput.disabled = false;
      }

      // Sync radios
      const sync = () => {
        if (modeMonthly.checked && hasMonthly) {
          customInput.value = monthlyRateNumber.toFixed(2);
          customInput.disabled = true;
        } else {
          customInput.disabled = false;
          if (!customInput.value || customInput.value === '0.00') customInput.value = '';
        }
      };
      modeMonthly.addEventListener('change', sync);
      modeCustom.addEventListener('change', sync);
      sync();

      setTimeout(()=>reasonEl?.focus(), 120);
    });

    // ===== Submit único (sin duplicados): onsubmit =====
    const submitBtn = document.getElementById('btnSubmitManualInv');
    submitBtn.onclick = async () => {
      const form = document.getElementById('manualInvForm');
      if (form.dataset.busy === '1') return;        // guard contra doble clic
      form.dataset.busy = '1';
      submitBtn.setAttribute('disabled', 'disabled');

      const modeMonthly = document.getElementById('mModeMonthly');
      const modeCustom  = document.getElementById('mModeCustom');
      const customInput = document.getElementById('mCustomInput');

      const reason = document.getElementById('mReason').value.trim();
      const nitRaw = document.getElementById('mNit').value.trim();
      const receptor_nit = nitRaw ? sanitizeNit(nitRaw).toUpperCase() : 'CF';

      if (reason.length < 3) { Dialog.alert({ title:'Falta motivo', message:'Escribe un motivo de al menos 3 caracteres.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }
      if (!(receptor_nit === 'CF' || /^\d+$/.test(receptor_nit))) { Dialog.alert({ title:'NIT inválido', message:'Escribe solo números o “CF”.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }

      let amount = 0, used_monthly = false, label = '';
      if (modeMonthly.checked && hasMonthly){
        used_monthly = true;
        amount = monthlyRateNumber;
        label = `Cobro mensual Q ${amount.toFixed(2)}`;
      } else {
        const v = Number(customInput.value);
        if (!Number.isFinite(v) || v <= 0) { Dialog.alert({ title:'Monto inválido', message:'Ingresa un total personalizado mayor a 0.' }); submitBtn.removeAttribute('disabled'); delete form.dataset.busy; return; }
        amount = Math.round(v * 100) / 100;
        label = `Cobro personalizado Q ${amount.toFixed(2)}`;
      }

      const payload = { reason, receptor_nit, amount, used_monthly, send_to_fel: true };
      const idemKey = (window.crypto?.randomUUID?.() || `mid-${Date.now()}-${Math.random().toString(16).slice(2)}`);

      const dlg = Dialog.loading({ title:'Creando', message:'Enviando datos…' });
      try {
        const res = await fetchJSON(api('fel/manual-invoice'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Idempotency-Key': idemKey },
          body: JSON.stringify(payload),
        });

        if (res?.ok) {
          const uuidTxt = res.uuid ? `\nUUID: ${res.uuid}` : '';
          Dialog.ok(`Factura creada y enviada a FEL.\n${label}${uuidTxt}`, 'Éxito');
          try {
            if (window.bootstrap?.Modal) {
              const el = document.getElementById('manualInvModal');
              const inst = bootstrap.Modal.getInstance(el) || manualInvModalRef;
              inst?.hide?.();
            } else { manualInvModalRef?.hide?.(); }
            document.querySelector('.modal-backdrop')?.remove();
            document.body.classList.remove('modal-open');
          } catch {}
          await refreshManualInvoicesTable();
        } else {
          throw new Error(res?.error || 'No se pudo crear la factura manual');
        }
      } catch (e) {
        Dialog.err(e, 'Error al crear factura');
      } finally {
        dlg.close();
        submitBtn.removeAttribute('disabled');
        delete form.dataset.busy;
      }
    };

    // ===== Tabla + paginación =====
    const tbody = document.querySelector('#tblManualInvoices tbody');
    const pager = document.getElementById('manualInvPager');

    function renderPager() {
      const { page, pages } = state;
      if (!pager) return;
      if (pages <= 1) { pager.innerHTML = ''; return; }

      const mkPageItem = (p, label = p, active = false, disabled = false) => `
        <li class="page-item ${active ? 'active' : ''} ${disabled ? 'disabled' : ''}">
          <a class="page-link" href="#" data-page="${p}">${label}</a>
        </li>
      `;

      const items = [];
      items.push(mkPageItem(Math.max(1, page - 1), 'Anterior', false, page === 1));

      // ventana de páginas (máx 7 links numerados)
      const windowSize = 7;
      let start = Math.max(1, page - Math.floor(windowSize/2));
      let end = Math.min(pages, start + windowSize - 1);
      if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

      if (start > 1) items.push(mkPageItem(1, '1', false, false));
      if (start > 2) items.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);

      for (let p = start; p <= end; p++) {
        items.push(mkPageItem(p, String(p), p === page, false));
      }

      if (end < pages - 1) items.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
      if (end < pages) items.push(mkPageItem(pages, String(pages), false, false));

      items.push(mkPageItem(Math.min(pages, page + 1), 'Siguiente', false, page === pages));

      pager.innerHTML = items.join('');

      pager.onclick = (ev) => {
        const a = ev.target.closest('a.page-link');
        if (!a) return;
        ev.preventDefault();
        const target = Number(a.dataset.page);
        if (!Number.isFinite(target) || target < 1 || target > state.pages || target === state.page) return;
        state.page = target;
        renderTablePage();
        renderPager();
      };
    }

    function renderTablePage() {
      const { rows, page, perPage } = state;
      if (!tbody) return;

      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">No hay facturas manuales registradas todavía.</td></tr>`;
        return;
      }

      const startIdx = (page - 1) * perPage;
      const slice = rows.slice(startIdx, startIdx + perPage);

      tbody.innerHTML = slice.map(r=>{
        const canPdf = (String(r?.fel_status||'').trim()==='OK') && !!r?.fel_uuid;
        const title = r?.receptor_name ? 'Nombre: ' + String(r.receptor_name).replace(/"/g,'&quot;') : '';
        return `<tr title="${title}">
          <td>${r.id ?? ''}</td>
          <td>${r.created_at ?? ''}</td>
          <td>${r.reason ? escapeHtml(r.reason) : ''}</td>
          <td>${r.receptor_nit ?? ''}</td>
          <td>Q ${Number(r.amount ?? 0).toFixed(2)}</td>
          <td>${r.used_monthly ? 'Sí' : 'No'}</td>
          <td>${r.fel_status ? escapeHtml(r.fel_status) : ''}</td>
          <td>${r.fel_uuid ? escapeHtml(r.fel_uuid) : ''}</td>
          <td class="text-end">
            <button type="button" class="btn btn-sm ${canPdf?'btn-outline-primary':'btn-outline-secondary'} btnManualPdf"
              data-id="${r.id ?? ''}" data-uuid="${r.fel_uuid ?? ''}" ${canPdf?'':'disabled'}
              title="${canPdf ? 'Descargar/abrir PDF' : 'Sin PDF disponible'}">
              <i class="bi bi-file-pdf"></i>
            </button>
          </td>
        </tr>`;
      }).join('');

      // Delegación para botón PDF (una vez)
      tbody.onclick = async (ev)=>{
        const btn = ev.target.closest?.('.btnManualPdf'); if (!btn) return;
        const id = btn.getAttribute('data-id'); const uuid = btn.getAttribute('data-uuid');
        const dlg = Dialog.loading({ title:'Obteniendo PDF', message:'Consultando…' });
        try {
          const b64 = await fetchPdfForManualInvoice({ id, uuid });
          downloadBase64Pdf((uuid?`${uuid}.pdf`:`manual-invoice-${id}.pdf`), b64);
          dlg.close(); Dialog.toast?.('PDF listo', { variant:'success' });
        } catch(err){
          dlg.close(); Dialog.alert({ title:'No disponible', message:String(err), variant:'warning' });
        }
      };
    }

    async function refreshManualInvoicesTable(){
      if (!tbody) return;
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4">
        <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div><span class="ms-2">Actualizando…</span></td></tr>`;
      try{
        const js = await fetchJSON(api('fel/manual-invoice-list'));
        const rows = Array.isArray(js?.data) ? js.data : (Array.isArray(js) ? js : []);
        state.rows = rows;
        state.page = 1;
        state.pages = Math.max(1, Math.ceil((rows.length || 0) / state.perPage));
        renderTablePage();
        renderPager();
      }catch(e){
        tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4"><div class="text-danger">Error al cargar.</div><div class="small text-muted">${escapeHtml(String(e))}</div></td></tr>`;
        pager.innerHTML = '';
      }
    }

    document.getElementById('btnRefreshManualInv')?.addEventListener('click', refreshManualInvoicesTable);
    await refreshManualInvoicesTable();
  }

  Core.registerPage('ManualInvoice', renderManualInvoiceModule);
})();

