<?php


declare(strict_types=1);
header('Content-Type: application/json');

// ---------- CORS (optional) ----------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Helpers ----------
function env(string $k, ?string $d=null): string {
  $v = getenv($k);
  return ($v === false || $v === '') ? ($d ?? '') : $v;
}
function respond(int $code, array $data=[]): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
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

  // Minimal tables (match index.php) + webhook deliveries
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
      INDEX(escrow_id), INDEX(provider_ref),
      CONSTRAINT fk_tx_esc FOREIGN KEY (escrow_id) REFERENCES escrows(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS webhook_deliveries (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      provider VARCHAR(32) NOT NULL,
      event_id VARCHAR(80) NOT NULL,
      signature VARCHAR(256) NULL,
      received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_provider_event (provider, event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  return $pdo;
}
function uid(string $prefix): string { return $prefix . bin2hex(random_bytes(6)); }

// ---------- Read raw body once ----------
$raw = file_get_contents('php://input') ?: '';
if ($raw === '') respond(400, ['error'=>'empty_body']);

// ---------- Verify Paystack signature ----------
$secret = env('PAYSTACK_SECRET_KEY', '');
if ($secret === '') respond(500, ['error'=>'missing_paystack_secret']);

$providedSig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$computedSig  = hash_hmac('sha512', $raw, $secret);

if (!hash_equals($computedSig, $providedSig)) {
  // Return 400 so Paystack retries; adjust to 200 if you prefer swallow.
  respond(400, ['error'=>'invalid_signature']);
}

// ---------- Parse payload ----------
$payload = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) respond(400, ['error'=>'invalid_json']);

$eventType = (string)($payload['event'] ?? '');
$eventId   = (string)($payload['data']['id'] ?? ($payload['data']['reference'] ?? ''));
if ($eventType === '' || $eventId === '') {
  // We still ack with 200 to avoid infinite retries
  respond(200, ['status'=>'ignored','reason'=>'missing_event_fields']);
}

// ---------- Idempotency (dedupe) ----------
$pdo = db();
try {
  $ins = $pdo->prepare("
    INSERT INTO webhook_deliveries (provider, event_id, signature)
    VALUES ('paystack', :eid, :sig)
  ");
  $ins->execute([':eid'=>$eventId, ':sig'=>$providedSig]);
} catch (PDOException $e) {
  // Duplicate event -> already processed
  if ($e->errorInfo[1] ?? null) { respond(200, ['status'=>'duplicate']); }
  respond(500, ['error'=>'db_error','detail'=>$e->getMessage()]);
}

// ---------- Business logic helpers ----------
function mark_funded(PDO $pdo, string $escrowId, int $amount, string $reference): void {
  // If escrow exists and not already funded, mark funded & record tx (fund)
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT status FROM escrows WHERE id=:id FOR UPDATE");
    $q->execute([':id'=>$escrowId]);
    $e = $q->fetch();
    if (!$e) { $pdo->rollBack(); return; } // Unknown escrow; ignore safely

    if ($e['status'] !== 'funded') {
      $pdo->prepare("UPDATE escrows SET status='funded' WHERE id=:id")->execute([':id'=>$escrowId]);
    }

    // Insert fund tx if not exists for provider_ref
    $txCheck = $pdo->prepare("SELECT id FROM transactions WHERE provider_ref=:ref AND kind='fund' LIMIT 1");
    $txCheck->execute([':ref'=>$reference]);
    if (!$txCheck->fetch()) {
      $pdo->prepare("
        INSERT INTO transactions (id, escrow_id, kind, provider, provider_ref, amount)
        VALUES (:id,:esc,'fund','paystack',:ref,:amt)
      ")->execute([
        ':id'=>uid('tx_'), ':esc'=>$escrowId, ':ref'=>$reference, ':amt'=>$amount
      ]);
    }

    $pdo->commit();
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // We still 200 OK (already recorded webhook_delivery). Manual reconcile later.
  }
}

function mark_released(PDO $pdo, string $escrowId, int $amount, string $reference): void {
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT status FROM escrows WHERE id=:id FOR UPDATE");
    $q->execute([':id'=>$escrowId]);
    $e = $q->fetch();
    if (!$e) { $pdo->rollBack(); return; }

    // Mark released (idempotent)
    if ($e['status'] !== 'released') {
      $pdo->prepare("UPDATE escrows SET status='released' WHERE id=:id")->execute([':id'=>$escrowId]);
    }

    $txCheck = $pdo->prepare("SELECT id FROM transactions WHERE provider_ref=:ref AND kind='release' LIMIT 1");
    $txCheck->execute([':ref'=>$reference]);
    if (!$txCheck->fetch()) {
      $pdo->prepare("
        INSERT INTO transactions (id, escrow_id, kind, provider, provider_ref, amount)
        VALUES (:id,:esc,'release','paystack',:ref,:amt)
      ")->execute([
        ':id'=>uid('tx_'), ':esc'=>$escrowId, ':ref'=>$reference, ':amt'=>$amount
      ]);
    }

    $pdo->commit();
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

function mark_refunded(PDO $pdo, string $escrowId, int $amount, string $reference): void {
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT status FROM escrows WHERE id=:id FOR UPDATE");
    $q->execute([':id'=>$escrowId]);
    $e = $q->fetch();
    if (!$e) { $pdo->rollBack(); return; }

    if ($e['status'] !== 'refunded') {
      $pdo->prepare("UPDATE escrows SET status='refunded' WHERE id=:id")->execute([':id'=>$escrowId]);
    }

    $txCheck = $pdo->prepare("SELECT id FROM transactions WHERE provider_ref=:ref AND kind='refund' LIMIT 1");
    $txCheck->execute([':ref'=>$reference]);
    if (!$txCheck->fetch()) {
      $pdo->prepare("
        INSERT INTO transactions (id, escrow_id, kind, provider, provider_ref, amount)
        VALUES (:id,:esc,'refund','paystack',:ref,:amt)
      ")->execute([
        ':id'=>uid('tx_'), ':esc'=>$escrowId, ':ref'=>$reference, ':amt'=>$amount
      ]);
    }

    $pdo->commit();
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

// ---------- Map Paystack events ----------
// Expecting payloads like:
// event: "charge.success" | "transfer.success" | "transfer.failed" | "refund.processed" etc.
// data.reference -> our payment ref (should map to an escrow_id via your own scheme)
// For this minimal demo, we accept escrow_id in metadata: data.metadata.escrow_id

$ref = (string)($payload['data']['reference'] ?? '');
$amountKobo = (int)($payload['data']['amount'] ?? 0); // Paystack sends in kobo
$amount = intdiv(max($amountKobo, 0), 100); // convert to base unit
$metaEscrowId = $payload['data']['metadata']['escrow_id'] ?? null;
$escrowId = is_string($metaEscrowId) ? $metaEscrowId : '';

if ($eventType === 'charge.success') {
  // Customer paid successfully -> mark escrow funded
  if ($escrowId !== '') {
    mark_funded($pdo, $escrowId, $amount, $ref);
  }
} elseif ($eventType === 'refund.processed') {
  if ($escrowId !== '') {
    mark_refunded($pdo, $escrowId, $amount, $ref);
  }
} elseif ($eventType === 'transfer.success') {
  // Payout to seller succeeded -> mark released
  if ($escrowId !== '') {
    mark_released($pdo, $escrowId, $amount, $ref);
  }
} else {
  // Unhandled types can just be acknowledged
  // e.g., charge.failed, transfer.failed -> you may log or open dispute flow
}

// Always acknowledge so Paystack stops retrying
respond(200, ['status'=>'ok']);
