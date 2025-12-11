(() => {
  const Core = window.AppCore;
  if (!Core) return;
  const {
    api,
    fetchJSON,
    escapeHtml,
    formatDateTime,
    formatRelativeTime,
    formatNumber,
    settingsState,
    statusToClass,
    buildTimeline,
    loadSettings,
    triggerHamachiSync,
  } = Core;
  const fmtNumber = typeof formatNumber === 'function' ? formatNumber : (v) => String(v ?? '');
  const loadSettingsSafe = typeof loadSettings === 'function' ? loadSettings : async () => null;
  const { app } = Core.elements;

  async function renderSettings() {
    app.innerHTML = `
      <div class="card shadow-sm">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
          <span class="text-muted">Cargando configuración activa…</span>
        </div>
      </div>
    `;

    const settings = await loadSettingsSafe(true);

    if (!settings) {
      app.innerHTML = `
        <div class="empty">
          No fue posible obtener la configuración actual.<br />
          <button class="btn btn-primary btn-sm mt-3" id="retrySettings">Reintentar</button>
        </div>
      `;
      document.getElementById('retrySettings')?.addEventListener('click', () => renderSettings());
      return;
    }

    const metrics = settings.database?.metrics ?? {};
    const hourlyRate = settings.billing?.hourly_rate ?? null;
    const hourlyRateValue = hourlyRate !== null && hourlyRate !== undefined && hourlyRate !== ''
      ? Number(hourlyRate).toFixed(2)
      : '';
    const monthlyRate = settings.billing?.monthly_rate ?? null;
    const monthlyRateValue = monthlyRate !== null && monthlyRate !== undefined && monthlyRate !== ''
      ? Number(monthlyRate).toFixed(2)
      : '';
    const env = String(settings.app?.environment ?? '').toLowerCase();
    let envClass = 'neutral';
    if (['production', 'prod'].includes(env)) envClass = 'danger';
    else if (['staging', 'pre', 'testing', 'qa'].includes(env)) envClass = 'warn';
    else if (env) envClass = 'ok';

    const dbStatus = settings.database?.status ?? 'unknown';
    const dbLabelMap = {
      online: 'Conectada',
      success: 'Conectada',
      healthy: 'Conectada',
      offline: 'Desconectada',
      down: 'Desconectada',
    };
    const dbLabel = dbLabelMap[dbStatus] || (dbStatus ? dbStatus.toString().toUpperCase() : 'Desconocido');

    const integrationItems = Object.values(settings.integrations ?? {});
    const integrationList = integrationItems.length
      ? integrationItems.map((integration) => {
          const statusClass = integration?.configured ? 'ok' : 'warn';
          const statusLabel = integration?.configured ? 'Configurada' : 'Incompleta';
          const detailParts = [];
          if (integration?.base_url) detailParts.push(`URL ${integration.base_url}`);
          if (integration?.mode) detailParts.push(`Modo ${integration.mode}`);
          if (integration?.requestor) detailParts.push(`ID ${integration.requestor}`);
          if (integration?.app_key) detailParts.push(`Clave ${integration.app_key}`);
          const details = detailParts.join(' · ');
          return `
            <li class="integration-item">
              <div>
                <strong>${escapeHtml(integration?.label ?? 'Integración')}</strong>
                <div class="integration-meta">${escapeHtml(details || 'Variables pendientes por completar')}</div>
              </div>
              <span class="status-pill ${statusClass}">${escapeHtml(statusLabel)}</span>
            </li>
          `;
        }).join('')
      : '<li class="integration-item"><div><strong>Sin integraciones definidas</strong><div class="integration-meta">Agrega las credenciales correspondientes en el archivo .env.</div></div></li>';

    app.innerHTML = `
      <div class="row g-4">
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <h5 class="card-title mb-1">Entorno de ejecución</h5>
                  <p class="text-muted small mb-0">${escapeHtml(settings.app?.name ?? 'Integración FEL')}</p>
                </div>
                <span class="status-pill ${envClass}">${escapeHtml(settings.app?.environment_label ?? 'Desconocido')}</span>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Zona horaria</span><span>${escapeHtml(settings.app?.timezone ?? '—')}</span></div>
                <div class="settings-list-item"><span>Servidor</span><span>${escapeHtml(settings.app?.server ?? '—')}</span></div>
                <div class="settings-list-item"><span>PHP</span><span>${escapeHtml(settings.app?.php_version ?? '—')}</span></div>
              </div>
              <small class="text-muted">Actualizado ${escapeHtml(formatRelativeTime(settings.generated_at) || 'recientemente')}.</small>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <h5 class="card-title mb-1">Base de datos</h5>
                  <p class="text-muted small mb-0">${escapeHtml((settings.database?.host && settings.database?.name) ? `${settings.database.host} · ${settings.database.name}` : 'Configura las variables DB_* en el archivo .env')}</p>
                </div>
                <span class="status-pill ${statusToClass(dbStatus)}">${escapeHtml(dbLabel)}</span>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Motor</span><span>${escapeHtml(settings.database?.driver ?? '—')}</span></div>
                <div class="settings-list-item"><span>Usuario</span><span>${escapeHtml(settings.database?.user ?? '—')}</span></div>
                <div class="settings-list-item"><span>Tickets</span><span>${escapeHtml(fmtNumber(metrics.tickets_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Pagos</span><span>${escapeHtml(fmtNumber(metrics.payments_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Facturas</span><span>${escapeHtml(fmtNumber(metrics.invoices_total ?? 0))}</span></div>
                <div class="settings-list-item"><span>Último ticket</span><span>${escapeHtml(formatDateTime(metrics.tickets_last_sync))}</span></div>
                <div class="settings-list-item"><span>Último pago</span><span>${escapeHtml(formatDateTime(metrics.payments_last_sync))}</span></div>
                <div class="settings-list-item"><span>Última factura</span><span>${escapeHtml(formatDateTime(metrics.invoices_last_sync))}</span></div>
                <div class="settings-list-item"><span>Pendientes FEL</span><span>${escapeHtml(fmtNumber(metrics.pending_invoices ?? 0))}</span></div>
              </div>
              ${settings.database?.error ? `<div class="alert alert-warning mb-0 small">${escapeHtml(settings.database.error)}</div>` : ''}
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Seguridad &amp; ingestas</h5>
                <p class="text-muted small mb-0">Claves utilizadas para la comunicación con ZKTeco y servicios externos.</p>
              </div>
              <div class="settings-list">
                <div class="settings-list-item"><span>Token de ingesta</span><span>${escapeHtml(settings.security?.ingest_key ?? 'No configurado')}</span></div>
              </div>
              <small class="text-muted">Las credenciales se leen desde <code>backend/.env</code>.</small>
            </div>
          </div>
        </div>
        <div class="col-xl-4 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Facturación automática</h5>
                <p class="text-muted small mb-0">Define la tarifa por hora para aplicar cobros automáticos en las facturas.</p>
              </div>
              <form id="hourlyRateForm" class="d-flex flex-column gap-3" autocomplete="off" novalidate>
                <div>
                  <label for="hourlyRateInput" class="form-label small mb-1">Tarifa por hora (GTQ)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Q</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="hourlyRateInput" value="${escapeHtml(hourlyRateValue)}" placeholder="0.00" />
                  </div>
                  <small class="text-muted d-block mt-1">Se aplicará automáticamente a los tickets facturados por hora.</small>
                </div>
                <div>
                  <label for="monthlyRateInput" class="form-label small mb-1">Tarifa mensual (GTQ)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Q</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="monthlyRateInput" value="${escapeHtml(monthlyRateValue)}" placeholder="0.00" />
                  </div>
                  <small class="text-muted d-block mt-1">Se aplicará al seleccionar cobro mensual en las facturas.</small>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-sm" id="hourlyRateSave">Guardar</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="hourlyRateClear">Limpiar</button>
                </div>
                <div class="alert alert-success py-2 px-3 small mb-0 d-none" id="hourlyRateFeedback">Tarifas actualizadas correctamente.</div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <h5 class="card-title mb-1">Integraciones activas</h5>
                <p class="text-muted small mb-0">Estado actual de cada conector configurado.</p>
              </div>
              <ul class="integration-list">${integrationList}</ul>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column gap-3">
              <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                  <h5 class="card-title mb-1">Actividad de sincronización</h5>
                  <p class="text-muted small mb-0">Resumen generado ${escapeHtml(formatRelativeTime(settings.generated_at) || 'hace instantes')}.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                  <button class="btn btn-primary btn-sm" id="settingsManualSync">actualizacion de data manual</button>
                  <button class="btn btn-outline-primary btn-sm" id="settingsReload">Actualizar</button>
                </div>
              </div>
              <div class="timeline" id="settingsTimeline">${buildTimeline(settings.activity)}</div>
            </div>
          </div>
        </div>
      </div>
    `;

    const reload = document.getElementById('settingsReload');
    if (reload) {
      reload.addEventListener('click', () => {
        reload.classList.add('is-loading');
        reload.disabled = true;
        renderSettings();
      });
    }

    const manualSyncBtn = document.getElementById('settingsManualSync');
    if (manualSyncBtn) {
      manualSyncBtn.addEventListener('click', async () => {
        manualSyncBtn.classList.add('is-loading');
        manualSyncBtn.disabled = true;
        let shouldRefresh = false;
        const m = Dialog.loading({ title:'Sincronizando', message:'Consultando registros remotos…' });
        try {
          await triggerHamachiSync({ silent:false, force:true });
          await loadSettings(true);
          shouldRefresh = true;
        } catch (error) {
          Dialog.err(error, 'No se pudo sincronizar');
        } finally {
          m.close();
          manualSyncBtn.classList.remove('is-loading');
          manualSyncBtn.disabled = false;
          if (shouldRefresh) void renderSettings();
        }
      });
    }

    const hourlyForm = document.getElementById('hourlyRateForm');
    const hourlyInput = document.getElementById('hourlyRateInput');
    const monthlyInput = document.getElementById('monthlyRateInput');
    const hourlyFeedback = document.getElementById('hourlyRateFeedback');
    const hourlyClear = document.getElementById('hourlyRateClear');
    const hourlySave = document.getElementById('hourlyRateSave');
    if (hourlyForm && hourlyInput) {
      hourlyInput.addEventListener('input', () => {
        if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
      });
      if (monthlyInput) {
        monthlyInput.addEventListener('input', () => {
          if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
        });
      }

      if (hourlyClear) {
        hourlyClear.addEventListener('click', (event) => {
          event.preventDefault();
          hourlyInput.value = '';
          if (monthlyInput) monthlyInput.value = '';
          if (hourlyFeedback) hourlyFeedback.classList.add('d-none');
        });
      }

      hourlyForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const rawHourly = hourlyInput.value.trim();
        const rawMonthly = monthlyInput ? monthlyInput.value.trim() : '';

        // Normaliza a número o null
        const body = {
          hourly_rate: rawHourly === '' ? null : Number(rawHourly),
          monthly_rate: monthlyInput ? (rawMonthly === '' ? null : Number(rawMonthly)) : undefined,
        };

        if (hourlySave) {
          hourlySave.disabled = true;
          hourlySave.classList.add('is-loading');
        }
        if (hourlyFeedback) hourlyFeedback.classList.add('d-none');

        try {
          // ✅ Solo guardar tarifas (NADA de fel/invoice aquí)
          await fetchJSON(api('settings/hourly-rate'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });

          // refresca y muestra valores formateados
          const refreshed = await loadSettings(true);

          const refreshedRate = refreshed?.billing?.hourly_rate ?? null;
          hourlyInput.value =
            refreshedRate !== null && refreshedRate !== undefined && refreshedRate !== ''
              ? Number(refreshedRate).toFixed(2)
              : '';

          if (monthlyInput) {
            const refreshedMonthly = refreshed?.billing?.monthly_rate ?? null;
            monthlyInput.value =
              refreshedMonthly !== null && refreshedMonthly !== undefined && refreshedMonthly !== ''
                ? Number(refreshedMonthly).toFixed(2)
                : '';
          }

          if (hourlyFeedback) {
            const hasHourly = hourlyInput.value !== '';
            const hasMonthly = monthlyInput ? monthlyInput.value !== '' : false;
            hourlyFeedback.textContent = (hasHourly || hasMonthly)
              ? 'Tarifas actualizadas correctamente.'
              : 'Tarifas eliminadas. Configura valores para habilitar los cálculos automáticos.';
            hourlyFeedback.classList.remove('d-none');
          }
        } catch (error) {
          Dialog.err(error, 'No se pudo guardar la tarifa');
        } finally {
          if (hourlySave) {
            hourlySave.classList.remove('is-loading');
            hourlySave.disabled = false;
          }
        }
      });

    }
  }
  Core.registerPage('settings', renderSettings);
})();
