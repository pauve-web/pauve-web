<?php
/* Caja PB x Pauvé — servidor. Guarda todo en una base SQLite dentro de /datos. */
declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
/* if PHP itself breaks, still answer with a readable message instead of an empty page */
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Error del servidor: ' . $e['message']], JSON_UNESCAPED_UNICODE);
  }
});

define('DATA_DIR', rtrim((string)(getenv('CAJA_DATA') ?: ($_SERVER['CAJA_DATA'] ?? (__DIR__ . '/datos'))), '/'));
const PHOTO_DIR = DATA_DIR . '/fotos';
const COOKIE = 'caja_pbp';
const CATS = ['Pendientes','Anillos','Collares','Pulseras','Charms','Complementos'];

/* text helpers that work without the mbstring extension (not always installed) */
function txtlen(string $s): int { return (int)preg_match_all('/./us', $s); }
function txtcut(string $s, int $start, int $len): string { preg_match_all('/./us', $s, $m); return implode('', array_slice($m[0], $start, $len)); }
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
CREATE TABLE IF NOT EXISTS tokens (token TEXT PRIMARY KEY, role TEXT, name TEXT, created TEXT, owner_id INTEGER);
CREATE TABLE IF NOT EXISTS owners (id INTEGER PRIMARY KEY, name TEXT, hash TEXT);
CREATE TABLE IF NOT EXISTS products (ref INTEGER PRIMARY KEY, name TEXT, cat TEXT, cost REAL, price REAL, stock INTEGER, photo TEXT, hidden INTEGER DEFAULT 0, added TEXT);
CREATE TABLE IF NOT EXISTS sales (id TEXT PRIMARY KEY, t TEXT, ref INTEGER, name TEXT, cat TEXT, list REAL, dto REAL, price REAL, cost REAL, manual INTEGER, seller TEXT);
CREATE TABLE IF NOT EXISTS entries (id TEXT PRIMARY KEY, t TEXT, day TEXT, supplier TEXT, total REAL, paid_by INTEGER, note TEXT, doc TEXT, lines TEXT, author TEXT);
CREATE TABLE IF NOT EXISTS holds (id TEXT PRIMARY KEY, t TEXT, kind TEXT, ref INTEGER, what TEXT, client TEXT, phone TEXT, deposit REAL, note TEXT, status TEXT, author TEXT, closed TEXT);
CREATE TABLE IF NOT EXISTS vouchers (code TEXT PRIMARY KEY, t TEXT, amount REAL, balance REAL, holder TEXT, pay TEXT, seller TEXT, note TEXT);
CREATE TABLE IF NOT EXISTS settlements (id INTEGER PRIMARY KEY, t TEXT, data TEXT, author TEXT);");

/* columns added after the first version: add them if the database is older */
foreach (['ALTER TABLE tokens ADD COLUMN owner_id INTEGER', 'ALTER TABLE sales ADD COLUMN pay TEXT', 'ALTER TABLE owners ADD COLUMN share REAL', 'ALTER TABLE sales ADD COLUMN own INTEGER DEFAULT 0', 'ALTER TABLE sales ADD COLUMN voucher TEXT', 'ALTER TABLE sales ADD COLUMN vamt REAL'] as $sql) {
  try { $pdo->exec($sql); } catch (Throwable $e) { /* already there */ }
}
function cfg(string $k, $def = null) { global $pdo; $s = $pdo->prepare('SELECT v FROM config WHERE k=?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
function setCfg(string $k, string $v): void { global $pdo; $pdo->prepare('INSERT INTO config(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '[]', true); return is_array($j) ? $j : []; }
function num($v): ?float { if ($v === null || $v === '') return null; return is_numeric($v) ? round((float)$v, 2) : null; }
/* give back to a gift card what a sale had taken from it */
function unVoucher(string $saleId): void {
  global $pdo;
  $s = $pdo->prepare('SELECT voucher,vamt FROM sales WHERE id=?'); $s->execute([$saleId]); $x = $s->fetch();
  if ($x && $x['voucher']) {
    $pdo->prepare('UPDATE vouchers SET balance=balance+? WHERE code=?')->execute([(float)$x['vamt'], $x['voucher']]);
    $pdo->prepare('UPDATE sales SET voucher=NULL, vamt=NULL WHERE id=?')->execute([$saleId]);
  }
}
function newId(): string { return bin2hex(random_bytes(8)); }
function okId($v): string { return preg_match('/^[a-z0-9]{8,32}$/', (string)$v) ? (string)$v : newId(); }
function short($v, int $n = 80): string { return trim(txtcut((string)($v ?? ''), 0, $n)); }
const DOC_RE = '/^[a-f0-9]{20}\.(jpg|png|webp|pdf)$/';

/* weekly copy of the whole database, kept next to it (the last 8 weeks) */
function maybeBackup(): void {
  global $pdo, $cfg;
  $last = (int)cfg('backup_t', '0');
  if (time() - $last < 7 * 86400) return;
  $dir = DATA_DIR . '/copias';
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
  $file = $dir . '/caja-' . date('Y-m-d') . '.sqlite';
  try {
    if (is_file($file)) @unlink($file);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($file));
  } catch (Throwable $e) {
    try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); @copy(DATA_DIR . '/' . $cfg['db'], $file); } catch (Throwable $e2) { return; }
  }
  if (!is_file($file)) return;
  setCfg('backup_t', (string)time());
  $all = glob($dir . '/caja-*.sqlite') ?: []; sort($all);
  foreach (array_slice($all, 0, max(0, count($all) - 8)) as $old) @unlink($old);
}
function backups(): array {
  $all = glob(DATA_DIR . '/copias/caja-*.sqlite') ?: []; rsort($all);
  return array_map(fn($f) => ['day' => substr(basename($f), 5, 10), 'kb' => (int)round(filesize($f) / 1024)], $all);
}
/* money of the sales since the last split, and what each owner should get */
function pending(): array {
  global $pdo;
  $since = (string)($pdo->query('SELECT t FROM settlements ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
  $owners = $pdo->query('SELECT id,name,share FROM owners ORDER BY id')->fetchAll();
  $n = max(1, count($owners));
  $s = $pdo->prepare('SELECT * FROM sales WHERE t > ?'); $s->execute([$since]);
  $count = 0; $revenue = 0.0; $cost = 0.0; $profit = 0.0; $ownCount = 0; $ownPaid = []; 
  foreach ($s as $x) {
    $c = $x['cost'] === null ? 0.0 : (float)$x['cost'];
    $cost += $c;
    if (!empty($x['own'])) { $ownCount++; $ownPaid[$x['seller']] = ($ownPaid[$x['seller']] ?? 0) + (float)$x['price']; continue; }
    $count++; $revenue += (float)$x['price'];
    if ($x['cost'] !== null) $profit += (float)$x['price'] - $c;
  }
  $e = $pdo->prepare('SELECT total,paid_by FROM entries WHERE t > ?'); $e->execute([$since]);
  $bought = 0.0; $paid = [];
  foreach ($e as $x) {
    $bought += (float)$x['total'];
    if ((int)$x['paid_by'] === 0) foreach ($owners as $o) $paid[$o['id']] = ($paid[$o['id']] ?? 0) + (float)$x['total'] / $n;
    else $paid[(int)$x['paid_by']] = ($paid[(int)$x['paid_by']] ?? 0) + (float)$x['total'];
  }
  $rows = [];
  foreach ($owners as $o) {
    $pct = $o['share'] === null ? 100 / $n : (float)$o['share'];
    $back = round($cost / $n, 2); $prof = round($profit * $pct / 100, 2);
    $adj = round(($paid[$o['id']] ?? 0) - $bought / $n, 2);
    $rows[] = ['id' => (int)$o['id'], 'name' => $o['name'], 'pct' => $pct, 'cost' => $back, 'profit' => $prof, 'adjust' => $adj, 'total' => round($back + $prof + $adj, 2)];
  }
  return ['since' => $since, 'count' => $count, 'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($profit, 2),
    'own' => $ownCount, 'ownPaid' => $ownPaid, 'bought' => round($bought, 2), 'owners' => $rows];
}

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
  $owner = trim(txtcut((string)($b['owner'] ?? ''), 0, 40)) ?: 'Paula';
  if (txtlen($team) < 6 || txtlen($admin) < 8) fail('La contraseña del equipo necesita al menos 6 caracteres y la tuya al menos 8.');
  if ($team === $admin) fail('Las dos contraseñas tienen que ser distintas.');
  setCfg('team_hash', password_hash($team, PASSWORD_DEFAULT));
  $pdo->prepare('INSERT INTO owners(name,hash) VALUES(?,?)')->execute([$owner, password_hash($admin, PASSWORD_DEFAULT)]);
  setCfg('dto', '0');
  /* first load of the product list */
  /* the starting product list arrives with the setup form, so it never has to live in the public repository */
  $rows = is_array($b['products'] ?? null) ? $b['products'] : [];
  if ($rows && (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
    foreach ($rows as $r) if (!is_array($r) || count($r) !== 6 || !in_array($r[2], CATS, true)) fail('El archivo de productos no tiene el formato esperado.');
    $ins = $pdo->prepare('INSERT INTO products(ref,name,cat,cost,price,stock,photo,hidden,added) VALUES(?,?,?,?,?,?,NULL,0,?)');
    $pdo->beginTransaction();
    foreach ($rows as [$ref, $name, $cat, $cost, $price, $stock]) $ins->execute([(int)$ref, txtcut((string)$name, 0, 80), $cat, num($cost), num($price), max(0, (int)$stock), gmdate('c')]);
    $pdo->commit();
  }
  out(['ok' => true]);
}

if (!$configured) out(['setup' => true]);

if ($a === 'login') {
  if (!$isPost) fail('Petición no válida', 405);
  $b = body(); $pw = (string)($b['password'] ?? ''); $name = trim(txtcut((string)($b['name'] ?? ''), 0, 40));
  $role = null; $ownerId = null;
  /* each owner (Paula, Patricia…) has her own password; the team shares one */
  foreach ($pdo->query('SELECT id,name,hash FROM owners') as $o) {
    if ($o['hash'] !== null && password_verify($pw, $o['hash'])) { $role = 'admin'; $ownerId = (int)$o['id']; $name = $o['name']; break; }
  }
  if (!$role && password_verify($pw, (string)cfg('team_hash'))) $role = 'team';
  if (!$role) { sleep(1); fail('Contraseña incorrecta.', 401); }
  if ($name === '') fail('Escribe tu nombre para saber quién vende.');
  $tok = bin2hex(random_bytes(24));
  $pdo->prepare('INSERT INTO tokens(token,role,name,created,owner_id) VALUES(?,?,?,?,?)')->execute([hash('sha256', $tok), $role, $name, gmdate('c'), $ownerId]);
  setcookie(COOKIE, $tok, ['expires' => time() + 400 * 86400, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
  out(['ok' => true]);
}

/* everything below needs a logged-in device */
$me = null;
if (!empty($_COOKIE[COOKIE])) {
  $s = $pdo->prepare('SELECT role,name,owner_id FROM tokens WHERE token=?'); $s->execute([hash('sha256', (string)$_COOKIE[COOKIE])]);
  $me = $s->fetch() ?: null;
}
if (!$me) out(['login' => true]);
$admin = $me['role'] === 'admin';
function needAdmin(): void { global $admin; if (!$admin) fail('Esto solo lo pueden hacer las dueñas.', 403); }

switch ($a) {
  case 'logout':
    $pdo->prepare('DELETE FROM tokens WHERE token=?')->execute([hash('sha256', (string)$_COOKIE[COOKIE])]);
    setcookie(COOKIE, '', ['expires' => 1, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/']);
    out(['ok' => true]);

  case 'state':
    /* agreed split (21 nov 2026): Patricia 58 % and Paula 42 % of the profit. Done once; afterwards
       the owners change it themselves. Patricia exists without a password until one is given to her. */
    if (cfg('reparto_v1') === null) {
      $all = $pdo->query('SELECT id,name,share FROM owners ORDER BY id')->fetchAll();
      $patri = null; foreach ($all as $o) if (stripos($o['name'], 'patri') === 0) $patri = $o;
      if (!$patri) { $pdo->prepare('INSERT INTO owners(name,hash,share) VALUES(?,NULL,58)')->execute(['Patricia']); }
      else $pdo->prepare('UPDATE owners SET share=58 WHERE id=?')->execute([$patri['id']]);
      foreach ($all as $o) if (!$patri || $o['id'] !== $patri['id']) {
        $pdo->prepare('UPDATE owners SET share=? WHERE id=?')->execute([stripos($o['name'], 'paula') === 0 ? 42 : 0, $o['id']]);
      }
      setCfg('reparto_v1', '1');
    }
    $products = $pdo->query('SELECT * FROM products')->fetchAll();
    foreach ($products as &$p) { $p['ref'] = (int)$p['ref']; $p['stock'] = (int)$p['stock']; $p['hidden'] = (bool)$p['hidden'];
      $p['cost'] = $p['cost'] === null ? null : (float)$p['cost']; $p['price'] = $p['price'] === null ? null : (float)$p['price']; }
    $since = gmdate('c', time() - 400 * 86400);
    $s = $pdo->prepare('SELECT * FROM sales WHERE t >= ? ORDER BY t'); $s->execute([$since]);
    $sales = $s->fetchAll();
    foreach ($sales as &$x) { $x['ref'] = $x['ref'] === null ? null : (int)$x['ref']; foreach (['list','dto','price'] as $k) $x[$k] = (float)$x[$k];
      $x['cost'] = $x['cost'] === null ? null : (float)$x['cost']; $x['manual'] = (bool)$x['manual']; $x['own'] = (bool)($x['own'] ?? 0); }
    /* a discount with an end date switches itself off once that day is over */
    $until = (string)cfg('dto_until', '');
    if ($until !== '' && date('Y-m-d') > $until) { setCfg('dto', '0'); setCfg('dto_until', ''); $until = ''; }
    maybeBackup();
    foreach ($sales as &$x) { $x['vamt'] = $x['vamt'] === null ? null : (float)$x['vamt']; }
    $holds = $pdo->query("SELECT * FROM holds WHERE status='abierto' OR closed >= '" . gmdate('c', time() - 30 * 86400) . "' ORDER BY t")->fetchAll();
    foreach ($holds as &$h) { $h['ref'] = $h['ref'] === null ? null : (int)$h['ref']; $h['deposit'] = $h['deposit'] === null ? null : (float)$h['deposit']; }
    $vouchers = $pdo->query('SELECT * FROM vouchers ORDER BY t')->fetchAll();
    foreach ($vouchers as &$v) { $v['amount'] = (float)$v['amount']; $v['balance'] = (float)$v['balance']; }
    unset($x, $h, $v);
    $res = ['role' => $me['role'], 'name' => $me['name'], 'dto' => (float)cfg('dto', '0'), 'dtoUntil' => $until, 'products' => $products, 'sales' => $sales,
      'holds' => $holds, 'vouchers' => $vouchers];
    if ($admin) {
      $res['owners'] = $pdo->query('SELECT id,name,share,(hash IS NOT NULL) AS canlogin FROM owners ORDER BY id')->fetchAll();
      foreach ($res['owners'] as &$o) { $o['id'] = (int)$o['id']; $o['share'] = $o['share'] === null ? null : (float)$o['share']; $o['canlogin'] = (bool)$o['canlogin']; }
    }
    out($res);

  case 'sell':
    $b = body();
    $id = preg_match('/^[a-z0-9]{8,32}$/', (string)($b['id'] ?? '')) ? $b['id'] : bin2hex(random_bytes(8));
    $cat = in_array($b['cat'] ?? '', CATS, true) ? $b['cat'] : fail('Tipo no válido');
    $price = num($b['price'] ?? null); if ($price === null || $price < 0) fail('Precio no válido');
    $ref = isset($b['ref']) && $b['ref'] !== null ? (int)$b['ref'] : null;
    $pay = in_array($b['pay'] ?? null, ['efectivo', 'tarjeta'], true) ? $b['pay'] : null;
    $pdo->prepare('INSERT OR IGNORE INTO sales(id,t,ref,name,cat,list,dto,price,cost,manual,seller,pay) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
      $id, gmdate('c'), $ref, $b['name'] !== null ? txtcut((string)$b['name'], 0, 80) : null, $cat,
      num($b['list'] ?? $price) ?? $price, num($b['dto'] ?? 0) ?? 0, $price, num($b['cost'] ?? null), !empty($b['manual']) ? 1 : 0, $me['name'], $pay]);
    out(['ok' => true, 'id' => $id]);

  case 'take':
    /* an owner keeps a piece for herself: she pays its full cost, so it brings no profit */
    needAdmin();
    $b = body();
    $id = preg_match('/^[a-z0-9]{8,32}$/', (string)($b['id'] ?? '')) ? $b['id'] : bin2hex(random_bytes(8));
    $s = $pdo->prepare('SELECT * FROM products WHERE ref=?'); $s->execute([(int)($b['ref'] ?? 0)]); $p = $s->fetch();
    if (!$p) fail('No encuentro esa pieza');
    if ($p['cost'] === null) fail('Esta pieza no tiene coste apuntado. Ponle el coste en Productos y vuelve a probar.');
    $pdo->prepare('INSERT OR IGNORE INTO sales(id,t,ref,name,cat,list,dto,price,cost,manual,seller,pay,own) VALUES(?,?,?,?,?,?,?,?,?,0,?,NULL,1)')->execute([
      $id, gmdate('c'), (int)$p['ref'], $p['name'], $p['cat'], $p['price'] ?? $p['cost'], 0, $p['cost'], $p['cost'], $me['name']]);
    out(['ok' => true, 'id' => $id, 'price' => (float)$p['cost']]);

  case 'delsale':
    $b = body(); $id = (string)($b['id'] ?? '');
    $s = $pdo->prepare('SELECT t FROM sales WHERE id=?'); $s->execute([$id]); $t = $s->fetchColumn();
    if ($t === false) out(['ok' => true]);
    /* the team may only remove sales from the last 24 hours; older ones only Paula */
    if (!$admin && strtotime($t) < time() - 86400) fail('Las ventas de días anteriores solo las pueden borrar las dueñas.', 403);
    $pdo->beginTransaction();
    unVoucher($id);
    $pdo->prepare('DELETE FROM sales WHERE id=?')->execute([$id]);
    $pdo->commit();
    out(['ok' => true]);

  case 'assign':
    $b = body();
    $s = $pdo->prepare('SELECT name,cost FROM products WHERE ref=?'); $s->execute([(int)($b['ref'] ?? 0)]); $p = $s->fetch();
    if (!$p) fail('No encuentro esa pieza');
    $pdo->prepare('UPDATE sales SET ref=?, name=?, cost=? WHERE id=?')->execute([(int)$b['ref'], $p['name'], $p['cost'], (string)($b['id'] ?? '')]);
    out(['ok' => true]);

  case 'pay':
    $b = body(); $id = (string)($b['id'] ?? '');
    $pay = in_array($b['pay'] ?? null, ['efectivo', 'tarjeta', 'vale'], true) ? $b['pay'] : null;
    $pdo->beginTransaction();
    unVoucher($id);
    $used = null;
    if ($pay === 'vale') {
      $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($b['code'] ?? '')));
      $v = $pdo->prepare('SELECT balance FROM vouchers WHERE code=?'); $v->execute([$code]); $bal = $v->fetchColumn();
      if ($bal === false) { $pdo->rollBack(); fail('No existe ningún vale con ese código.'); }
      if ((float)$bal <= 0) { $pdo->rollBack(); fail('Ese vale ya está gastado.'); }
      $s = $pdo->prepare('SELECT price FROM sales WHERE id=?'); $s->execute([$id]); $price = $s->fetchColumn();
      if ($price === false) { $pdo->rollBack(); fail('No encuentro esa venta.'); }
      $used = round(min((float)$bal, (float)$price), 2);
      $pdo->prepare('UPDATE vouchers SET balance=balance-? WHERE code=?')->execute([$used, $code]);
      $pdo->prepare('UPDATE sales SET pay=?, voucher=?, vamt=? WHERE id=?')->execute(['vale', $code, $used, $id]);
    } else {
      $pdo->prepare('UPDATE sales SET pay=? WHERE id=?')->execute([$pay, $id]);
    }
    $pdo->commit();
    out(['ok' => true, 'used' => $used]);

  case 'vsell':
    /* sell a gift card: the money comes in now, the sale is counted when it is spent */
    $b = body();
    $amount = num($b['amount'] ?? null); if ($amount === null || $amount <= 0 || $amount > 1000) fail('Importe no válido');
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do { $code = ''; for ($i = 0; $i < 6; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
      $c = $pdo->prepare('SELECT 1 FROM vouchers WHERE code=?'); $c->execute([$code]); } while ($c->fetchColumn());
    $pay = in_array($b['pay'] ?? null, ['efectivo', 'tarjeta'], true) ? $b['pay'] : null;
    $pdo->prepare('INSERT INTO vouchers(code,t,amount,balance,holder,pay,seller,note) VALUES(?,?,?,?,?,?,?,?)')->execute([
      $code, gmdate('c'), $amount, $amount, short($b['holder'] ?? '', 60), $pay, $me['name'], short($b['note'] ?? '', 200)]);
    out(['ok' => true, 'code' => $code]);

  case 'vdel':
    needAdmin();
    $code = (string)(body()['code'] ?? '');
    $c = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE voucher=?'); $c->execute([$code]);
    if ((int)$c->fetchColumn() > 0) fail('Ese vale ya se ha usado en alguna venta; no se puede borrar.');
    $pdo->prepare('DELETE FROM vouchers WHERE code=?')->execute([$code]);
    out(['ok' => true]);

  case 'hold':
    /* put a piece aside for a client (apartado) or note something a client asked for (encargo) */
    $b = body();
    $kind = in_array($b['kind'] ?? '', ['apartado', 'encargo'], true) ? $b['kind'] : fail('Tipo no válido');
    $client = short($b['client'] ?? '', 60); if ($client === '') fail('Escribe el nombre de la clienta.');
    $ref = isset($b['ref']) && $b['ref'] !== null ? (int)$b['ref'] : null;
    $what = short($b['what'] ?? '', 120);
    if ($kind === 'apartado') {
      $s = $pdo->prepare('SELECT name FROM products WHERE ref=?'); $s->execute([(int)$ref]); $pn = $s->fetchColumn();
      if ($pn === false) fail('Elige la pieza que se aparta.');
      $what = $pn;
    } elseif ($what === '') fail('Escribe qué ha pedido.');
    $id = okId($b['id'] ?? '');
    $pdo->prepare('INSERT OR IGNORE INTO holds(id,t,kind,ref,what,client,phone,deposit,note,status,author,closed) VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL)')->execute([
      $id, gmdate('c'), $kind, $kind === 'apartado' ? $ref : null, $what, $client, short($b['phone'] ?? '', 30), num($b['deposit'] ?? null), short($b['note'] ?? '', 200), 'abierto', $me['name']]);
    out(['ok' => true, 'id' => $id]);

  case 'holdstatus':
    $b = body();
    $st = in_array($b['status'] ?? '', ['abierto', 'hecho', 'anulado'], true) ? $b['status'] : fail('Estado no válido');
    $pdo->prepare('UPDATE holds SET status=?, closed=? WHERE id=?')->execute([$st, $st === 'abierto' ? null : gmdate('c'), (string)($b['id'] ?? '')]);
    out(['ok' => true]);

  case 'entries':
    needAdmin();
    $rows = $pdo->query('SELECT * FROM entries ORDER BY day DESC, t DESC')->fetchAll();
    foreach ($rows as &$r) { $r['total'] = (float)$r['total']; $r['paid_by'] = (int)$r['paid_by']; $r['lines'] = json_decode((string)$r['lines'], true) ?: []; }
    out(['entries' => $rows]);

  case 'entry':
    /* goods bought: what it cost, who paid, the invoice, and the units that go into stock */
    needAdmin();
    $b = body();
    $total = num($b['total'] ?? null); if ($total === null || $total < 0) fail('Escribe el importe de la factura.');
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['day'] ?? '')) ? $b['day'] : date('Y-m-d');
    $paidBy = (int)($b['paid_by'] ?? 0);
    if ($paidBy) { $c = $pdo->prepare('SELECT 1 FROM owners WHERE id=?'); $c->execute([$paidBy]); if (!$c->fetchColumn()) fail('No encuentro a esa dueña.'); }
    $doc = isset($b['doc']) && preg_match(DOC_RE, (string)$b['doc']) ? $b['doc'] : null;
    $lines = [];
    foreach ((array)($b['lines'] ?? []) as $l) {
      $ref = (int)($l['ref'] ?? 0); $q = (int)($l['qty'] ?? 0);
      if ($ref && $q > 0 && $q <= 1000) $lines[] = ['ref' => $ref, 'qty' => $q];
    }
    $pdo->beginTransaction();
    $up = $pdo->prepare('UPDATE products SET stock=stock+?, hidden=0 WHERE ref=?');
    foreach ($lines as $l) $up->execute([$l['qty'], $l['ref']]);
    $id = okId($b['id'] ?? '');
    $pdo->prepare('INSERT INTO entries(id,t,day,supplier,total,paid_by,note,doc,lines,author) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
      $id, gmdate('c'), $day, short($b['supplier'] ?? '', 80), $total, $paidBy, short($b['note'] ?? '', 300), $doc, json_encode($lines), $me['name']]);
    $pdo->commit();
    out(['ok' => true, 'id' => $id]);

  case 'delentry':
    /* undo an entry: its units leave the stock again */
    needAdmin();
    $id = (string)(body()['id'] ?? '');
    $s = $pdo->prepare('SELECT lines FROM entries WHERE id=?'); $s->execute([$id]); $l = $s->fetchColumn();
    if ($l === false) out(['ok' => true]);
    $pdo->beginTransaction();
    $up = $pdo->prepare('UPDATE products SET stock=MAX(0,stock-?) WHERE ref=?');
    foreach (json_decode((string)$l, true) ?: [] as $x) $up->execute([(int)$x['qty'], (int)$x['ref']]);
    $pdo->prepare('DELETE FROM entries WHERE id=?')->execute([$id]);
    $pdo->commit();
    out(['ok' => true]);

  case 'globals':
    /* all-time figures for the owners */
    needAdmin();
    $g = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(price),0) rev, COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN price-cost ELSE 0 END),0) prof,
      COALESCE(SUM(COALESCE(cost,0)),0) cost, MIN(t) first FROM sales WHERE COALESCE(own,0)=0")->fetch();
    $own = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(price),0) paid FROM sales WHERE own=1")->fetch();
    $sold = []; foreach ($pdo->query('SELECT ref, COUNT(*) n FROM sales WHERE ref IS NOT NULL GROUP BY ref') as $r) $sold[(int)$r['ref']] = (int)$r['n'];
    $held = []; foreach ($pdo->query("SELECT ref, COUNT(*) n FROM holds WHERE kind='apartado' AND status='abierto' GROUP BY ref") as $r) $held[(int)$r['ref']] = (int)$r['n'];
    $units = 0; $atCost = 0.0; $atPrice = 0.0; $noCost = 0;
    foreach ($pdo->query('SELECT ref,cost,price,stock,hidden FROM products') as $p) {
      $left = (int)$p['stock'] - ($sold[(int)$p['ref']] ?? 0); if ($left <= 0) continue;
      $units += $left;
      if ($p['cost'] === null) $noCost += $left; else $atCost += $left * (float)$p['cost'];
      if ($p['price'] !== null) $atPrice += $left * (float)$p['price'];
    }
    $inv = $pdo->query('SELECT COALESCE(SUM(total),0) total, COUNT(*) n FROM entries')->fetch();
    $owners = $pdo->query('SELECT id,name FROM owners ORDER BY id')->fetchAll(); $n = max(1, count($owners));
    $byOwner = []; foreach ($owners as $o) $byOwner[$o['name']] = 0.0;
    $names = array_column($owners, 'name', 'id');
    foreach ($pdo->query('SELECT total,paid_by FROM entries') as $e) {
      if ((int)$e['paid_by'] === 0) foreach ($byOwner as $k => $_) $byOwner[$k] += (float)$e['total'] / $n;
      elseif (isset($names[(int)$e['paid_by']])) $byOwner[$names[(int)$e['paid_by']]] += (float)$e['total'];
    }
    $vou = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(amount),0) sold, COALESCE(SUM(balance),0) bal FROM vouchers')->fetch();
    $hold = $pdo->query("SELECT kind, COUNT(*) n FROM holds WHERE status='abierto' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
    $hist = $pdo->query('SELECT id,t,data,author FROM settlements ORDER BY id DESC LIMIT 24')->fetchAll();
    foreach ($hist as &$h) { $h['data'] = json_decode((string)$h['data'], true); } unset($h);
    out(['sales' => ['n' => (int)$g['n'], 'revenue' => round((float)$g['rev'], 2), 'profit' => round((float)$g['prof'], 2), 'cost' => round((float)$g['cost'], 2), 'first' => $g['first']],
      'own' => ['n' => (int)$own['n'], 'paid' => round((float)$own['paid'], 2)],
      'stock' => ['units' => $units, 'atCost' => round($atCost, 2), 'atPrice' => round($atPrice, 2), 'noCost' => $noCost],
      'invested' => ['total' => round((float)$inv['total'], 2), 'n' => (int)$inv['n'], 'byOwner' => array_map(fn($v) => round($v, 2), $byOwner)],
      'vouchers' => ['n' => (int)$vou['n'], 'sold' => round((float)$vou['sold'], 2), 'left' => round((float)$vou['bal'], 2)],
      'holds' => ['apartado' => (int)($hold['apartado'] ?? 0), 'encargo' => (int)($hold['encargo'] ?? 0)],
      'pending' => pending(), 'settlements' => $hist,
      'backups' => backups(), 'lastDownload' => cfg('download_t')]);

  case 'settle':
    /* the owners have shared out the money up to now: remember what each one got */
    needAdmin();
    $p = pending();
    if ($p['count'] === 0 && $p['own'] === 0 && $p['bought'] == 0) fail('No hay nada nuevo que repartir desde el último reparto.');
    $p['note'] = short(body()['note'] ?? '', 200);
    $pdo->prepare('INSERT INTO settlements(t,data,author) VALUES(?,?,?)')->execute([gmdate('c'), json_encode($p, JSON_UNESCAPED_UNICODE), $me['name']]);
    out(['ok' => true]);

  case 'unsettle':
    /* undo the last split if it was marked by mistake */
    needAdmin();
    $pdo->exec('DELETE FROM settlements WHERE id=(SELECT MAX(id) FROM settlements)');
    out(['ok' => true]);

  case 'dto':
    needAdmin();
    $b = body();
    $v = num($b['dto'] ?? 0); if ($v === null || $v < 0 || $v > 90) fail('Descuento no válido');
    $until = (string)($b['until'] ?? '');
    if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) fail('Fecha de fin no válida');
    if ($until !== '' && $until < date('Y-m-d')) fail('La fecha de fin ya ha pasado.');
    setCfg('dto', (string)$v); setCfg('dto_until', $v > 0 ? $until : '');
    out(['ok' => true]);

  case 'share':
    needAdmin();
    $shares = body()['shares'] ?? null;
    if (!is_array($shares)) fail('Datos no válidos');
    $sum = 0; foreach ($shares as $v) { $n = num($v); if ($n === null || $n < 0) fail('Porcentaje no válido'); $sum += $n; }
    if (abs($sum - 100) > 0.01) fail('Los porcentajes tienen que sumar 100.');
    $up = $pdo->prepare('UPDATE owners SET share=? WHERE id=?');
    foreach ($shares as $id => $v) $up->execute([num($v), (int)$id]);
    out(['ok' => true]);

  case 'photo':
    /* team and Paula can both add photos */
    if (empty($_FILES['f']) || $_FILES['f']['error'] !== UPLOAD_ERR_OK) fail('No ha llegado la foto. Prueba otra vez.');
    if ($_FILES['f']['size'] > 8 * 1024 * 1024) fail('El archivo es demasiado grande (máximo 8 MB).');
    $info = @getimagesize($_FILES['f']['tmp_name']);
    $isPdf = !$info && $admin && !empty($_POST['doc']) && file_get_contents($_FILES['f']['tmp_name'], false, null, 0, 5) === '%PDF-';
    if (!$isPdf && (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true))) fail('Ese archivo no es una foto válida.');
    $ext = $isPdf ? 'pdf' : [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $pid = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['f']['tmp_name'], PHOTO_DIR . '/' . $pid)) fail('No he podido guardar la foto en el servidor.', 500);
    $ref = (int)($_POST['ref'] ?? 0);
    if ($ref) $pdo->prepare('UPDATE products SET photo=? WHERE ref=?')->execute([$pid, $ref]);
    out(['ok' => true, 'photo' => $pid]);

  case 'foto':
    $pid = (string)($_GET['id'] ?? '');
    if (!preg_match(DOC_RE, $pid) || !is_file(PHOTO_DIR . '/' . $pid)) { http_response_code(404); exit; }
    header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][pathinfo($pid, PATHINFO_EXTENSION)]);
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile(PHOTO_DIR . '/' . $pid); exit;

  case 'saveproduct':
    $b = body();
    $name = trim(txtcut((string)($b['name'] ?? ''), 0, 80));
    $cat = in_array($b['cat'] ?? '', CATS, true) ? $b['cat'] : fail('Tipo no válido');
    $price = num($b['price'] ?? null); $cost = num($b['cost'] ?? null);
    $stock = max(0, (int)($b['stock'] ?? 0));
    $photo = isset($b['photo']) && preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', (string)$b['photo']) ? $b['photo'] : null;
    if ($admin && ($name === '' || $price === null)) fail('Faltan el nombre o el precio.');
    $ref = (int)($b['ref'] ?? 0);
    if ($ref) {
      $s = $pdo->prepare('SELECT * FROM products WHERE ref=?'); $s->execute([$ref]); $old = $s->fetch();
      if (!$old) fail('Ese producto ya no existe.');
      /* the team can only add units and photos; prices and names are for the owners */
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

  case 'owners':
    needAdmin();
    out(['owners' => $pdo->query('SELECT id,name,share,(hash IS NOT NULL) AS canlogin FROM owners ORDER BY id')->fetchAll(), 'me' => (int)$me['owner_id']]);

  case 'ownerpassword':
    /* give a password to an owner who has none yet (e.g. Patricia, created by the split setup) */
    needAdmin();
    $b = body(); $pw = (string)($b['password'] ?? '');
    if (txtlen($pw) < 8) fail('La contraseña necesita al menos 8 caracteres.');
    $s = $pdo->prepare('SELECT hash FROM owners WHERE id=?'); $s->execute([(int)($b['id'] ?? 0)]); $h = $s->fetchColumn();
    if ($h === false) fail('No encuentro a esa dueña.');
    if ($h !== null) fail('Esa dueña ya tiene contraseña; puede cambiarla ella al entrar.');
    if (password_verify($pw, (string)cfg('team_hash'))) fail('Esa es la contraseña del equipo. Elige otra.');
    $pdo->prepare('UPDATE owners SET hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$b['id']]);
    out(['ok' => true]);

  case 'addowner':
    needAdmin();
    $b = body(); $n = trim(txtcut((string)($b['name'] ?? ''), 0, 40)); $pw = (string)($b['password'] ?? '');
    if ($n === '' || txtlen($pw) < 8) fail('Pon su nombre y una contraseña de al menos 8 caracteres.');
    foreach ($pdo->query('SELECT hash FROM owners WHERE hash IS NOT NULL') as $o) if (password_verify($pw, $o['hash'])) fail('Esa contraseña ya la usa otra dueña. Elige otra.');
    if (password_verify($pw, (string)cfg('team_hash'))) fail('Esa es la contraseña del equipo. Elige otra.');
    $pdo->prepare('INSERT INTO owners(name,hash) VALUES(?,?)')->execute([$n, password_hash($pw, PASSWORD_DEFAULT)]);
    out(['ok' => true]);

  case 'mypassword':
    needAdmin();
    $pw = (string)(body()['password'] ?? '');
    if (txtlen($pw) < 8) fail('La contraseña necesita al menos 8 caracteres.');
    if (password_verify($pw, (string)cfg('team_hash'))) fail('Esa es la contraseña del equipo. Elige otra.');
    $pdo->prepare('UPDATE owners SET hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$me['owner_id']]);
    out(['ok' => true]);

  case 'teampassword':
    needAdmin();
    $pw = (string)(body()['password'] ?? '');
    if (txtlen($pw) < 6) fail('La contraseña del equipo necesita al menos 6 caracteres.');
    setCfg('team_hash', password_hash($pw, PASSWORD_DEFAULT));
    /* close every team session so the old password stops working everywhere */
    $pdo->exec("DELETE FROM tokens WHERE role='team'");
    out(['ok' => true]);

  case 'import':
    /* load the starting product list later, from the app; products that already exist are kept as they are */
    needAdmin();
    $rows = body()['products'] ?? null;
    if (!is_array($rows) || !$rows) fail('El archivo está vacío o no es la lista de productos.');
    foreach ($rows as $r) if (!is_array($r) || count($r) !== 6 || !in_array($r[2], CATS, true)) fail('El archivo de productos no tiene el formato esperado.');
    $ins = $pdo->prepare('INSERT OR IGNORE INTO products(ref,name,cat,cost,price,stock,photo,hidden,added) VALUES(?,?,?,?,?,?,NULL,0,?)');
    $pdo->beginTransaction(); $n = 0;
    foreach ($rows as [$ref, $name, $cat, $cost, $price, $stock]) { $ins->execute([(int)$ref, txtcut((string)$name, 0, 80), $cat, num($cost), num($price), max(0, (int)$stock), gmdate('c')]); $n += $ins->rowCount(); }
    $pdo->commit();
    out(['ok' => true, 'added' => $n]);

  case 'stockcsv':
    needAdmin();
    setCfg('download_t', (string)time());
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-pb-pauve-' . date('Y-m-d') . '.csv"');
    $sold = []; foreach ($pdo->query('SELECT ref, COUNT(*) n FROM sales WHERE ref IS NOT NULL GROUP BY ref') as $r) $sold[(int)$r['ref']] = (int)$r['n'];
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    $nf = fn($v) => $v === null ? '' : str_replace('.', ',', (string)$v);
    fputcsv($f, ['Referencia', 'Producto', 'Tipo', 'Coste', 'Precio', 'Unidades compradas', 'Vendidas', 'Quedan', 'Valor a coste', 'Retirado'], ';');
    foreach ($pdo->query('SELECT * FROM products ORDER BY cat, ref') as $p) {
      $v = $sold[(int)$p['ref']] ?? 0; $l = (int)$p['stock'] - $v;
      fputcsv($f, [$p['ref'], $p['name'], $p['cat'], $nf($p['cost']), $nf($p['price']), $p['stock'], $v, $l, $p['cost'] === null ? '' : $nf(round(max(0, $l) * (float)$p['cost'], 2)), $p['hidden'] ? 'Sí' : ''], ';');
    }
    exit;

  case 'entriescsv':
    needAdmin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compras-pb-pauve-' . date('Y-m-d') . '.csv"');
    $names = array_column($pdo->query('SELECT id,name FROM owners')->fetchAll(), 'name', 'id');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Fecha', 'Proveedor', 'Importe', 'Pagado por', 'Piezas añadidas', 'Nota', 'Factura'], ';');
    foreach ($pdo->query('SELECT * FROM entries ORDER BY day') as $e) {
      $u = array_sum(array_column(json_decode((string)$e['lines'], true) ?: [], 'qty'));
      fputcsv($f, [date('d/m/Y', strtotime($e['day'])), $e['supplier'], str_replace('.', ',', (string)$e['total']), (int)$e['paid_by'] ? ($names[(int)$e['paid_by']] ?? '?') : 'A medias', $u, $e['note'], $e['doc'] ? 'Sí' : 'No'], ';');
    }
    exit;

  case 'csv':
    needAdmin();
    setCfg('download_t', (string)time());
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas-pb-pauve-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Fecha', 'Hora', 'Tipo', 'Producto', 'Referencia', 'Precio etiqueta', 'Descuento %', 'Cobrado', 'Coste', 'Vendido por', 'Pago', 'Para una dueña', 'Vale'], ';');
    foreach ($pdo->query('SELECT * FROM sales ORDER BY t') as $x) {
      $ts = strtotime($x['t']);
      $nf = fn($v) => $v === null ? '' : str_replace('.', ',', (string)$v);
      fputcsv($f, [date('d/m/Y', $ts), date('H:i', $ts), $x['cat'], $x['name'] ?? 'SIN CONCRETAR', $x['ref'] ?? '', $nf($x['list']), $nf($x['dto']), $nf($x['price']), $nf($x['cost']), $x['seller'], $x['pay'] ?? '', !empty($x['own']) ? 'Sí, a precio de coste' : '', $x['voucher'] ? $x['voucher'] . ' (' . str_replace('.', ',', (string)$x['vamt']) . ')' : ''], ';');
    }
    exit;
}
fail('Acción desconocida', 404);
