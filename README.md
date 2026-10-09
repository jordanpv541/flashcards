# Flashcards

Web de tarjetas de estudio con repaso espaciado (FSRS), pensada para estudiar desde el celular.
Gratis, sin anuncios y de código abierto (AGPL-3.0).

En producción: https://flashcards.jmpvlab.com

## Qué hace

- Importa CSV en varios formatos (`1;pregunta;respuesta`, `S1;…`, `Semana 1;…`, `semana,pregunta,respuesta` con encabezado). Reimportar no duplica ni borra el progreso.
- Repaso con FSRS-6 ([ts-fsrs](https://github.com/open-spaced-repetition/ts-fsrs), MIT) y 4 botones: Otra vez, Difícil, Bien, Fácil.
- Cola "Hoy" que mezcla todos los mazos, con tope de tarjetas nuevas por día.
- Mazos por curso, filtro por semana y sesiones cortas de 10.
- Editar tarjetas incluso en medio del repaso.
- Fórmulas con [KaTeX](https://katex.org) (MIT): `\( x^2 \)` en línea, `$$ … $$` o `\[ … \]` en bloque.
- Modo examen: repasa todo un rango de semanas sin mover el horario.
- Cuaderno de errores, exportar a CSV y borrar la cuenta.
- Login con Google o con correo + código de 6 dígitos (con límites anti-spam).

## Requisitos

- PHP 8.1 o superior con `pdo_mysql`, `curl`, `mbstring` y `openssl`.
- MySQL 5.7+ o MariaDB 10.3+.
- Apache con `mod_rewrite` (como SiteGround).

## Instalación en SiteGround

### 1. Subdominio

Site Tools → Dominio → Subdominios → crea `flashcards.jmpvlab.com`.
Luego, en Seguridad → SSL, instala Let's Encrypt para el subdominio y activa "HTTPS forzado".

### 2. Base de datos

Site Tools → Sitio → MySQL:

1. Crea una base de datos y un usuario, y dale al usuario todos los permisos sobre la base.
2. Abre phpMyAdmin, elige la base, ve a Importar y sube `sql/schema.sql`.

### 3. Archivos

En el Administrador de archivos, entra a la carpeta del subdominio (`www/flashcards.jmpvlab.com/`). Ahí está `public_html/`.

- Sube el zip y descomprímelo **en esa carpeta**, al lado de `public_html/`, para que queden `app/`, `config/`, `sql/` y `storage/` fuera de la web.
- El contenido de la carpeta `public/` del repositorio va **dentro** de `public_html/` (el zip ya viene así).

Debe quedar:

```
www/flashcards.jmpvlab.com/
├── app/
├── config/
├── sql/
├── storage/
└── public_html/
    ├── index.php
    ├── .htaccess
    └── assets/
```

Si por algún motivo todo queda dentro de `public_html/`, igual funciona: `.htaccess` bloquea `app/`, `config/` y `storage/`.

`storage/`, `storage/logs` y `storage/imports` deben poder escribirse (permisos 755 o 775).

### 4. Configuración

Copia `config/config.example.php` como `config/config.php` y completa:

- `app_secret`: déjalo vacío. La web crea su clave secreta la primera vez y la guarda en `storage/app_secret.key`.
- `db`: nombre, usuario y clave del paso 2.
- `mail`: un buzón del dominio (Site Tools → Correo → Cuentas), por ejemplo `no-responder@jmpvlab.com`. Usa los datos SMTP que muestra SiteGround en "Configuración de correo" (normalmente puerto 465 con SSL).
- `google`: ver el paso 5.

`config.php` nunca se sube a GitHub (está en `.gitignore`).

### 5. Login con Google

En https://console.cloud.google.com:

1. Crea un proyecto (por ejemplo "Flashcards").
2. APIs y servicios → Pantalla de consentimiento de OAuth: tipo "Externo", nombre de la app, correo de soporte, dominio `jmpvlab.com`, y los enlaces `https://flashcards.jmpvlab.com/privacidad` y `https://flashcards.jmpvlab.com/terminos`. Ámbitos: `openid`, `email`, `profile`. Publica la app para que cualquiera pueda entrar.
3. Credenciales → Crear credenciales → ID de cliente de OAuth → "Aplicación web".
   - Orígenes autorizados de JavaScript: `https://flashcards.jmpvlab.com`
   - URI de redireccionamiento autorizados: `https://flashcards.jmpvlab.com/auth/google/callback`
4. Copia el ID de cliente y el secreto en `config.php`.

Si dejas `google` vacío, el botón de Google no aparece y solo se entra con correo + código.

### 6. Opcional: Cloudflare Turnstile

Para frenar bots en el formulario del correo, crea un widget gratis en Cloudflare → Turnstile para `flashcards.jmpvlab.com` y pon las dos claves en `config.php`.

### 7. Probar

Abre https://flashcards.jmpvlab.com, entra con tu correo y revisa que llegue el código. Si no llega, mira `storage/logs/app.log`.

## Desarrollo local

```bash
# Base de datos local
mysql -u root -e "CREATE DATABASE flashcards CHARACTER SET utf8mb4"
mysql -u root flashcards < sql/schema.sql

# config/config.php con 'app_url' => 'http://localhost:8080' y 'mail' => ['driver' => 'log', ...]
php -S localhost:8080 -t public public/index.php
```

Con `driver => 'log'`, los códigos de acceso se guardan en `storage/logs/mail.log` en vez de enviarse.

Pruebas (necesitan el servidor local corriendo y los CSV de ejemplo en `tests/fixtures/`):

```bash
php tests/e2e.php            # 86 pruebas: login, importación, FSRS, privacidad, exportar…
python3 tests/ui_test.py     # prueba en Chromium con tamaño de celular (Playwright)
```

## Estructura

```
app/
  bootstrap.php   arranque, errores, sesión
  routes.php      todas las rutas (URLs limpias, sin .php)
  lib/            base de datos, login, Google, correo, CSV, tarjetas y repaso
  views/          plantillas HTML
public/           lo que va en public_html (index.php, .htaccess, assets)
sql/schema.sql    tablas
config/           config.example.php
storage/          logs e importaciones temporales (no se sirve por web)
```

## Licencia

AGPL-3.0-only. Si usas este código en una web, debes ofrecer su código fuente a quienes la usan (el pie de página ya enlaza al repositorio).

Incluye ts-fsrs (MIT) y KaTeX (MIT); sus licencias están en `public/assets/vendor/`.
