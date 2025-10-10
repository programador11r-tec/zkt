(() => {
  const app = document.getElementById('app');

  // Detecta el directorio base (soporta /, /zkt/backend/public/, etc.)
  const base = (() => {
    const url = new URL(window.location.href);
    let p = url.pathname.replace(/index\.html?$/i, '');
    if (!p.endsWith('/')) p += '/';
    return p;
  })();

  function api(path) {
    // Siempre relativo a /public/
    return `${base}api/${path.replace(/^\/?api\//, '')}`;
  }

  function setActive(link) {
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    link.classList.add('active');
  }

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts);
    const text = await res.text();
    try {
      if (!res.ok) throw new Error(`HTTP ${res.status} ${res.statusText}\n${text}`);
      return JSON.parse(text);
    } catch (e) {
      throw new Error(`Respuesta no JSON desde ${url}\n---\n${text}\n---`);
    }
  }

  // ===== Dashboard =====
  async function renderDashboard() {
    try {
      const { data } = await fetchJSON(api('tickets'));
      app.innerHTML = `
        <div class="row g-4">
          <div class="col-md-4">
            <div class="card shadow-sm">
              <div class="card-body">
                <h5 class="card-title">Asistencias de hoy</h5>
                <p class="display-6 mb-0">${data.length}</p>
                <span class="badge badge-soft mt-2">Datos de G4S</span>
              </div>
            </div>
          </div>
          <div class="col-md-8">
            <div class="card shadow-sm">
              <div class="card-body">
                <h5 class="card-title">Listado</h5>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead><tr><th>#</th><th>Nombre</th><th>Entrada</th><th>Salida</th></tr></thead>
                    <tbody>
                      ${data.map((r,i)=>`
                        <tr>
                          <td>${i+1}</td>
                          <td>${r.name}</td>
                          <td>${r.checkIn || '-'}</td>
                          <td>${r.checkOut || '-'}</td>
                        </tr>`).join('')}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>`;
    } catch (e) {
      app.innerHTML = `
        <div class="alert alert-danger">
          No se pudo cargar el dashboard.<br/>
          <pre class="small mb-0">${String(e).replace(/[<>&]/g,s=>({"<":"&lt;",">":"&gt;","&":"&amp;"}[s]))}</pre>
        </div>`;
    }
  }

  // ===== Facturación (tabla + Facturar) =====
  async function renderInvoices() {
    const today = new Date().toISOString().slice(0,10);
    app.innerHTML = `
      <div class='card p-3'>
        <h5 class="mb-3">Facturación (BD → G4S)</h5>
        <p class="text-muted small">Lista tickets <strong>CLOSED</strong> con pagos (o monto) y <strong>sin factura</strong>.</p>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Ticket</th>
                <th>Fecha</th>
                <th class="text-end">Total</th>
                <th>UUID</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody id="invRows">
              <tr><td colspan="5" class="text-muted">Cargando…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    `;

    const renderRows = (rows=[]) => {
      const tbody = document.getElementById('invRows');
      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-muted">No hay pendientes por facturar.</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map(d => {
        const totalFmt = (d.total != null) ? Number(d.total).toLocaleString('es-GT', {style:'currency', currency:'GTQ'}) : '';
        const payload = encodeURIComponent(JSON.stringify({
          ticket_no: d.ticket_no,
          receptor_nit: d.receptor || 'CF', // ajusta si guardas NIT en BD
          serie: d.serie || 'A',
          numero: d.numero || null
        }));
        const disabled = d.uuid ? 'disabled' : '';
        return `
          <tr>
            <td>${d.ticket_no}</td>
            <td>${d.fecha ?? ''}</td>
            <td class="text-end">${totalFmt}</td>
            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;">${d.uuid ?? ''}</td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-success" data-action="invoice" data-payload="${payload}" ${disabled}>
                Facturar
              </button>
            </td>
          </tr>`;
      }).join('');

      document.querySelectorAll('#invRows [data-action="invoice"]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
          btn.disabled = true; btn.textContent = 'Enviando…';
          try {
            const js = await fetchJSON(api('fel/invoice'), {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify(payload)
            });
            alert(`OK: UUID ${js.uuid || '(verifique respuesta)'}`);
            // refrescar la lista
            loadList();
          } catch (e) {
            alert('Error al facturar: ' + e.message);
          } finally {
            btn.disabled = false; btn.textContent = 'Facturar';
          }
        });
      });
    };

    async function loadList() {
      const tbody = document.getElementById('invRows');
      tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Consultando BD…</td></tr>`;
      try {
        const js = await fetchJSON(api('facturacion/list'));
        renderRows(js.rows || []);
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger">Error: ${e.message}</td></tr>`;
      }
    }

    // Carga inicial
    loadList();
  }

  // ===== Reportes =====
  async function renderReports() {
    app.innerHTML = `
      <div class='card p-3 mb-4'>
        <h5>Reportes de Tickets</h5>
        <p class="text-muted small mb-2">Genera el reporte de tickets con tiempos activos y exporta.</p>
        <div class='row g-2 align-items-end mb-3'>
          <div class='col-md-3'>
            <label class='form-label small'>Desde</label>
            <input type='date' id='repFrom' class='form-control form-control-sm'>
          </div>
          <div class='col-md-3'>
            <label class='form-label small'>Hasta</label>
            <input type='date' id='repTo' class='form-control form-control-sm'>
          </div>
          <div class='col-md-6 d-flex gap-2'>
            <button id='btnRepHtml' class='btn btn-outline-primary btn-sm'>Ver HTML</button>
            <button id='btnRepPdf' class='btn btn-primary btn-sm'>Generar PDF</button>
            <button id='btnRepCsv' class='btn btn-outline-secondary btn-sm'>Exportar CSV</button>
          </div>
        </div>
        <div id='reportResult'></div>
      </div>

      <div class='card p-3'>
        <h5>Facturas Emitidas (BD)</h5>
        <p class="text-muted small mb-2">Filtra y exporta las facturas certificadas (status = OK).</p>
        <div class='row g-2 align-items-end mb-3'>
          <div class='col-md-3'>
            <label class='form-label small'>Desde</label>
            <input type='date' id='issFrom' class='form-control form-control-sm'>
          </div>
          <div class='col-md-3'>
            <label class='form-label small'>Hasta</label>
            <input type='date' id='issTo' class='form-control form-control-sm'>
          </div>
          <div class='col-md-3'>
            <label class='form-label small'>NIT Receptor</label>
            <input type='text' id='issNit' class='form-control form-control-sm' placeholder='CF o NIT'>
          </div>
          <div class='col-md-3 d-flex gap-2'>
            <button id='btnIssSearch' class='btn btn-primary btn-sm flex-fill'>Buscar</button>
            <button id='btnIssCsv' class='btn btn-outline-secondary btn-sm'>CSV</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Ticket</th>
                <th>Fecha</th>
                <th class="text-end">Total</th>
                <th>Receptor</th>
                <th>UUID</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody id="issRows">
              <tr><td colspan="5" class="text-muted">Sin datos.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    `;

    // === Parte 1: Reporte de tickets (tu backend /api/reports/tickets) ===
    const toISO = d => d || new Date().toISOString().slice(0,10);
    const go = async (fmt) => {
      const from = toISO(document.getElementById('repFrom').value);
      const to   = toISO(document.getElementById('repTo').value || from);
      const box  = document.getElementById('reportResult');
      box.innerHTML = `<div class='text-muted'>Generando ${fmt.toUpperCase()}…</div>`;
      try {
        const js = await fetchJSON(api(`reports/tickets?from=${from}&to=${to}&format=${fmt}`));
        let links = `<ul class='mt-2'>`;
        for (const [k,v] of Object.entries(js.files)) {
          links += `<li><a href='${v}' target='_blank' rel='noopener'>${k.toUpperCase()}</a></li>`;
        }
        links += `</ul>`;
        box.innerHTML = `<div class='alert alert-success'>Reporte listo (${js.count} tickets) ${links}</div>`;
      } catch (e) {
        box.innerHTML = `<div class='alert alert-danger'>${e.message}</div>`;
      }
    };
    document.getElementById('btnRepHtml').onclick = () => go('html');
    document.getElementById('btnRepPdf').onclick  = () => go('pdf');
    document.getElementById('btnRepCsv').onclick  = () => go('csv');

    // === Parte 2: Emitidas (usa /api/facturacion/emitidas) ===
    let lastRows = [];
    function renderIssued(rows=[]) {
      lastRows = rows;
      const tbody = document.getElementById('issRows');
      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-muted">No hay resultados.</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map(r => `
        <tr>
          <td>${r.ticket_no}</td>
          <td>${r.fecha ?? ''}</td>
          <td class="text-end">${Number(r.total ?? 0).toLocaleString('es-GT',{style:'currency',currency:'GTQ'})}</td>
          <td>${r.receptor ?? 'CF'}</td>
          <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;">${r.uuid ?? ''}</td>
          <td class="text-center">
            ${r.uuid ? `
              <a class="btn btn-sm btn-outline-primary me-1" href="${api('fel/pdf')}?uuid=${encodeURIComponent(r.uuid)}" target="_blank" rel="noopener">PDF</a>
              <a class="btn btn-sm btn-outline-secondary" href="${api('fel/xml')}?uuid=${encodeURIComponent(r.uuid)}" target="_blank" rel="noopener">XML</a>
            ` : `<span class="badge bg-secondary">Sin UUID</span>`}
          </td>
        </tr>
      `).join('');
    }

    async function loadIssued() {
      const from = document.getElementById('issFrom').value;
      const to   = document.getElementById('issTo').value;
      const nit  = document.getElementById('issNit').value.trim();
      const url  = new URL(api('facturacion/emitidas'), window.location.origin);
      if (from) url.searchParams.set('from', from);
      if (to)   url.searchParams.set('to', to);
      if (nit)  url.searchParams.set('nit', nit);
      const tbody = document.getElementById('issRows');
      tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Consultando…</td></tr>`;
      try {
        const js = await fetchJSON(url.toString());
        renderIssued(js.rows || []);
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger">Error: ${e.message}</td></tr>`;
      }
    }

    // Exportar CSV (client-side)
    function exportIssuedCSV() {
      if (!lastRows.length) { alert('No hay datos para exportar'); return; }
      const headers = ['ticket_no','fecha','total','receptor','uuid'];
      const lines = [headers.join(',')].concat(
        lastRows.map(r => headers.map(h => {
          const v = (r[h] ?? '').toString().replace(/"/g,'""');
          return `"${v}"`;
        }).join(','))
      );
      const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8'});
      const url  = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = `facturas_emitidas_${new Date().toISOString().slice(0,10)}.csv`;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    }

    document.getElementById('btnIssSearch').onclick = loadIssued;
    document.getElementById('btnIssCsv').onclick    = exportIssuedCSV;

    // carga inicial
    loadIssued();
  }

  // ===== Ajustes (igual que lo tenías) =====
  async function renderSettings() {
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Ajustes</h5>
          <p class="text-muted">Configura variables de entorno en <code>backend/.env</code> (ver <code>.env.sample</code>).</p>
          <ul>
            <li><strong>ZKTeco:</strong> ZKTECO_BASE_URL, ZKTECO_APP_KEY, ZKTECO_APP_SECRET</li>
            <li><strong>G4S FEL:</strong> (usa RequestTransaction) FEL_G4S_* en .env</li>
            <li><strong>SAT Emisor:</strong> variables SAT_*</li>
          </ul>
        </div>
      </div>
    `;
  }

  const renderers = { dashboard: renderDashboard, invoices: renderInvoices, reports: renderReports, settings: renderSettings };

  function initNav() {
    const links = document.querySelectorAll('[data-page]');
    links.forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const page = link.getAttribute('data-page');
        setActive(link);
        (renderers[page] || renderDashboard)();
      });
    });
    // Activa la que tenga .active o dashboard por defecto
    const initial = document.querySelector('.nav-link.active[data-page]') || document.querySelector('[data-page="dashboard"]');
    if (initial) { setActive(initial); (renderers[initial.getAttribute('data-page')] || renderDashboard)(); }
    else { renderDashboard(); }
  }

  

  initNav();
})();
