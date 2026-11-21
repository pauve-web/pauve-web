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
- **Dueñas**: además, quedarse una pieza a precio de coste (sin beneficio), crear y retirar productos, cambiar nombres, precios y descuentos, borrar cualquier venta y descargar todas las ventas en Excel (CSV).

## Copia de seguridad

Basta con copiar la carpeta `/var/lib/caja-pbp`.

## Más funciones (nov 2026)

- **Clientas** (todas): apartados (resta la pieza del stock disponible hasta que se la lleva), encargos y vales regalo con código. Un vale se cobra al venderlo, pero cuenta como venta cuando se gasta.
- **Cuentas** (dueñas): cifras globales, «Repartir beneficio» (lo que toca a cada una desde el último reparto, incluido el ajuste de quién pagó la mercancía), compras de mercancía con foto o PDF de la factura (suman piezas al stock) y descargas en Excel.
- **Copias**: cada semana se guarda sola una copia de la base en `/var/lib/caja-pbp/copias` (las 8 últimas).
