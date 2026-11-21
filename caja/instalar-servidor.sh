#!/bin/sh
# Prepara el servidor para la Caja de PB x Pauvé. Se ejecuta UNA sola vez, como root.
# actualizar.sh lo lanza solo la primera vez (deja la marca /var/lib/caja-pbp/.instalado).
# A mano: sudo sh /var/www/pauve-web/caja/instalar-servidor.sh
# Instala PHP (con SQLite), crea la carpeta privada de datos y deja que Caddy hable con PHP.
set -e
# Desde cron el PATH es mínimo: usermod y otros están en /usr/sbin
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export DEBIAN_FRONTEND=noninteractive

apt-get update -q
apt-get install -y -q php-fpm php-sqlite3

# Carpeta de datos fuera del repositorio público: ventas, fotos y contraseñas (cifradas).
mkdir -p /var/lib/caja-pbp
chown www-data:www-data /var/lib/caja-pbp
chmod 750 /var/lib/caja-pbp

# Caddy necesita poder usar el socket de PHP, que pertenece al grupo www-data.
usermod -aG www-data caddy

# Fotos de hasta 8 MB por si algún móvil no las reduce antes de subirlas.
for ini in /etc/php/*/fpm/php.ini; do
  sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 8M/; s/^post_max_size = .*/post_max_size = 10M/' "$ini"
done

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
systemctl enable "php$PHPV-fpm"
systemctl restart "php$PHPV-fpm"
systemctl restart caddy

echo "Listo. Abre https://pauvestudio.com/caja/ para configurar las contraseñas."
