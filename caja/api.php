<?php
/* Caja PB x Pauvé — servidor. Guarda todo en una base SQLite dentro de /datos. */
declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

define('DATA_DIR', rtrim((string)(getenv('CAJA_DATA') ?: ($_SERVER['CAJA_DATA'] ?? (__DIR__ . '/datos'))), '/'));
const PHOTO_DIR = DATA_DIR . '/fotos';
const COOKIE = 'caja_pbp';
const CATS = ['Pendientes','Anillos','Collares','Pulseras','Charms','Complementos'];

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

if (!extension_loaded('pdo_sqlite')) fail('Este hosting no tiene activado SQLite (pdo_sqlite). Pide al hosting que lo active.', 500);
if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true)) fail('No puedo crear la carpeta de datos (' . DATA_DIR . '). Revisa los permisos.', 500);
if (!is_writable(DATA_DIR)) fail('La carpeta de datos no tiene permisos de escritura.', 500);
if (!is_dir(PHOTO_DIR)) @mkdir(PHOTO_DIR, 0755, true);
if (!file_exists(DATA_DIR . '/.htaccess')) @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

/* the database file gets a random name so it can't be guessed on servers that ignore .htaccess */
$cfgFile = DATA_DIR . '/config.php';
if (!file_exists($cfgFile)) {
  $name = 'caja-' . bin2hex(random_bytes(12)) . '.sqlite';
  file_put_contents($cfgFile, "<?php return ['db' => " . var_export($name, true) . "];\n");
}
$cfg = require $cfgFile;
$pdo = new PDO('sqlite:' . DATA_DIR . '/' . $cfg['db']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
$pdo->exec("CREATE TABLE IF NOT EXISTS config (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS tokens (token TEXT PRIMARY KEY, role TEXT, name TEXT, created TEXT);
CREATE TABLE IF NOT EXISTS products (ref INTEGER PRIMARY KEY, name TEXT, cat TEXT, cost REAL, price REAL, stock INTEGER, photo TEXT, hidden INTEGER DEFAULT 0, added TEXT);
CREATE TABLE IF NOT EXISTS sales (id TEXT PRIMARY KEY, t TEXT, ref INTEGER, name TEXT, cat TEXT, list REAL, dto REAL, price REAL, cost REAL, manual INTEGER, seller TEXT);");

function cfg(string $k, $def = null) { global $pdo; $s = $pdo->prepare('SELECT v FROM config WHERE k=?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
function setCfg(string $k, string $v): void { global $pdo; $pdo->prepare('INSERT INTO config(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '[]', true); return is_array($j) ? $j : []; }
function num($v): ?float { if ($v === null || $v === '') return null; return is_numeric($v) ? round((float)$v, 2) : null; }

$a = $_GET['a'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
/* writes need a header that other websites can't add: blocks cross-site forgery */
if ($isPost && ($_SERVER['HTTP_X_CAJA'] ?? '') !== '1') fail('Petición no válida', 403);

$configured = cfg('team_hash') !== null;

if ($a === 'setup') {
  if ($configured) fail('La caja ya está configurada.', 403);
  if (!$isPost) fail('Petición no válida', 405);
  $b = body();
  $team = (string)($b['team'] ?? ''); $admin = (string)($b['admin'] ?? '');
  if (mb_strlen($team) < 6 || mb_strlen($admin) < 8) fail('La contraseña del equipo necesita al menos 6 caracteres y la tuya al menos 8.');
  if ($team === $admin) fail('Las dos contraseñas tienen que ser distintas.');
  setCfg('team_hash', password_hash($team, PASSWORD_DEFAULT));
  setCfg('admin_hash', password_hash($admin, PASSWORD_DEFAULT));
  setCfg('dto', '0');
  /* first load of the product list */
  /* the starting product list arrives with the setup form, so it never has to live in the public repository */
  $rows = is_array($b['products'] ?? null) ? $b['products'] : [];
  if ($rows && (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
    foreach ($rows as $r) if (!is_array($r) || count($r) !== 6 || !in_array($r[2], CATS, true)) fail('El archivo de productos no tiene el formato esperado.');
    $ins = $pdo->prepare('INSERT INTO products(ref,name,cat,cost,price,stock,photo,hidden,added) VALUES(?,?,?,?,?,?,NULL,0,?)');
    $pdo->beginTransaction();
    foreach ($rows as [$ref, $name, $cat, $cost, $price, $stock]) $ins->execute([(int)$ref, mb_substr((string)$name, 0, 80), $cat, num($cost), num($price), max(0, (int)$stock), gmdate('c')]);
    $pdo->commit();
  }
  out(['ok' => true]);
}

if (!$configured) out(['setup' => true]);

if ($a === 'login') {
  if (!$isPost) fail('Petición no válida', 405);
  $b = body(); $pw = (string)($b['password'] ?? ''); $name = trim(mb_substr((string)($b['name'] ?? ''), 0, 40));
  $role = null;
  if (password_verify($pw, cfg('admin_hash'))) $role = 'admin';
  elseif (password_verify($pw, cfg('team_hash'))) $role = 'team';
  if (!$role) { sleep(1); fail('Contraseña incorrecta.', 401); }
  if ($name === '') fail('Escribe tu nombre para saber quién vende.');
  $tok = bin2hex(random_bytes(24));
  $pdo->prepare('INSERT INTO tokens VALUES(?,?,?,?)')->execute([hash('sha256', $tok), $role, $name, gmdate('c')]);
  setcookie(COOKIE, $tok, ['expires' => time() + 400 * 86400, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
  out(['ok' => true]);
}

/* everything below needs a logged-in device */
$me = null;
if (!empty($_COOKIE[COOKIE])) {
  $s = $pdo->prepare('SELECT role,name FROM tokens WHERE token=?'); $s->execute([hash('sha256', (string)$_COOKIE[COOKIE])]);
  $me = $s->fetch() ?: null;
}
if (!$me) out(['login' => true]);
$admin = $me['role'] === 'admin';
function needAdmin(): void { global $admin; if (!$admin) fail('Solo Paula puede hacer esto.', 403); }

switch ($a) {
  case 'logout':
    $pdo->prepare('DELETE FROM tokens WHERE token=?')->execute([hash('sha256', (string)$_COOKIE[COOKIE])]);
    setcookie(COOKIE, '', ['expires' => 1, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/']);
    out(['ok' => true]);

  case 'state':
    $products = $pdo->query('SELECT * FROM products')->fetchAll();
    foreach ($products as &$p) { $p['ref'] = (int)$p['ref']; $p['stock'] = (int)$p['stock']; $p['hidden'] = (bool)$p['hidden'];
      $p['cost'] = $p['cost'] === null ? null : (float)$p['cost']; $p['price'] = $p['price'] === null ? null : (float)$p['price']; }
    $since = gmdate('c', time() - 400 * 86400);
    $s = $pdo->prepare('SELECT * FROM sales WHERE t >= ? ORDER BY t'); $s->execute([$since]);
    $sales = $s->fetchAll();
    foreach ($sales as &$x) { $x['ref'] = $x['ref'] === null ? null : (int)$x['ref']; foreach (['list','dto','price'] as $k) $x[$k] = (float)$x[$k];
      $x['cost'] = $x['cost'] === null ? null : (float)$x['cost']; $x['manual'] = (bool)$x['manual']; }
    out(['role' => $me['role'], 'name' => $me['name'], 'dto' => (float)cfg('dto', '0'), 'products' => $products, 'sales' => $sales]);

  case 'sell':
    $b = body();
    $id = preg_match('/^[a-z0-9]{8,32}$/', (string)($b['id'] ?? '')) ? $b['id'] : bin2hex(random_bytes(8));
    $cat = in_array($b['cat'] ?? '', CATS, true) ? $b['cat'] : fail('Tipo no válido');
    $price = num($b['price'] ?? null); if ($price === null || $price < 0) fail('Precio no válido');
    $ref = isset($b['ref']) && $b['ref'] !== null ? (int)$b['ref'] : null;
    $pdo->prepare('INSERT OR IGNORE INTO sales VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([
      $id, gmdate('c'), $ref, $b['name'] !== null ? mb_substr((string)$b['name'], 0, 80) : null, $cat,
      num($b['list'] ?? $price) ?? $price, num($b['dto'] ?? 0) ?? 0, $price, num($b['cost'] ?? null), !empty($b['manual']) ? 1 : 0, $me['name']]);
    out(['ok' => true, 'id' => $id]);

  case 'delsale':
    $b = body(); $id = (string)($b['id'] ?? '');
    $s = $pdo->prepare('SELECT t FROM sales WHERE id=?'); $s->execute([$id]); $t = $s->fetchColumn();
    if ($t === false) out(['ok' => true]);
    /* the team may only remove sales from the last 24 hours; older ones only Paula */
    if (!$admin && strtotime($t) < time() - 86400) fail('Solo Paula puede borrar ventas de días anteriores.', 403);
    $pdo->prepare('DELETE FROM sales WHERE id=?')->execute([$id]);
    out(['ok' => true]);

  case 'assign':
    $b = body();
    $s = $pdo->prepare('SELECT name,cost FROM products WHERE ref=?'); $s->execute([(int)($b['ref'] ?? 0)]); $p = $s->fetch();
    if (!$p) fail('No encuentro esa pieza');
    $pdo->prepare('UPDATE sales SET ref=?, name=?, cost=? WHERE id=?')->execute([(int)$b['ref'], $p['name'], $p['cost'], (string)($b['id'] ?? '')]);
    out(['ok' => true]);

  case 'dto':
    needAdmin();
    $v = num(body()['dto'] ?? 0); if ($v === null || $v < 0 || $v > 90) fail('Descuento no válido');
    setCfg('dto', (string)$v); out(['ok' => true]);

  case 'photo':
    /* team and Paula can both add photos */
    if (empty($_FILES['f']) || $_FILES['f']['error'] !== UPLOAD_ERR_OK) fail('No ha llegado la foto. Prueba otra vez.');
    if ($_FILES['f']['size'] > 5 * 1024 * 1024) fail('La foto es demasiado grande.');
    $info = @getimagesize($_FILES['f']['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Ese archivo no es una foto válida.');
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $pid = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['f']['tmp_name'], PHOTO_DIR . '/' . $pid)) fail('No he podido guardar la foto en el servidor.', 500);
    $ref = (int)($_POST['ref'] ?? 0);
    if ($ref) $pdo->prepare('UPDATE products SET photo=? WHERE ref=?')->execute([$pid, $ref]);
    out(['ok' => true, 'photo' => $pid]);

  case 'foto':
    $pid = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', $pid) || !is_file(PHOTO_DIR . '/' . $pid)) { http_response_code(404); exit; }
    header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($pid, PATHINFO_EXTENSION)]);
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile(PHOTO_DIR . '/' . $pid); exit;

  case 'saveproduct':
    $b = body();
    $name = trim(mb_substr((string)($b['name'] ?? ''), 0, 80));
    $cat = in_array($b['cat'] ?? '', CATS, true) ? $b['cat'] : fail('Tipo no válido');
    $price = num($b['price'] ?? null); $cost = num($b['cost'] ?? null);
    $stock = max(0, (int)($b['stock'] ?? 0));
    $photo = isset($b['photo']) && preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', (string)$b['photo']) ? $b['photo'] : null;
    if ($admin && ($name === '' || $price === null)) fail('Faltan el nombre o el precio.');
    $ref = (int)($b['ref'] ?? 0);
    if ($ref) {
      $s = $pdo->prepare('SELECT * FROM products WHERE ref=?'); $s->execute([$ref]); $old = $s->fetch();
      if (!$old) fail('Ese producto ya no existe.');
      /* the team can only add units and photos; prices and names are Paula's */
      if (!$admin) { $name = $old['name']; $cat = $old['cat']; $price = $old['price'] === null ? null : (float)$old['price']; $cost = $old['cost'] === null ? null : (float)$old['cost']; }
      $pdo->prepare('UPDATE products SET name=?,cat=?,price=?,cost=?,stock=?,photo=COALESCE(?,photo) WHERE ref=?')->execute([$name, $cat, $price, $cost, $stock, $photo, $ref]);
    } else {
      needAdmin();
      $ref = (int)$pdo->query('SELECT COALESCE(MAX(ref),0)+1 FROM products')->fetchColumn();
      $pdo->prepare('INSERT INTO products VALUES(?,?,?,?,?,?,?,0,?)')->execute([$ref, $name, $cat, $cost, $price, $stock, $photo, gmdate('c')]);
    }
    out(['ok' => true, 'ref' => $ref]);

  case 'hide':
    needAdmin();
    $pdo->prepare('UPDATE products SET hidden=1 WHERE ref=?')->execute([(int)(body()['ref'] ?? 0)]);
    out(['ok' => true]);

  case 'csv':
    needAdmin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas-pb-pauve-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Fecha', 'Hora', 'Tipo', 'Producto', 'Referencia', 'Precio etiqueta', 'Descuento %', 'Cobrado', 'Coste', 'Vendido por'], ';');
    foreach ($pdo->query('SELECT * FROM sales ORDER BY t') as $x) {
      $ts = strtotime($x['t']);
      $nf = fn($v) => $v === null ? '' : str_replace('.', ',', (string)$v);
      fputcsv($f, [date('d/m/Y', $ts), date('H:i', $ts), $x['cat'], $x['name'] ?? 'SIN CONCRETAR', $x['ref'] ?? '', $nf($x['list']), $nf($x['dto']), $nf($x['price']), $nf($x['cost']), $x['seller']], ';');
    }
    exit;
}
fail('Acción desconocida', 404);
