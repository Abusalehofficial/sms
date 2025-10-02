<?php
declare(strict_types=1);

/**
 * SMSPool Admin Import/Sync (Infozeen)
 * ------------------------------------
 * - Uses SMSPool REST endpoints exactly as in your Postman JSON.
 * - Secure PDO only, strict types, and JSON responses for AJAX.
 * - DB tables expected: sms_services(service_id, name, display_name, updated_at),
 *   sms_countries(provider_id, name_en, local_name, updated_at),
 *   sms_prices(country_id, service_id, provider_cost, pprice, available_count, updated_at).
 */

if (
  ($admin["admin_type"] == 2 || $admin["admin_type"] == 3 || $admin["admin_type"] == 4)
  && !empty($_SESSION["msmbilisim_adminlogin"])
  && (int)$admin["client_type"] === 2
) :
  // --- Security headers
  header_remove('X-Powered-By');
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');

  if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    echo "PDO \$conn not available.";
    exit;
  }

  // --- Config (set via your app)
  $apiKey = isset($app['api_key']) ? (string)$app['api_key'] : '';
  $BASE   = 'https://api.smspool.net'; // correct REST base for SMSPool

  // ===== Utilities =====
  function jres(bool $ok, string $msg = '', array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$ok,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }
 
 
  /**
   * Currency convert using site's stored FX table.
   * Returns float|false if unknown currency.
   */
  function convertCurrencyUpdateds(string $from_currency, string $to_currency, float $amount) {
    global $settings;
    $currentcur = json_decode($settings["currency_conversion_data"] ?? "{}", true);
    if (!is_array($currentcur) || !isset($currentcur["rates"])) return false;

    $from = strtoupper(trim($from_currency));
    $to   = strtoupper(trim($to_currency));
    if (!isset($currentcur["rates"][$from]) || !isset($currentcur["rates"][$to])) return false;

    $fromRate = (float)$currentcur["rates"][$from];
    $toRate   = (float)$currentcur["rates"][$to];
    if ($fromRate <= 0.0 || $toRate <= 0.0) return false;

    $usdAmount = $amount / $fromRate;   // normalize to USD
    $converted = $usdAmount * $toRate;  // to target
    return round($converted, 4);
  }

  

function smspool_post(string $path, array $params = [], ?string $withKey = null): array {
  global $BASE;
  $url = rtrim($BASE, '/') . '/' . ltrim($path, '/');

  // Optional: include key in body for backward-compat
  if ($withKey !== null && !array_key_exists('key', $params)) {
    $params['key'] = $withKey;
  }

  $headers = [];
  if ($withKey !== null) {
    $headers[] = 'Authorization: Bearer ' . $withKey; // SMSPool collection style
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $params,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Infozeen-SMSPool/1.0 PHP',
  ]);
  $body = curl_exec($ch);
  $err  = curl_errno($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err !== 0) throw new RuntimeException("cURL error {$err} calling {$path}");
  if ($code !== 200 || !is_string($body) || $body === '') throw new RuntimeException("HTTP {$code} from {$path}");

  $trim = trim($body);
  $json = json_decode($trim, true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($json)) return $json;
  return ['raw' => $trim];
}

 

  // ===== DB helpers =====
  function upsert_country(PDO $conn, int $providerId, string $name_en): void {
    if ($providerId <= 0 || $name_en === '') return;
    $st = $conn->prepare("
      INSERT INTO sms_countries (provider_id, name_en, local_name)
      VALUES (:id, :ne, NULL)
      ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), updated_at=CURRENT_TIMESTAMP
    ");
    $st->execute([':id'=>$providerId, ':ne'=>$name_en]);
  }

  function cleanup_orphan_countries(PDO $conn): void {
    $conn->exec("
      DELETE FROM sms_countries
      WHERE provider_id NOT IN (SELECT DISTINCT country_id FROM sms_prices)
    ");
  }

  // ===== AJAX router =====
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method === 'POST' && isset($_POST['action'])) {
    try {
      switch ($_POST['action']) {

        // === Countries already in DB (for UI filter) ===
        case 'countries_db_list': {
          $t0  = microtime(true);
          $sql = "SELECT provider_id AS id, COALESCE(local_name, name_en) AS name
                  FROM sms_countries
                  ORDER BY COALESCE(local_name, name_en) ASC";
          $st = $conn->prepare($sql);
          $st->execute();
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          $t = number_format(microtime(true) - $t0, 4);
          jres(true, 'ok', ['rows'=>$rows,'timing'=>"Query took {$t} seconds"]);
        }

        // === Provider countries (SMSPool /country/retrieve_all) — NO key ===
       case 'provider_countries': {
  $t0   = microtime(true);
  $prov = smspool_post('country/retrieve_all', [], null);

  // response can be root array or {data:[...]}
  $rows = (isset($prov['data']) && is_array($prov['data']))
    ? $prov['data']
    : (is_array($prov) ? $prov : []);

  // Already-imported (hide them)
  $used = [];
  foreach ($conn->query("SELECT DISTINCT country_id FROM sms_prices") as $r) {
    $used[(int)$r['country_id']] = true;
  }

  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;

    // Be casing-flexible: ID/id/country_id
    $id = (int)($row['ID'] ?? $row['id'] ?? $row['country_id'] ?? 0);

    // Name can be name/title/name_en
    $nm = (string)($row['name'] ?? $row['title'] ?? $row['name_en'] ?? '');

    if ($id > 0 && $nm !== '' && empty($used[$id])) {
      $out[] = ['id' => $id, 'name' => $nm];
    }
  }

  $t = number_format(microtime(true) - $t0, 4);
  jres(true, 'ok', ['rows' => $out, 'timing' => "API took {$t} seconds"]);
}


        // === Import services (SMSPool /service/retrieve_all) — NO key ===
     case 'import_services': {
  // This endpoint returns an array, sometimes with uppercase keys.
  $payload = smspool_post('service/retrieve_all', [], null);

  // Accept either root array or {data:[...]}
  $rows = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : (is_array($payload) ? $payload : []);

  $st = $conn->prepare("
    INSERT INTO sms_services (service_id, name, display_name)
    VALUES (:sid, :nm, NULL)
    ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=CURRENT_TIMESTAMP
  ");

  $n = 0;
  foreach ($rows as $row) {
    if (!is_array($row)) continue;

    // Be casing-flexible: ID/id/service
    $sid = (string)($row['ID'] ?? $row['id'] ?? $row['service'] ?? '');
    // Name candidates
    $nm  = (string)($row['name'] ?? $row['short_name'] ?? $row['title'] ?? '');

    // Guard: some APIs send numeric IDs; we want stable string keys
    if ($sid !== '' && $nm !== '') {
      $st->execute([':sid' => $sid, ':nm' => $nm]);
      $n++;
    }
  }

  jres(true, "Imported/updated {$n} services", ['total' => $n]);
}


        // === Services list (DB) ===
        case 'services_list': {
          $lim = max(10, min(200, (int)($_POST['limit'] ?? 120)));
          $off = max(0, (int)($_POST['offset'] ?? 0));
          $q   = trim((string)($_POST['q'] ?? ''));
          $sql = "SELECT service_id AS id, name, display_name FROM sms_services WHERE 1";
          $args = [];
          if ($q !== '') { $sql .= " AND (service_id LIKE :q OR name LIKE :q OR display_name LIKE :q)"; $args[':q']="%{$q}%"; }
          $sql .= " ORDER BY name ASC LIMIT :lim OFFSET :off";
          $st = $conn->prepare($sql);
          foreach ($args as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
          $st->bindValue(':lim', $lim, PDO::PARAM_INT);
          $st->bindValue(':off', $off, PDO::PARAM_INT);
          $st->execute();
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          jres(true, 'ok', ['rows'=>$rows,'next_offset'=>(count($rows)<$lim?null:$off+$lim)]);
        }

        case 'save_service': {
          $sid  = (string)($_POST['service_id'] ?? '');
          $name = trim((string)($_POST['name'] ?? ''));
          if ($sid === '' || $name === '') jres(false, 'Invalid');
          $st = $conn->prepare("UPDATE sms_services SET name=:n, updated_at=CURRENT_TIMESTAMP WHERE service_id=:s");
          $st->execute([':n'=>$name, ':s'=>$sid]);
          jres(true, 'Saved');
        }

        case 'delete_services_selected': {
          $ids = $_POST['ids'] ?? [];
          if (!is_array($ids) || !$ids) jres(false,'No rows selected');
          $ids = array_values(array_unique(array_map('strval', $ids)));
          $in  = implode(',', array_fill(0, count($ids), '?'));
          $st  = $conn->prepare("DELETE FROM sms_services WHERE service_id IN ($in)");
          $st->execute($ids);
          jres(true, "Deleted {$st->rowCount()} row(s)");
        }

        case 'delete_services_filtered': {
          $q = trim((string)($_POST['q'] ?? ''));
          $conn->beginTransaction();
          try {
            if ($q === '') {
              $conn->exec("DELETE p FROM sms_prices p INNER JOIN sms_services s ON s.service_id=p.service_id");
              $conn->exec("DELETE FROM sms_services");
            } else {
              $st = $conn->prepare("SELECT service_id FROM sms_services WHERE service_id LIKE :q OR name LIKE :q OR display_name LIKE :q");
              $st->execute([':q'=>"%{$q}%"]);
              $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
              if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $conn->prepare("DELETE FROM sms_prices WHERE service_id IN ($in)")->execute($ids);
                $conn->prepare("DELETE FROM sms_services WHERE service_id IN ($in)")->execute($ids);
              }
            }
            $conn->commit();
            jres(true, 'Deleted');
          } catch (Throwable $e) {
            $conn->rollBack();
            jres(false,'Delete failed');
          }
        }
// Save any array/object payload to logs as pretty JSON
  
   
    // === Bulk import: multiple countries ===
// === Bulk import: multiple countries (country-only pricing) ===
case 'import_countries_bulk': {
  if ($apiKey === '') jres(false, 'Missing API key');

  $ids = $_POST['country_ids'] ?? [];
  if (!is_array($ids) || !$ids) jres(false, 'Select countries');
  $ids = array_values(array_unique(array_map('intval', $ids)));

  // Country id -> name (NO key)
  $countries = smspool_post('country/retrieve_all', [], null);
  $rowsC     = (isset($countries['data']) && is_array($countries['data'])) ? $countries['data'] : (is_array($countries) ? $countries : []);
  $names     = [];
  foreach ($rowsC as $row) {
    if (!is_array($row)) continue;
    $id = (int)($row['ID'] ?? $row['id'] ?? 0);
    $nm = (string)($row['name'] ?? $row['title'] ?? $row['name_en'] ?? '');
    if ($id > 0 && $nm !== '') $names[$id] = $nm;
  }

  // Allowed services
  $allow = [];
  foreach ($conn->query("SELECT service_id FROM sms_services") as $r) {
    $allow[(string)$r['service_id']] = true;
  }

  $sql = "INSERT INTO sms_prices (country_id, service_id, provider_cost, pprice, available_count, updated_at)
          VALUES (:c,:s,:pc,:pp,:cnt,CURRENT_TIMESTAMP)
          ON DUPLICATE KEY UPDATE provider_cost=VALUES(provider_cost),
                                  pprice=VALUES(pprice),
                                  available_count=VALUES(available_count),
                                  updated_at=CURRENT_TIMESTAMP";
  $st = $conn->prepare($sql);

  $siteCur = strtoupper((string)($settings['site_currency'] ?? 'USD'));
  $total   = 0;

  @set_time_limit(300);

  foreach ($ids as $country) {
    if (isset($names[$country])) upsert_country($conn, $country, $names[$country]);

    // 1) PRICING (only country) — authorised; save raw response
    $pricing = smspool_post('request/pricing', ['country' => (string)$country], $apiKey);
 
    if (!is_array($pricing) || empty($pricing)) {
      // No rows for this country
      continue;
    }

    // 2) Pick lowest price per service (best pool)
    //    best[service_id] = ['price'=>float, 'pool'=>int|null]
    $best = [];

    foreach ($pricing as $row) {
      if (!is_array($row)) continue;

      // service id (case flexible)
      $sidRaw = $row['service'] ?? $row['Service'] ?? $row['SERVICE'] ?? null;
      if ($sidRaw === null) continue;
      $sid = (string)$sidRaw;
      if ($sid === '' || empty($allow[$sid])) continue;

      // price (string) -> float
      $priceStr = (string)($row['price'] ?? '');
      if ($priceStr === '' || !is_numeric($priceStr)) continue;
      $price = (float)$priceStr;
      if (!is_finite($price) || $price <= 0.0) continue;

      // pool (optional)
      $poolId = null;
      if (isset($row['pool']) && is_numeric($row['pool'])) $poolId = (int)$row['pool'];

      if (!isset($best[$sid]) || $price < $best[$sid]['price']) {
        $best[$sid] = ['price' => $price, 'pool' => $poolId];
      }
    }

    if (!$best) continue;

    // 3) Insert chosen prices (+ stock per chosen tuple)
    $conn->beginTransaction();
    try {
      $n = 0;
      foreach ($best as $sid => $info) {
        $raw    = (float)$info['price']; // USD
        $poolId = $info['pool'];         // may be null

        // (Optional) STOCK — comment next 8 lines to skip for speed
        $sp = ['country' => (string)$country, 'service' => (string)$sid];
        if ($poolId !== null) $sp['pool'] = (string)$poolId;
         $qty = $poolId;
         
$stmt = $conn->prepare("SELECT name_en FROM sms_countries WHERE provider_id = :country_id LIMIT 1");
$stmt->bindParam(':country_id', $country, PDO::PARAM_INT);
$stmt->execute();

// Fetch single column
$countryName = $stmt->fetchColumn();

        // Prices into DB
      $pc = convertCurrencyUpdateds("USD", $settings["site_currency"], $raw);
          $pc = $pc;

  $pprice = convertCurrencyUpdateds($settings["site_currency"], getCurrencyByCountry($countryName), $pc);
        $st->execute([
          ':c'   => $country,
          ':s'   => (string)$sid,
          ':pc'  => $pc,
          ':pp'  => $pprice,
          ':cnt' => $qty
        ]);
        $n++;
      }

      $conn->commit();
      $total += $n;
    } catch (Throwable $e) {
      $conn->rollBack();
      // Optional: error_log('bulk import failed for country '.$country.': '.$e->getMessage());
    }
  }

  jres(true, "Imported/updated {$total} rows", ['total' => $total]);
}


        // === Prices list (DB) ===
        case 'prices_list': {
          $lim = max(10, min(200, (int)($_POST['limit'] ?? 120)));
          $off = max(0, (int)($_POST['offset'] ?? 0));
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);

          $sql = "SELECT 
                    p.country_id,
                    COALESCE(c.local_name, c.name_en) AS country_name,
                    p.service_id,
                    p.provider_cost,
                    p.pprice,
                    p.available_count,
                    COALESCE(s.display_name, s.name) AS service_name
                  FROM sms_prices p
                  LEFT JOIN sms_countries c ON c.provider_id = p.country_id
                  LEFT JOIN sms_services  s ON s.service_id   = p.service_id
                  WHERE 1";
          $args = [];

          if ($cid > 0) { $sql .= " AND p.country_id = :cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q 
                        OR s.name LIKE :q 
                        OR s.display_name LIKE :q 
                        OR c.name_en LIKE :q 
                        OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }

          $sql .= " ORDER BY p.country_id ASC, p.service_id ASC
                    LIMIT :lim OFFSET :off";

          $st = $conn->prepare($sql);
          if (array_key_exists(':cid',$args)) { $st->bindValue(':cid', (int)$args[':cid'], PDO::PARAM_INT); unset($args[':cid']); }
          if (array_key_exists(':q',$args))   { $st->bindValue(':q',   (string)$args[':q'], PDO::PARAM_STR); unset($args[':q']); }
          $st->bindValue(':lim', $lim, PDO::PARAM_INT);
          $st->bindValue(':off', $off, PDO::PARAM_INT);
          $st->execute();
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);

          foreach ($rows as &$r) {
            $provCurrency = getCurrencyByCountry($r['country_name'] ?? null);
            $r['pprice_currency'] = $provCurrency;
            $r['pprice_label']    = ($r['pprice'] !== null && $r['pprice'] !== '')
                                  ? ($r['pprice'] . ' ' . $provCurrency)
                                  : null;
          }
          unset($r);

          jres(true, 'ok', [
            'rows' => $rows,
            'next_offset' => (count($rows) < $lim ? null : $off + $lim),
          ]);
        }

        // === Save single provider_cost (manual edit) ===
        case 'save_price': {
          $cid = (int)($_POST['country_id'] ?? 0);
          $sid = (string)($_POST['service_id'] ?? '');
          $pv  = trim((string)($_POST['provider_cost'] ?? ''));
          if ($cid <= 0 || $sid === '') jres(false, 'Invalid');
          $val = ($pv === '') ? null : number_format((float)$pv, 4, '.', '');
          $st = $conn->prepare("UPDATE sms_prices SET provider_cost=:pc, updated_at=CURRENT_TIMESTAMP WHERE country_id=:c AND service_id=:s");
          $st->execute([':pc'=>$val, ':c'=>$cid, ':s'=>$sid]);
          jres(true, 'Saved');
        }

        // === Bulk profit adjust ===
        case 'bulk_profit': {
          $percent = (float)($_POST['percent'] ?? 0);
          if ($percent === 0.0) jres(false, 'Percent required');
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);
          $factor = 1.0 + ($percent / 100.0);

          $sql = "UPDATE sms_prices p
                  LEFT JOIN sms_services s  ON s.service_id=p.service_id
                  LEFT JOIN sms_countries c ON c.provider_id=p.country_id
                  SET p.provider_cost=ROUND(p.provider_cost*:f,4), p.updated_at=CURRENT_TIMESTAMP
                  WHERE 1";
          $args = [':f'=>$factor];
          if ($cid > 0) { $sql .= " AND p.country_id=:cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q OR s.name LIKE :q OR s.display_name LIKE :q OR c.name_en LIKE :q OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }
          $st = $conn->prepare($sql);
          foreach ($args as $k=>$v) $st->bindValue($k, $v, is_string($v) ? PDO::PARAM_STR : PDO::PARAM_STR);
          $st->execute();
          jres(true, 'Profit applied');
        }

        // === Delete selected price rows ===
        case 'delete_prices_selected': {
          $items = $_POST['items'] ?? [];
          if (!is_array($items) || !$items) jres(false, 'No rows selected');
          $pairs = [];
          foreach ($items as $k) {
            $parts = explode('|', (string)$k, 2);
            if (count($parts) === 2) {
              $c = (int)$parts[0]; $s = trim($parts[1]);
              if ($c > 0 && $s !== '') $pairs[] = ['c'=>$c,'s'=>$s];
            }
          }
          if (!$pairs) jres(false, 'Invalid selection');

          $conn->beginTransaction();
          try {
            $st = $conn->prepare("DELETE FROM sms_prices WHERE country_id=:c AND service_id=:s");
            foreach ($pairs as $p) $st->execute([':c'=>$p['c'], ':s'=>$p['s']]);
            $conn->commit();
            cleanup_orphan_countries($conn);
            jres(true, 'Deleted selected');
          } catch (Throwable $e) {
            $conn->rollBack();
            jres(false, 'Delete failed');
          }
        }

        // === Delete prices by filter ===
        case 'delete_prices_filtered': {
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);

          $sql = "DELETE p FROM sms_prices p
                  LEFT JOIN sms_services s  ON s.service_id=p.service_id
                  LEFT JOIN sms_countries c ON c.provider_id=p.country_id
                  WHERE 1";
          $args = [];
          if ($cid > 0) { $sql .= " AND p.country_id=:cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q OR s.name LIKE :q OR s.display_name LIKE :q OR c.name_en LIKE :q OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }

          $st = $conn->prepare($sql);
          foreach ($args as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
          $st->execute();
          cleanup_orphan_countries($conn);
          jres(true, "Deleted {$st->rowCount()} row(s)");
        }

        default: jres(false, 'Unknown action');
      }
    } catch (Throwable $e) {
      jres(false, $e->getMessage());
    }
    exit;
  }

 
 
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SMS Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{background:#fff;color:#000}
.card{background:#fff;border:1px solid #e6e6e6;border-radius:12px}
.table thead th{color:#000;border-bottom-color:#e6e6e6}
.table tbody td{color:#000;border-top-color:#f1f1f1}
.scroll-box{max-height:65vh;overflow:auto}
.table tbody tr{height:56px}
.badge-code{background:#eef3ff;border:1px solid #d9e4ff;color:#1b3d8a;padding:.25rem .5rem;border-radius:7px}
.tools{position:sticky;top:-1px;background:#fff;border-bottom:1px solid #eee;z-index:3;padding-bottom:6px;margin-bottom:10px}
tfoot td{background:#fafafa}
</style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">SMS Admin</h4>
    <?php if(!$apiKey): ?><span class="badge bg-danger">API key missing</span><?php else: ?><span class="badge bg-success">API ready</span><?php endif; ?>
  </div>

  <ul class="nav nav-pills mb-3">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-services">Services</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-prices">Country-wise Prices</button></li>
  </ul>

  <div class="tab-content">

    <!-- SERVICES -->
<div class="tab-pane fade show active" id="tab-services">
  <div class="card"><div class="card-body">
    <div class="tools d-flex flex-column flex-lg-row gap-2 align-items-stretch">
      <div class="me-auto">
        <h6 class="mb-0">Services</h6>
        <small class="text-muted">Import from API (uses <b>title</b>), edit names, delete selected/all.</small>
      </div>
      <input id="sSearch" class="form-control" placeholder="Search services…">
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="btnImportServices">Import</button>
        <button class="btn btn-outline-danger btn-sm" id="btnSvcDeleteSelected" disabled>Delete Selected</button>
        <button class="btn btn-danger btn-sm" id="btnSvcDeleteAll">Delete All (filtered)</button>
      </div>
    </div>
    <div class="table-responsive scroll-box" id="boxServices">
      <table class="table table-sm align-middle mb-0" id="tblServices">
        <thead><tr>
          <th style="width:26px"><input type="checkbox" id="svcChkAll"></th>
          <th style="width:160px">Service ID</th>
          <th>Name</th>
          <th style="width:110px">Save</th>
          <th style="width:90px">Delete</th>
        </tr></thead>
        <tbody></tbody>
        <tfoot><tr id="sFoot"><td colspan="5" class="text-center text-muted small py-2">Loading…</td></tr></tfoot>
      </table>
    </div>
  </div></div>
</div>
    <!-- PRICES -->
    <div class="tab-pane fade" id="tab-prices">
      <div class="card"><div class="card-body">
        <div class="tools d-flex flex-column flex-lg-row gap-2 align-items-stretch">
          <div class="me-auto">
            <h6 class="mb-0">Country-wise Prices</h6>
            <small class="text-muted">Filter/search, bulk profit, multi-import, and deletes. Import list hides already-imported countries.</small>
          </div>
          <div class="d-flex gap-2">
            <select id="filterCountry" class="form-select"><option value="0">All countries</option></select>
            <input id="pSearch" class="form-control" placeholder="Search (service/country)…">
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <input id="profitPercent" type="number" step="0.1" class="form-control" placeholder="% profit">
            <button class="btn btn-outline-primary btn-sm" id="btnApplyProfit">Set Profit</button>
            <button class="btn btn-outline-secondary btn-sm" id="btnImportOpen">Import</button>
            <button class="btn btn-outline-danger btn-sm" id="btnDeleteSelected" disabled>Delete Selected</button>
            <button class="btn btn-danger btn-sm" id="btnDeleteAll">Delete All (filtered)</button>
          </div>
        </div>
        <div class="table-responsive scroll-box" id="boxPrices">
          <table class="table table-sm align-middle mb-0" id="tblPrices">
            <thead><tr>
              <th style="width:26px"><input type="checkbox" id="chkAllRows"></th>
              <th style="width:90px">Country</th>
              <th style="min-width:140px">Country Name</th>
              <th style="width:160px">Service Code</th>
              <th>Service Name</th>
              <th style="width:120px">Price</th>
              <th style="width:100px">Avail</th>
              <th style="width:90px">Save</th>
              <th style="width:90px">Delete</th>
            </tr></thead>
            <tbody></tbody>
            <tfoot><tr id="pFoot"><td colspan="9" class="text-center text-muted small py-2">Loading…</td></tr></tfoot>
          </table>
        </div>
      </div></div>
    </div>

  </div>
</div>

<!-- Import Modal (Prices) -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">Import Prices by Country</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><input id="imSearch" class="form-control" placeholder="Search countries…"></div>
        <div class="list-group small" id="imList" style="max-height:55vh;overflow:auto"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="imSelectAll"><label class="form-check-label" for="imSelectAll">Select All</label></div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" id="btnImportSelected" disabled>Import Selected</button>
          <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* helpers */
const toast=(icon,title)=>Swal.fire({toast:true,position:'top-end',timer:1800,showConfirmButton:false,icon,title});
function post(action,data,cb){ $.ajax({url:'',method:'POST',data:Object.assign({action},data||{}),dataType:'json',success:cb,error:()=>cb({status:false,message:'Network error'})}); }
function escapeHtml(s){return $('<div/>').text(s==null?'':String(s)).html();}
function escapeAttr(s){return String(s==null?'':s).replace(/"/g,'&quot;');}
function sFoot(text,show){ $('#sFoot').toggle(!!show).find('td').text(text||''); }
function pFoot(text,show){ $('#pFoot').toggle(!!show).find('td').text(text||''); }

/* SERVICES */
let sOffset=0,sBusy=false,sDone=false,sLimit=120,sQ='';
const svcSel=new Set();
function refreshSvcDelete(){ $('#btnSvcDeleteSelected').prop('disabled', svcSel.size===0).text(svcSel.size?`Delete Selected (${svcSel.size})`:'Delete Selected'); }
function resetServices(){ sOffset=0;sBusy=false;sDone=false; svcSel.clear(); $('#svcChkAll').prop('checked',false); refreshSvcDelete(); $('#tblServices tbody').empty(); sFoot('Loading…',true); }
function loadServices(){
  if(sBusy||sDone) return; sBusy=true; sFoot('Loading…',true);
  post('services_list',{offset:sOffset,limit:sLimit,q:sQ},res=>{
    sBusy=false;
    if(!res.status){ sFoot('Failed to load',true); return; }
    const rows=res.data.rows||[];
    if(!rows.length){ if(sOffset===0) $('#tblServices tbody').html('<tr><td colspan="5" class="text-muted">No results</td></tr>'); sDone=true; sFoot('All loaded',true); return; }
    const buf=[];
rows.forEach(r=>{
  const id=escapeAttr(r.id);
  buf.push(`<tr data-id="${id}">
    <td><input type="checkbox" class="svc-chk"></td>
    <td><span class="badge-code">${escapeHtml(r.id)}</span></td>
    <td style="min-width:220px">
      <input class="form-control js-svc-name" 
             value="${escapeAttr(r.name||'')}" 
             placeholder="Edit name">
    </td>
    <td><button class="btn btn-primary btn-sm js-svc-save">Save</button></td>
    <td><button class="btn btn-outline-danger btn-sm js-svc-del">Delete</button></td>
  </tr>`);
});
    $('#tblServices tbody').append(buf.join(''));
    sOffset = res.data.next_offset ?? sOffset;
    sFoot(res.data.next_offset==null?'All loaded':'Scroll to load more…',true);
    if(res.data.next_offset==null) sDone=true;
  });
}
$('#boxServices').on('scroll',function(){ if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadServices(); });
$('#btnImportServices').on('click',()=>Swal.fire({icon:'question',title:'Import services from API?',showCancelButton:true}).then(r=>{
  if(!r.isConfirmed) return;
  Swal.fire({title:'Importing…',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
  post('import_services',{},res=>{ Swal.close(); res.status?toast('success',res.message):toast('error',res.message||'Failed'); resetServices(); loadServices();});
}));
$(document).on('change','.svc-chk',function(){ const id=$(this).closest('tr').data('id'); if(this.checked) svcSel.add(String(id)); else svcSel.delete(String(id)); refreshSvcDelete(); });
$('#svcChkAll').on('change',function(){ const on=this.checked; $('#tblServices tbody .svc-chk').each(function(){ const id=$(this).closest('tr').data('id'); $(this).prop('checked',on); if(on) svcSel.add(String(id)); else svcSel.delete(String(id)); }); refreshSvcDelete(); });
$(document).on('click','.js-svc-save',function(){ const $tr=$(this).closest('tr'); post('save_service',{service_id:$tr.data('id'),name:$tr.find('.js-svc-name').val().trim()},res=>{ res.status?toast('success','Saved'):toast('error',res.message||'Failed'); }); });
$(document).on('click','.js-svc-del',function(){ const id=$(this).closest('tr').data('id'); Swal.fire({icon:'warning',title:`Delete service ${id}?`,showCancelButton:true}).then(r=>{ if(!r.isConfirmed) return; post('delete_services_selected',{ids:[id]},res=>{ res.status?(toast('success','Deleted'),resetServices(),loadServices()):toast('error',res.message||'Failed'); });}); });
$('#btnSvcDeleteSelected').on('click',()=>{ if(!svcSel.size) return; Swal.fire({icon:'warning',title:`Delete ${svcSel.size} selected?`,showCancelButton:true}).then(r=>{ if(!r.isConfirmed) return; post('delete_services_selected',{ids:[...svcSel]},res=>{ res.status?(toast('success',res.message||'Deleted'),resetServices(),loadServices()):toast('error',res.message||'Failed'); });}); });
let sT=null; $('#sSearch').on('input',function(){ sQ=this.value.trim(); clearTimeout(sT); sT=setTimeout(()=>{ resetServices(); loadServices(); },350); });
$('#btnSvcDeleteAll').on('click', ()=>{
  const scope = sQ ? `matching "<b>${escapeHtml(sQ)}</b>"` : 'ALL services';
  Swal.fire({
    icon:'warning',
    title:'Delete services?',
    html:`This will delete ${scope} (and their price rows).`,
    showCancelButton:true, confirmButtonText:'Delete'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_services_filtered', {q:sQ}, res=>{
      if(res.status){
        toast('success','Deleted');
        resetServices(); loadServices();
      }else toast('error',res.message||'Failed');
    });
  });
});

/* PRICES */
const sel=new Set();
function refreshDeleteBtn(){ $('#btnDeleteSelected').prop('disabled', sel.size===0).text(sel.size?`Delete Selected (${sel.size})`:'Delete Selected'); }
function loadFilterCountries(){ post('countries_db_list',{},res=>{ const $f=$('#filterCountry'); $f.find('option:not([value="0"])').remove(); if(res.status&&res.data.rows){ res.data.rows.forEach(r=>$f.append(`<option value="${r.id}">${escapeHtml(r.name)}</option>`)); } }); }

let pOffset=0,pBusy=false,pDone=false,pLimit=120,pQ='',pCountry=0;
function resetPrices(){ pOffset=0;pBusy=false;pDone=false; sel.clear(); $('#chkAllRows').prop('checked',false); refreshDeleteBtn(); $('#tblPrices tbody').empty(); pFoot('Loading…',true); }
function loadPrices(){
  if(pBusy||pDone) return; pBusy=true; pFoot('Loading…',true);
  post('prices_list',{offset:pOffset,limit:pLimit,q:pQ,country_id:pCountry},res=>{
    pBusy=false;
    if(!res.status){ pFoot('Failed to load',true); return; }
    const rows=res.data.rows||[];
    if(!rows.length){ if(pOffset===0) $('#tblPrices tbody').html('<tr><td  colspan="9" class="text-muted">No results</td></tr>'); pDone=true; pFoot('All loaded',true); return; }
    const buf=[];
    rows.forEach(r=>{
    const key = `${r.country_id}|${r.service_id}`;
      buf.push(`<tr data-country="${r.country_id}" data-service="${escapeAttr(r.service_id)}" data-key="${key}">
        <td><input type="checkbox" class="row-chk"></td>
        <td>${escapeHtml(String(r.country_id))}</td>
        <td>${escapeHtml(r.country_name||'')}</td>
        <td><span class="badge-code">${escapeHtml(r.service_id)}</span></td>
        <td>${escapeHtml(r.service_name||'')}</td>
        <td style="min-width:160px">
          <input class="form-control js-price" 
                 value="${escapeAttr(r.provider_cost!=null?r.provider_cost:'')}" 
                 placeholder="Enter price">
          <div class="text-dark mt-1" style="font-size:12px">
            Provider: ${escapeHtml(r.pprice_label!=null?r.pprice_label:'-')}
          </div>
        </td>
        <td>${escapeHtml(r.available_count)}</td>
        <td><button class="btn btn-primary btn-sm js-save-price">Save</button></td>
        <td><button class="btn btn-outline-danger btn-sm js-del-price">Delete</button></td>
      </tr>`);
    });
    $('#tblPrices tbody').append(buf.join(''));
    pOffset = res.data.next_offset ?? pOffset;
    if(res.data.next_offset==null){ pDone=true; pFoot('All loaded',true); } else { pFoot('Scroll to load more…',true); }
  });
}
$('#boxPrices').on('scroll',function(){ if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadPrices(); });

$(document).on('change','.row-chk', function(){ const key=$(this).closest('tr').data('key'); if(this.checked) sel.add(String(key)); else sel.delete(String(key)); refreshDeleteBtn(); });
$('#chkAllRows').on('change', function(){ const on=this.checked; $('#tblPrices tbody tr').each(function(){ const key=$(this).data('key'); const $cb=$(this).find('.row-chk'); $cb.prop('checked',on); if(on) sel.add(String(key)); else sel.delete(String(key)); }); refreshDeleteBtn(); });

$(document).on('click','.js-save-price',function(){ const $tr=$(this).closest('tr'); post('save_price',{country_id:$tr.data('country'),service_id:$tr.data('service'),provider_cost:$tr.find('.js-price').val().trim()},res=>{ res.status?toast('success','Saved'):toast('error',res.message||'Failed'); }); });
$(document).on('click','.js-del-price',function(){ const $tr=$(this).closest('tr'); const key=$tr.data('key'); Swal.fire({icon:'warning',title:'Delete this row?',showCancelButton:true}).then(r=>{ if(!r.isConfirmed) return; post('delete_prices_selected',{items:[key]},res=>{ res.status?(toast('success','Deleted'),resetPrices(),loadPrices()):toast('error',res.message||'Failed'); }); }); });

$('#btnApplyProfit').on('click',()=>{ const pct=parseFloat($('#profitPercent').val()); if(isNaN(pct)||pct===0){toast('error','Enter %');return;} Swal.fire({icon:'question',title:`Apply ${pct}% profit?`,showCancelButton:true}).then(r=>{ if(!r.isConfirmed) return; post('bulk_profit',{percent:pct,q:pQ,country_id:pCountry},res=>{ res.status?(toast('success','Profit applied'),resetPrices(),loadPrices()):toast('error',res.message||'Failed'); }); }); });
$('#btnDeleteSelected').on('click',()=>{ if(!sel.size) return; Swal.fire({icon:'warning',title:`Delete ${sel.size} selected?`,showCancelButton:true}).then(r=>{ if(!r.isConfirmed) return; post('delete_prices_selected',{items:[...sel]},res=>{ res.status?(toast('success',res.message||'Deleted'),resetPrices(),loadPrices()):toast('error',res.message||'Failed'); }); }); });
$('#btnDeleteAll').on('click',()=>{ const scope=(pCountry>0?`country #${pCountry}`:'all countries'); Swal.fire({icon:'warning',title:'Delete ALL filtered rows?',html:`This will delete every price matching <b>${scope}</b>${pQ?` and search "<b>${escapeHtml(pQ)}</b>"`:''}.`,showCancelButton:true,confirmButtonText:'Delete'}).then(r=>{ if(!r.isConfirmed) return; post('delete_prices_filtered',{country_id:pCountry,q:pQ},res=>{ res.status?(toast('success',res.message||'Deleted'),resetPrices(),loadPrices()):toast('error',res.message||'Failed'); }); }); });

let st=null; $('#pSearch').on('input',function(){ pQ=this.value.trim(); clearTimeout(st); st=setTimeout(()=>{ sel.clear(); $('#chkAllRows').prop('checked',false); refreshDeleteBtn(); resetPrices(); loadPrices(); },350); });
$('#filterCountry').on('change',function(){ pCountry=parseInt(this.value||'0',10)||0; sel.clear(); $('#chkAllRows').prop('checked',false); refreshDeleteBtn(); resetPrices(); loadPrices(); });

/* === Mini patch: import modal + filter refresh === */
const importModal = new bootstrap.Modal('#importModal');
const imSel = new Set();
let provCountries = [];

function renderImportList(rows,q){
  imSel.clear();
  $('#imSelectAll').prop('checked',false);
  $('#btnImportSelected').prop('disabled',true).text('Import Selected');
  const qq=(q||'').toLowerCase();
  const html=(rows||[])
    .filter(r=>!qq||r.name.toLowerCase().includes(qq)||String(r.id).includes(qq))
    .map(r=>`<label class="list-group-item d-flex justify-content-between">
      <span class="d-flex align-items-center gap-2">
        <input type="checkbox" class="form-check-input im-chk" data-id="${r.id}">
        <span>${escapeHtml(r.name)}</span>
      </span>
      <span class="badge bg-light text-dark">${r.id}</span>
    </label>`).join('');
  $('#imList').html(html || '<div class="text-muted py-3 text-center">No countries</div>');
}

$('#btnImportOpen').on('click',()=>{
  $('#imList').html('<div class="text-center text-muted py-3">Loading…</div>');
  $('#imSearch').val(''); $('#imSelectAll').prop('checked',false);
  $('#btnImportSelected').prop('disabled',true).text('Import Selected');
  imSel.clear(); importModal.show();
  post('provider_countries',{},res=>{
    provCountries = (res.status && res.data.rows) ? res.data.rows : [];
    renderImportList(provCountries,'');
    $('#imSearch').off('input').on('input',function(){ renderImportList(provCountries,this.value.trim()); });
  });
});

$(document).on('change','.im-chk',function(){
  const id=+$(this).data('id'); this.checked?imSel.add(id):imSel.delete(id);
  $('#btnImportSelected').prop('disabled',imSel.size===0)
    .text(imSel.size?`Import Selected (${imSel.size})`:'Import Selected');
});

$('#imSelectAll').on('change',function(){
  const on=this.checked;
  $('#imList .im-chk').each(function(){
    $(this).prop('checked',on);
    const id=+$(this).data('id'); on?imSel.add(id):imSel.delete(id);
  });
  $('#btnImportSelected').prop('disabled',imSel.size===0)
    .text(imSel.size?`Import Selected (${imSel.size})`:'Import Selected');
});

$('#btnImportSelected').on('click',()=>{
  if(!imSel.size) return;
  const chosen=[...imSel];
  Swal.fire({icon:'question',title:`Import ${chosen.length} countr${chosen.length>1?'ies':'y'}?`,showCancelButton:true})
  .then(r=>{
    if(!r.isConfirmed) return;
    Swal.fire({title:'Importing…',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    post('import_countries_bulk',{country_ids:chosen},res=>{
      Swal.close();
      if(!res.status){ toast('error',res.message||'Failed'); return; }
      toast('success',res.message||'Imported'); importModal.hide();

      // 🔁 refresh filter options
      loadFilterCountries();

      // auto-select if exactly one imported
      if(chosen.length===1){
        const val=String(chosen[0]);
        $('#filterCountry').val(val).trigger('change');
      }else{
        // keep current selection; just refresh table
        if(typeof resetPrices==='function'){ resetPrices(); loadPrices(); }
      }
    });
  });
});

/* Boot */
$(function(){
  // Services visible first
  resetServices(); loadServices();
  // Prices init when opened
  $('button[data-bs-target="#tab-prices"]').one('shown.bs.tab', ()=>{ loadFilterCountries(); resetPrices(); loadPrices(); });
});
</script>
</body></html><?php 


else :

   include FILES_BASE . '/admin/controller/404.php';
    exit();
    $route[1] = "index";
endif;

exit; ?>
