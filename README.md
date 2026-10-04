# pauve-web

Web de Pauvè · Tooth Gems · Sevilla — https://pauvestudio.com

## Cómo funciona

- `public/` → contenido de la web (lo que se publica).
- `Caddyfile` → configuración del servidor web (HTTPS automático, redirección de www).
- El servidor (Hetzner, `pauve-web`) descarga los cambios de la rama `main` cada 5 minutos
  (`/etc/cron.d/pauve-web`) y recarga Caddy. Publicar = hacer push a `main`.

## Notas

- Repositorio público: nunca subir contraseñas, claves ni datos de clientas.
- DNS en Cloudflare (registros A/AAAA en modo "DNS only").
