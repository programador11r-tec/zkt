# ZKTeco ↔ G4S FEL PHP (Frontend + Backend)

Proyecto base listo para DigitalOcean (LAMP/LEMP). Contiene:
- Backend PHP sin dependencias externas (router simple) con endpoints JSON.
- Frontend Bootstrap 5 (estático) servido desde `backend/public`.
- Clientes *placeholders* para ZKTeco y G4S FEL listos para cablear con APIs reales.
- SQLite + scripts para inicializar datos dummy.
- Configs de ejemplo para Nginx y Apache.
- Script de despliegue (`ops/scripts/deploy.sh`).

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
1. Conecta por SSH y sube el contenido del proyecto (`scp` o `git clone`).
2. Ejecuta:
```bash
cd zk-g4s-fel-php
bash ops/scripts/deploy.sh
```
3. Apunta tu dominio/IP y verifica `http://<ip>/`.

## Variables de entorno
Editar `backend/.env` (usar `.env.sample` como plantilla).

## Conexión a APIs reales
- Implementa métodos reales en `src/Services/ZKTecoClient.php::request` y `src/Services/G4SClient.php::request`.
- En `ApiController::getDummyAttendance` y `simulateFelInvoice`, sustituye llamadas dummy por las reales.

## Notas
- Este proyecto evita Composer para máxima compatibilidad inmediata. Si prefieres, puedes migrar a Slim/Lumen/Laravel después.
- Generado: 2025-09-25T15:13:36.798150
