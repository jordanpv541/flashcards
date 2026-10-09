<?php
// Copia este archivo como config.php (en esta misma carpeta) y completa los datos.
// config.php NUNCA se sube a GitHub: está en .gitignore.

return [
    // Dirección pública de la web, sin barra al final.
    'app_url' => 'https://flashcards.jmpvlab.com',

    // Déjalo vacío: la web crea una clave secreta la primera vez y la guarda en storage/app_secret.key.
    'app_secret' => '',

    // Muestra errores en pantalla. Déjalo en false en SiteGround.
    'debug' => false,

    // Base de datos (Site Tools > Sitio > MySQL).
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'NOMBRE_DE_LA_BASE',
        'user' => 'USUARIO_DE_LA_BASE',
        'pass' => 'CLAVE_DE_LA_BASE',
    ],

    // Login con Google (Google Cloud Console > APIs y servicios > Credenciales).
    // URI de redireccionamiento autorizado: https://flashcards.jmpvlab.com/auth/google/callback
    // Si lo dejas vacío, el botón de Google no aparece.
    'google' => [
        'client_id' => '',
        'client_secret' => '',
    ],

    // Correo para enviar los códigos de acceso.
    // driver: 'smtp' (recomendado), 'mail' (función mail() de PHP) o 'log' (solo pruebas: guarda el correo en storage/logs/mail.log).
    'mail' => [
        'driver' => 'smtp',
        'from_email' => 'no-responder@jmpvlab.com',
        'from_name' => 'Flashcards',
        'smtp_host' => 'mail.jmpvlab.com',
        'smtp_port' => 465,
        'smtp_secure' => 'ssl', // 'ssl' (puerto 465) o 'tls' (puerto 587)
        'smtp_user' => 'no-responder@jmpvlab.com',
        'smtp_pass' => 'CLAVE_DEL_BUZON',
    ],

    // Cloudflare Turnstile (opcional, frena bots en el formulario de correo).
    // Si lo dejas vacío, no se usa.
    'turnstile' => [
        'site_key' => '',
        'secret_key' => '',
    ],

    // Datos que se muestran en la web.
    'contact_email' => 'soporte@jmpvlab.com',
    'source_url' => 'https://github.com/jordanpv541/flashcards',

    // Límites por usuario (se muestran en la página Cuenta).
    'limits' => [
        'max_decks' => 100,
        'max_cards' => 20000,
        'max_csv_bytes' => 2 * 1024 * 1024,
        'max_import_rows' => 5000,
        'max_imports_per_day' => 30,
    ],

    // Detrás de un proxy que pone la IP real en una cabecera (normalmente no hace falta en SiteGround).
    'trust_proxy_header' => '',
];
