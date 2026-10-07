#!/bin/sh
# Lo ejecuta el servidor cada minuto (ver /etc/cron.d/pauve-web).
# Descarga los cambios de GitHub y solo recarga Caddy si cambió su configuración,
# para no cortar las visitas en cada actualización.
cd /var/www/pauve-web || exit 1
antes=$(sha256sum Caddyfile)
git pull -q || exit 1
despues=$(sha256sum Caddyfile)
if [ "$antes" != "$despues" ]; then
  caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 && systemctl reload caddy
fi
