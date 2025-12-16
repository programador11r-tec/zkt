(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const { api, fetchJSON, escapeHtml, formatDateTime } = Core;
  const { app } = Core.elements;

  const fmtQ = (v) => `Q${Number(v ?? 0).toFixed(2)}`;

  async function ensureBarcodeLib() {
    if (window.JsBarcode && typeof window.JsBarcode === 'function') return;
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';
      s.onload = () => resolve(null);
      s.onerror = () => reject(new Error('No se pudo cargar la librería de código de barras'));
      document.head.appendChild(s);
    });
  }

  async function renderBarcode(canvasEl, text, opts = {}) {
    if (!canvasEl) return;
    await ensureBarcodeLib();
    try {
      window.JsBarcode(canvasEl, text, Object.assign({
        format: 'CODE128',
        width: 2,
        height: 80,
        displayValue: false,
        margin: 2,
      }, opts));
    } catch (err) {
      console.warn('Barcode render failed', err);
      canvasEl.replaceWith(document.createTextNode(text));
    }
  }

  async function openPrintWindow(code, amount, description) {
    await ensureBarcodeLib();
    let dataUrl = null;
    try {
      const tmpCanvas = document.createElement('canvas');
      window.JsBarcode(tmpCanvas, code, { format: 'CODE128', width: 2, height: 80, displayValue: true, margin: 10 });
      dataUrl = tmpCanvas.toDataURL('image/png');
    } catch (err) {
      console.warn('Barcode dataURL failed', err);
    }

    const imgMarkup = dataUrl
      ? `<img src="${dataUrl}" alt="CB ${escapeHtml(code)}" style="width:320px;height:120px;" />`
      : `<div style="width:320px;height:120px;border:1px dashed #999;display:flex;align-items:center;justify-content:center;">${escapeHtml(code)}</div>`;

    const html = `
      <!doctype html>
      <html lang="es">
      <head>
        <meta charset="UTF-8" />
        <title>Descuento ${escapeHtml(code)}</title>
        <style>
          body { font-family: Arial, sans-serif; text-align: center; padding: 20px; }
          .card { display: inline-block; padding: 16px; border: 1px solid #ccc; border-radius: 8px; }
          h3 { margin: 8px 0; }
          .meta { color: #555; font-size: 12px; }
        </style>
      </head>
      <body>
        <div class="card">
          <h3>Código de descuento</h3>
          ${imgMarkup}
          <div><strong>${escapeHtml(code)}</strong></div>
          <div>${fmtQ(amount)}</div>
          <div class="meta">${escapeHtml(description || '(sin descripción)')}</div>
        </div>
      </body>
      </html>
    `;
    const win = window.open('', '_blank', 'width=420,height=520');
    if (!win) return;
    win.document.write(html);
    win.document.close();
    if (dataUrl) {
      setTimeout(() => { try { win.print(); } catch (_) {} }, 150);
    }
  }

  function renderList(rows, batchLabel = '') {
    if (!Array.isArray(rows) || !rows.length) {
      return '<div class="empty">Sin descuentos generados.</div>';
    }
    const header = batchLabel
      ? `<div class="alert alert-light py-2 px-3 border"><strong>Lote:</strong> ${escapeHtml(batchLabel)}</div>`
      : '';
    const items = rows.map((r) => {
      const status = String(r.status || '').toUpperCase();
      const badge =
        status === 'NEW' ? 'bg-success-subtle text-success-emphasis' :
        status === 'REDEEMED' ? 'bg-secondary' : 'bg-warning';
      return `
        <div class="col-md-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column gap-2">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold">${escapeHtml(r.code)}</div>
                  <div class="text-muted small">Lote: ${escapeHtml(r.batch_id || '')}</div>
                </div>
                <span class="badge ${badge}">${escapeHtml(status)}</span>
              </div>
              <div class="flex-grow-1 small">
                <div><strong>Monto:</strong> ${fmtQ(r.amount)}</div>
                <div><strong>Descripción:</strong> ${escapeHtml(r.description || '(sin descripción)')}</div>
                <div><strong>Creado:</strong> ${escapeHtml(formatDateTime(r.created_at))}</div>
                ${r.redeemed_ticket ? `<div><strong>Redimido:</strong> ${escapeHtml(r.redeemed_ticket)} ${escapeHtml(formatDateTime(r.redeemed_at))}</div>` : ''}
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" data-action="print" data-code="${escapeHtml(r.code)}" data-amount="${r.amount}" data-description="${escapeHtml(r.description || '')}">
                  <i class="bi bi-printer"></i> Imprimir
                </button>
                <button class="btn btn-outline-secondary btn-sm" data-action="copy" data-code="${escapeHtml(r.code)}">
                  <i class="bi bi-clipboard"></i> Copiar código
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
    }).join('');
    return header + items;
  }

  async function renderDiscounts() {
    app.innerHTML = `
      <div class="row g-4">
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h5 class="card-title mb-2">Nuevo descuento</h5>
              <p class="text-muted small">Genera cupones con código QR para aplicar en facturación.</p>
              <form id="discountForm" class="d-flex flex-column gap-3" autocomplete="off">
                <div>
                  <label class="form-label small mb-1" for="discAmount">Monto a descontar (GTQ)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Q</span>
                    <input type="number" step="0.01" min="0" id="discAmount" class="form-control" required>
                  </div>
                </div>
                <div>
                  <label class="form-label small mb-1" for="discDesc">Descripción</label>
                  <input type="text" id="discDesc" class="form-control form-control-sm" placeholder="Ej. Descuento cortesía">
                </div>
                <div>
                  <label class="form-label small mb-1" for="discQty">Cantidad de cupones</label>
                  <input type="number" min="1" max="50" value="1" id="discQty" class="form-control form-control-sm">
                </div>
                <button type="submit" class="btn btn-primary btn-sm" id="discSubmit">
                  <i class="bi bi-qr-code me-1"></i> Generar cupones
                </button>
                <div class="alert alert-info py-2 px-3 small mb-0 d-none" id="discInfo"></div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-xl-8">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex align-items-start justify-content-between">
                <div>
                  <h5 class="card-title mb-1">Cupones generados</h5>
                  <p class="text-muted small mb-0">Últimos 200 registros.</p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <select class="form-select form-select-sm" id="discBatchFilter" style="max-width:220px;">
                    <option value="all">Todos los lotes</option>
                  </select>
                  <button class="btn btn-outline-primary btn-sm" id="discPrintBatch"><i class="bi bi-printer"></i> Imprimir lote</button>
                  <button class="btn btn-outline-secondary btn-sm" id="discReload"><i class="bi bi-arrow-clockwise"></i> Refrescar</button>
                </div>
              </div>
              <div class="row g-3" id="discList">
                <div class="text-muted small">Cargando...</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    const form = document.getElementById('discountForm');
    const amountInput = document.getElementById('discAmount');
    const descInput = document.getElementById('discDesc');
    const qtyInput = document.getElementById('discQty');
    const info = document.getElementById('discInfo');
    const listEl = document.getElementById('discList');
    const reloadBtn = document.getElementById('discReload');
    const batchFilter = document.getElementById('discBatchFilter');
    const printBatchBtn = document.getElementById('discPrintBatch');
    const state = { batch: 'all', batches: [], rows: [] };

    async function loadBatches() {
      try {
        const res = await fetchJSON(api('discounts/batches'));
        const rows = res.rows || [];
        state.batches = rows;
        batchFilter.innerHTML = `<option value="all">Todos los lotes</option>` + rows.map((b) =>
          `<option value="${escapeHtml(b.batch_id)}">${escapeHtml(b.batch_id)} · ${escapeHtml(b.description || '')}</option>`
        ).join('');
      } catch (_) { /* ignore */ }
    }

    async function loadList() {
      listEl.innerHTML = `<div class="text-muted small">Cargando...</div>`;
      try {
        await ensureBarcodeLib();
        const params = [];
        if (state.batch && state.batch !== 'all') params.push(`batch_id=${encodeURIComponent(state.batch)}`);
        const query = params.length ? `?${params.join('&')}` : '';
        const res = await fetchJSON(api(`discounts${query}`));
        const batchLabel = state.batch && state.batch !== 'all'
          ? (state.batches.find((b) => b.batch_id === state.batch)?.description || state.batch)
          : '';
        state.rows = res.rows || [];
        const html = renderList(res.rows || [], batchLabel);
        listEl.innerHTML = html;
        listEl.querySelectorAll('[data-action="print"]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const code = btn.getAttribute('data-code') || '';
            const amount = Number(btn.getAttribute('data-amount') || 0);
            const desc = btn.getAttribute('data-description') || '';
            openPrintWindow(code, amount, desc);
          });
        });
        listEl.querySelectorAll('[data-action="copy"]').forEach((btn) => {
          btn.addEventListener('click', async () => {
            const code = btn.getAttribute('data-code') || '';
            try { await navigator.clipboard.writeText(code); } catch (_) {}
          });
        });
      } catch (err) {
        listEl.innerHTML = `<div class="text-danger small">Error: ${escapeHtml(err.message || String(err))}</div>`;
      }
    }

    if (reloadBtn) reloadBtn.addEventListener('click', () => { loadBatches(); loadList(); });
    if (batchFilter) batchFilter.addEventListener('change', () => { state.batch = batchFilter.value; loadList(); });
    if (printBatchBtn) {
      printBatchBtn.addEventListener('click', async () => {
        const rows = state.rows || [];
        if (!rows.length) return;
        await ensureBarcodeLib();
        const urls = await Promise.all(rows.map(async (r) => {
          try {
            const tmp = document.createElement('canvas');
            window.JsBarcode(tmp, r.code, { format: 'CODE128', width: 2, height: 80, displayValue: true, margin: 8 });
            const url = tmp.toDataURL('image/png');
            return { ...r, url };
          } catch (err) {
            return { ...r, url: null };
          }
        }));
        const grid = urls.map(r => `
          <div style="width:220px; text-align:center; margin:8px; border:1px solid #ccc; border-radius:8px; padding:8px; display:inline-block;">
            ${r.url ? `<img src="${r.url}" alt="QR ${escapeHtml(r.code)}" style="width:180px;height:180px;" />` : `<div style="width:180px;height:180px;border:1px dashed #999;display:inline-block;">QR</div>`}
            <div style="font-weight:bold;">${escapeHtml(r.code)}</div>
            <div>${fmtQ(r.amount)}</div>
            <div style="font-size:12px;color:#555;">${escapeHtml(r.description || '')}</div>
          </div>
        `).join('');
        const html = `
          <html><head><meta charset="UTF-8"><title>Impresión de lote</title></head>
          <body style="font-family:Arial,sans-serif; text-align:center;">${grid}</body></html>`;
        const w = window.open('', '_blank', 'width=900,height=700');
        if (!w) return;
        w.document.write(html);
        w.document.close();
        setTimeout(() => { try { w.print(); } catch (_) {} }, 200);
      });
    }

    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (info) info.classList.add('d-none');
        const amt = Number(amountInput.value);
        const qty = Number(qtyInput.value || 1);
        const desc = descInput.value.trim();
        if (!Number.isFinite(amt) || amt <= 0) { alert('Ingresa un monto válido.'); return; }
        if (!Number.isFinite(qty) || qty < 1 || qty > 50) { alert('Cantidad entre 1 y 50.'); return; }
        const btn = document.getElementById('discSubmit');
        if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
        try {
          const res = await fetchJSON(api('discounts'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount: amt, description: desc, quantity: qty }),
          });
          if (info) {
            info.textContent = `Generado lote ${res.batch_id || ''} (${res.items?.length || 0} cupones).`;
            info.classList.remove('d-none');
          }
          await loadBatches();
          await loadList();
        } catch (err) {
          alert('No se pudo crear el descuento: ' + (err.message || err));
        } finally {
          if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
        }
      });
    }

    await loadBatches();
    await loadList();
  }

  Core.registerPage('discounts', renderDiscounts);
})();

