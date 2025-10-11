diff --git a/README.md b/README.md
index d2e3f9df05f9b3d2c720ce6e146fe9e4a3cb563c..b8a6aa0355ad7a35b7889bf6525da06d5b134588 100644
--- a/README.md
+++ b/README.md
@@ -1,42 +1,56 @@
 # ZKTeco ↔ G4S FEL PHP (Frontend + Backend)
 
 Proyecto base listo para DigitalOcean (LAMP/LEMP). Contiene:
 - Backend PHP sin dependencias externas (router simple) con endpoints JSON.
 - Frontend Bootstrap 5 (estático) servido desde `backend/public`.
 - Clientes *placeholders* para ZKTeco y G4S FEL listos para cablear con APIs reales.
 - SQLite + scripts para inicializar datos dummy.
 - Configs de ejemplo para Nginx y Apache.
-- Script de despliegue (`ops/scripts/deploy.sh`).
+- Scripts de despliegue (`ops/scripts/setup_droplet.sh` y `ops/scripts/deploy.sh`).
 
 ## Endpoints
 - `GET /api/health`
 - `GET /api/dummy/attendance`
 - `POST /api/fel/invoice`  (simula certificación y devuelve UUID de prueba)
 
 ## Inicio local rápido (PHP embebido)
 ```bash
 cd backend
 php ops_init_db.php
 php -S 0.0.0.0:8080 -t public
 # abrir http://localhost:8080
 ```
 
 ## DigitalOcean (Droplet Ubuntu)
-1. Conecta por SSH y sube el contenido del proyecto (`scp` o `git clone`).
-2. Ejecuta:
-```bash
-cd zk-g4s-fel-php
-bash ops/scripts/deploy.sh
-```
-3. Apunta tu dominio/IP y verifica `http://<ip>/`.
+
+### Guía paso a paso
+1. **Crear el droplet** (Ubuntu 22.04 recomendado) y abrir el puerto 80.
+2. **Subir el código** desde tu máquina local (elige un método):
+   - Con `git`: `ssh root@<ip> "git clone https://<tu-repo>.git zk-g4s-fel-php"`
+   - Con `scp`: `scp -r ./ root@<ip>:/root/zk-g4s-fel-php`
+3. **Preparar dependencias** dentro del droplet:
+   ```bash
+   cd zk-g4s-fel-php
+   sudo bash ops/scripts/setup_droplet.sh
+   ```
+   Este script instala PHP, Nginx/Apache, SQLite y deja los servicios activos.
+4. **Desplegar la aplicación** (desde la raíz del repo en el droplet):
+   ```bash
+   bash ops/scripts/deploy.sh
+   ```
+   - Puedes cambiar el directorio destino exportando `TARGET_DIR=/var/www/mi-sitio` antes de ejecutarlo.
+   - El script sincroniza los archivos (excluyendo `.git`), crea `backend/.env` si falta y prepara la base SQLite.
+   - Autoconfigura Nginx (si existe) con el socket de PHP detectado y habilita Apache con `mod_rewrite` si está disponible.
+5. **Configurar variables** editando `backend/.env` dentro del servidor (por ejemplo, `sudo nano /var/www/zk-g4s-fel-php/backend/.env`).
+6. **Probar en el navegador** visitando `http://<ip>` o tu dominio apuntado al droplet.
 
 ## Variables de entorno
 Editar `backend/.env` (usar `.env.sample` como plantilla).
 
 ## Conexión a APIs reales
 - Implementa métodos reales en `src/Services/ZKTecoClient.php::request` y `src/Services/G4SClient.php::request`.
 - En `ApiController::getDummyAttendance` y `simulateFelInvoice`, sustituye llamadas dummy por las reales.
 
 ## Notas
 - Este proyecto evita Composer para máxima compatibilidad inmediata. Si prefieres, puedes migrar a Slim/Lumen/Laravel después.
 - Generado: 2025-09-25T15:13:36.798150
