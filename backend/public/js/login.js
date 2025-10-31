(function(){
  const form = document.getElementById('loginForm');
  const alertBox = document.getElementById('alert');
  const btn = document.getElementById('btnLogin');

  function showError(msg){
    alertBox.textContent = msg || 'Error de autenticación';
    alertBox.classList.remove('d-none');
  }
  function hideError(){ alertBox.classList.add('d-none'); }

// ===== Keep-alive por actividad de usuario =====
// Marca actividad local (no contamos “polling” de la app si el usuario no toca nada)
let __lastUserInteraction = Date.now();
['click','keydown','mousemove','scroll','touchstart','touchmove'].forEach(ev => {
  window.addEventListener(ev, () => { __lastUserInteraction = Date.now(); }, { passive: true });
});

// Cada 2 minutos, si hubo interacción en los últimos 5, enviamos ping (POST)
// Esto evita que se caiga la sesión si el usuario realmente está activo.
setInterval(async () => {
  const now = Date.now();
  const ACTIVE_WINDOW_MS = 5 * 60 * 1000; // 5 minutos
  if (now - __lastUserInteraction <= ACTIVE_WINDOW_MS) {
    try {
      await fetch('/api/auth/ping', { method: 'POST', credentials: 'include', headers: { 'Accept':'application/json' } });
      // Si 401 aquí, tu parche global de fetch ya redirigirá a login.
    } catch (_) { /* ignorar fallos de red */ }
  }
}, 120000); // cada 2 min

  // Intenta leer JSON; si no, devuelve texto plano como error
  async function safeJson(res){
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    if (ct.includes('application/json')) {
      return await res.json();
    }
    const txt = await res.text();
    return { ok: false, error: txt || res.statusText };
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideError();
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Ingresando…';

    const payload = {
      username: document.getElementById('username').value.trim(),
      // no trim al password; respeta espacios si el usuario los puso a propósito
      password: document.getElementById('password').value
    };

    try{
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'include',
      });

      const data = await safeJson(res);

      if (!res.ok || !data.ok) {
        // 401: credenciales inválidas o usuario inactivo; 500: error del server
        const msg = data && (data.error || data.message) ? data.error || data.message
                  : (res.status === 401 ? 'Credenciales inválidas' : `HTTP ${res.status}`);
        throw new Error(msg);
      }

      // éxito → al panel
      window.location.href = '/';
    } catch (err) {
      showError(err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-1"></i> Entrar';
    }
  });

  // Si ya hay sesión, ir directo al panel
  (async () => {
    try{
      const res = await fetch('/api/auth/me', { credentials: 'include', headers: { 'Accept': 'application/json' } });
      if (res.ok) {
        const js = await safeJson(res);
        if (js && js.ok) { window.location.href = '/'; }
      }
    }catch{ /* ignore */ }
  })();
})();
