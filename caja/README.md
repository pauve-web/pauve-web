# Caja de PB x Pauvé

App interna para apuntar en dos toques las ventas de bisutería en el centro de estética de Patricia.
Se abre en https://pauvestudio.com/caja/ desde cualquier móvil.

- `index.html`: la app (pantalla de venta, ventas del día, productos).
- `api.php`: guarda los datos en una base SQLite.
- `instalar-servidor.sh`: preparación del servidor, una sola vez.

## Datos

Ventas, productos, fotos y contraseñas se guardan en `/var/lib/caja-pbp` en el servidor,
**fuera de este repositorio público**. No se suben nunca a GitHub.

## Primera vez

1. Ejecutar `sudo sh /var/www/pauve-web/caja/instalar-servidor.sh` en el servidor.
2. Abrir https://pauvestudio.com/caja/, elegir la contraseña del equipo y la de Paula,
   y subir el archivo `productos-iniciales.json` con la lista de productos.

## Permisos

- **Equipo**: apuntar ventas, concretar «No sé cuál», poner fotos, sumar unidades y borrar ventas de las últimas 24 h.
- **Paula**: además, crear y retirar productos, cambiar nombres, precios y descuentos, borrar cualquier venta y descargar todas las ventas en Excel (CSV).

## Copia de seguridad

Basta con copiar la carpeta `/var/lib/caja-pbp`.
