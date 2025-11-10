<?php
// index.php — Excrow mini API (single-file)
// Drop in public/ or your webroot. Requires PHP 8.1+, MySQL 8+ (JSON supported).

/* -------------------- Basic setup -------------------- */

declare(strict_types=1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function env(string $k, ?string $d=null): string {
  $v = getenv($k);
  return ($v === false || $v === '') ? ($d ?? '') : $v;
}

function respond(int $code, array $data=[]): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  if ($raw === '') return [];
  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE) respond(400, ['error'=>'invalid_json']);
  return $data;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $host = env('DB_HOST', '127.0.0.1');
  $name = env('DB_NAME', 'excrow');
  $user = env('DB_USER', 'excrow');
  $pass = env('DB_PASS', 'password');
  $port = env('DB_PORT', '3306');

  $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Auto-create minimal tables (safe if already exist)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS escrows (
      id VARCHAR(32) PRIMARY KEY,
      title VARCHAR(160) NOT NULL,
      amount BIGINT NOT NULL,
      currency VARCHAR(8) NOT NULL,
      buyer_id VARCHAR(64) NOT NULL,
      seller_id VARCHAR(64) NOT NULL,
      status ENUM('created','funding_pending','funded','released','refunded','canceled','disputed') NOT NULL DEFAULT 'created',
      metadata JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS transactions (
      id VARCHAR(40) PRIMARY KEY,
      escrow_id VARCHAR(32) NOT NULL,
      kind ENUM('fund','release','refund') NOT NULL,
      provider VARCHAR(32) NULL,
      provider_ref VARCHAR(80) NULL,
      amount BIGINT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(escrow_id),
      CONSTRAINT fk_tx_esc FOREIGN KEY (escrow_id) REFERENCES escrows(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  return $pdo;
}

function uid(string $prefix): string { return $prefix . bin2hex(random_bytes(6)); }

function route(): array {
  $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
  $parts = array_values(array_filter(explode('/', $uri)));
  return $parts; // e.g. ['api','escrows','esc_123','release']
}

/* -------------------- Simple auth (optional stub) -------------------- */
// For real auth, validate a Bearer token here and load user.
// Leaving it open for now (demo-API).

/* -------------------- Handlers -------------------- */

function create_escrow(): void {
  $in = json_input();
  foreach (['title','amount','currency','buyer_id','seller_id'] as $f) {
    if (!isset($in[$f]) || $in[$f]==='') respond(422, ['error'=>"missing_field:$f"]);
  }
  $id = uid('esc_');
  $pdo = db();
  $stmt = $pdo->prepare("
    INSERT INTO escrows (id, title, amount, currency, buyer_id, seller_id, status, metadata)
    VALUES (:id,:title,:amount,:currency,:buyer,:seller,'created',CAST(:metadata AS JSON))
  ");
  $stmt->execute([
    ':id'=>$id,
    ':title'=>(string)$in['title'],
    ':amount'=>(int)$in['amount'],
    ':currency'=>strtoupper((string)$in['currency']),
    ':buyer'=>(string)$in['buyer_id'],
    ':seller'=>(string)$in['seller_id'],
    ':metadata'=> json_encode($in['metadata'] ?? new stdClass()),
  ]);

  respond(201, [
    'id'=>$id,
    'status'=>'created',
    'amount'=>(int)$in['amount'],
    'currency'=>strtoupper((string)$in['currency']),
    'funding'=>['payment_intent_id'=>uid('pi_')],
  ]);
}

function get_escrow(string $id): void {
  $pdo = db();
  $row = $pdo->prepare("SELECT * FROM escrows WHERE id=:id");
  $row->execute([':id'=>$id]);
  $e = $row->fetch();
  if (!$e) respond(404, ['error'=>'not_found']);
  // Cast JSON
  if (isset($e['metadata']) && is_string($e['metadata'])) $e['metadata'] = json_decode($e['metadata'], true);
  respond(200, $e);
}

function fund_escrow(string $id): void {
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $r = $pdo->prepare("SELECT status, amount FROM escrows WHERE id=:id FOR UPDATE");
    $r->execute([':id'=>$id]);
    $e = $r->fetch();
    if (!$e) { $pdo->rollBack(); respond(404, ['error'=>'not_found']); }
    if (!in_array($e['status'], ['created','funding_pending'], true)) { $pdo->rollBack(); respond(409, ['error'=>'invalid_state']); }

    $pdo->prepare("UPDATE escrows SET status='funding_pending' WHERE id=:id")->execute([':id'=>$id]);

    $tx = uid('tx_');
    $pdo->prepare("
      INSERT INTO transactions (id, escrow_id, kind, provider, provider_ref, amount)
      VALUES (:id,:esc,'fund',:prov,:pref,:amt)
    ")->execute([
      ':id'=>$tx, ':esc'=>$id, ':prov'=>($_POST['provider'] ?? 'paystack'),
      ':pref'=>uid('PSK_'), ':amt'=>(int)$e['amount']
    ]);

    $pdo->commit();
    respond(200, ['status'=>'funding_pending', 'checkout_url'=>'https://pay.example/checkout/'.$tx]);
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['error'=>'server_error','detail'=>$ex->getMessage()]);
  }
}

function release_escrow(string $id): void {
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $r = $pdo->prepare("SELECT status, amount FROM escrows WHERE id=:id FOR UPDATE");
    $r->execute([':id'=>$id]);
    $e = $r->fetch();
    if (!$e) { $pdo->rollBack(); respond(404, ['error'=>'not_found']); }
    if ($e['status'] !== 'funded' && $e['status'] !== 'funding_pending') { $pdo->rollBack(); respond(409, ['error'=>'invalid_state']); }

    // For demo: mark funded → released
    if ($e['status'] === 'funding_pending') {
      $pdo->prepare("UPDATE escrows SET status='funded' WHERE id=:id")->execute([':id'=>$id]);
    }
    $pdo->prepare("UPDATE escrows SET status='released' WHERE id=:id")->execute([':id'=>$id]);

    $pdo->prepare("
      INSERT INTO transactions (id, escrow_id, kind, provider, provider_ref, amount)
      VALUES (:id,:esc,'release','wallet',:pref,:amt)
    ")->execute([
      ':id'=>uid('tx_'), ':esc'=>$id, ':pref'=>uid('REL_'), ':amt'=>(int)$e['amount']
    ]);

    $pdo->commit();
    respond(200, ['status'=>'released']);
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['error'=>'server_error','detail'=>$ex->getMessage()]);
  }
}

function dispute_escrow(string $id): void {
  $pdo = db();
  $s = $pdo->prepare("SELECT status FROM escrows WHERE id=:id");
  $s->execute([':id'=>$id]);
  $e = $s->fetch();
  if (!$e) respond(404, ['error'=>'not_found']);
  if (in_array($e['status'], ['released','refunded','canceled'], true)) respond(409, ['error'=>'invalid_state']);
  $pdo->prepare("UPDATE escrows SET status='disputed' WHERE id=:id")->execute([':id'=>$id]);
  respond(200, ['status'=>'disputed']);
}

/* -------------------- Router -------------------- */

$parts = route();          // e.g. ['api','escrows','esc_x','release']
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (($parts[0] ?? '') !== 'api') {
  respond(200, ['name'=>'Excrow Mini API','health'=>'ok']);
}

if (($parts[1] ?? '') === 'escrows') {
  // /api/escrows
  if (count($parts) === 2 && $method === 'POST') { create_escrow(); }
  // /api/escrows/{id}
  if (count($parts) === 3 && $method === 'GET') { get_escrow($parts[2]); }
  // /api/escrows/{id}/fund
  if (count($parts) === 4 && $parts[3] === 'fund' &&
