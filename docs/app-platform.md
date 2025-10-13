# DigitalOcean App Platform

Esta guía complementa el `README.md` y describe cómo desplegar el proyecto en DigitalOcean App Platform usando el archivo `.do/app.yaml` incluido en el repositorio.

## 1. Preparar el repositorio
- Haz fork o clona el repositorio en tu propia cuenta de GitHub/GitLab/Bitbucket.
- Asegúrate de que la rama que usarás para producción contenga el archivo `.do/app.yaml` y el script `backend/ops_app_platform_entrypoint.sh`.

## 2. Crear la aplicación en App Platform
1. Inicia sesión en [DigitalOcean](https://cloud.digitalocean.com/apps) y haz clic en **Launch App**.
2. Selecciona el proveedor Git correspondiente y autoriza el acceso al repositorio.
3. El asistente detectará automáticamente la especificación `.do/app.yaml` y mostrará un servicio PHP llamado `backend` con la carpeta `backend/` como `source_dir`.
4. Deja activada la opción **Autodeploy on Push** para que cada `git push` a la rama elegida dispare un despliegue.

## 3. Configurar la base de datos
- Crea (o vincula) una base de datos MySQL gestionada por DigitalOcean.
- En la pestaña **Environment Variables** del servicio agrega:
  - `DB_CONNECTION = mysql`
  - `DB_HOST`, `DB_PORT` (3306 por defecto), `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` con los valores de la base gestionada.
- Si necesitas conectarte al FEL real, también agrega las variables `FEL_G4S_*` basadas en tu `.env`.

## 4. Migraciones automáticas
El comando de inicio definido en `.do/app.yaml` es `./ops_app_platform_entrypoint.sh`, que ejecuta:

```bash
php ops_init_db.php   # aplica migraciones y semillas
heroku-php-apache2 public/
```

El script `ops_init_db.php` detecta si `DB_CONNECTION` es `sqlite` o `mysql` y aplica las migraciones y semillas específicas de cada driver. Para App Platform debe mantenerse en `mysql`.

Si despliegas sobre una base ya existente donde la tabla `invoices` fue creada antes de estas columnas adicionales, ejecuta una vez `php ops_patch_invoices.php` para que se añadan o validen los campos nuevos antes de reanudar la facturación.

## 5. Publicar
- Revisa el resumen final del asistente y haz clic en **Launch App**.
- Espera a que finalice el build y despliegue. Podrás ver los logs en la sección **Deployments**.
- Una vez finalizado, abre la URL pública que te proporciona DigitalOcean para validar que el backend y el frontend responden correctamente.

## 6. Deploys posteriores
- Cada vez que hagas `git push` a la rama seleccionada, App Platform reconstruirá la imagen, ejecutará `php ops_init_db.php` y actualizará la app.
- Si necesitas cambiar variables de entorno o tamaño de instancia puedes hacerlo desde la consola de DigitalOcean sin modificar el repositorio.

## 7. Limpieza
- Para evitar cargos innecesarios, destruye la app y la base de datos desde el panel de App Platform cuando termines de probar.
