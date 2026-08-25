# CRM DIMSUM — Levantar proyecto en modo local (sin Docker)

Levanta el panel Filament de `D:\DS-TI\CRM-DIMSUM\opm-digemid` corriendo directo
sobre el host (PHP nativo + PostgreSQL nativo), sin pasar por Docker. Es un
modo alterno al de `docker compose up` -- usa su **propia base de datos**
(`opm_digemid_dev`, distinta de la que usa Docker), así que los datos no se
comparten entre ambos modos.

## Prerrequisitos (una sola vez)

- Servicio Windows `postgresql-x64-17` corriendo (puerto 5432).
- Base `opm_digemid_dev` creada, con `php artisan migrate` al día.
- `vendor/` (composer install) y `node_modules/` (npm install) instalados.

## Pasos para levantarlo

1. **Gateway Node** (sesión con Restaurant.pe Logística -- lo necesitan Stock, Kardex y Ventas):
   ```bash
   cd "D:\DS-TI\API-TI" && node server.js
   ```
   Verificar: `curl http://localhost:3000/kardex/api/locals` debe responder 200.

2. **Migraciones pendientes** (si acabas de traer cambios nuevos del repo):
   ```bash
   cd "D:\DS-TI\CRM-DIMSUM\opm-digemid" && php artisan migrate --force
   ```

3. **Servidor Laravel**:
   ```bash
   cd "D:\DS-TI\CRM-DIMSUM\opm-digemid" && php artisan serve --port=8001
   ```

4. Abre el navegador en: **http://localhost:8001/admin**

## Si el frontend se ve roto o desactualizado

Casi siempre es porque `public/build` quedó desactualizado (Tailwind solo
incluye las clases que existían en los blade *al momento de compilar* --
Docker siempre recompila desde cero, este modo local no). Reconstruir:

```bash
cd "D:\DS-TI\CRM-DIMSUM\opm-digemid" && npm run build
```

Si además faltan imágenes subidas (branding, logos), falta el symlink:

```bash
php artisan storage:link
```

Y si algo se ve raro después de tocar código, limpiar caché:

```bash
php artisan optimize:clear
```

## Credenciales

Único usuario en `opm_digemid_dev`: `bcelestepoke@gmail.com` (rol
`superadministrador`, además tiene bypass total vía `FILAMENT_ADMIN_EMAILS`).
La contraseña se reseteó manualmente en esta sesión -- si no la tienes,
resetéala por tinker:

```bash
php artisan tinker --execute "
\$u = App\Models\User::where('email','bcelestepoke@gmail.com')->first();
\$u->password = 'TuNuevaClaveAqui';
\$u->save();
"
```

## Stack

- Laravel 11 + Filament 5.7 + PostgreSQL 17 (servicio nativo, no contenedor)
- PHP 8.2 (XAMPP) · Puerto 8001 · Gateway Node en puerto 3000
