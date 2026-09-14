<?php
/* =====================================================================
 *  CONST-WMS — Supabase backend  (api_supabase.php)
 *  ---------------------------------------------------------------------
 *  DROP-IN replacement for the Google Apps Script (Code.gs) backend.
 *  Speaks the SAME action API the web app (index.html) and the mobile app
 *  (mobile.html) already use — so you only paste this file's URL into the
 *  app's "Web App URL" box; no client code changes.
 *
 *     GET  ?action=ping                         -> health (no key needed)
 *     GET  ?action=version&key=KEY              -> { ok, version, lastModified }
 *     GET  ?action=load&key=KEY                 -> { ok, data:{...META...}, version }
 *     POST ?action=save&key=KEY   body=META     -> { ok, version, converted, warnings }
 *     POST ?action=sync&key=KEY   body={data:META}
 *     POST ?action=uploadimg&key=KEY  body={cid,code,data}  -> { ok, url }
 *     POST ?action=uploadfile&key=KEY body={name,data,folder} -> { ok, url, id }
 *     GET  ?action=delimg&key=KEY               -> { ok }
 *
 *  STORAGE MODEL: one real relational table per entity (columns, NOT JSON).
 *  Nested line-items (PO/SO/BOQ/BOM items, components, SO workers) live in
 *  dedicated CHILD tables. Every table keeps an `extra jsonb` safety-net so a
 *  field the app adds later is never lost. See supabase_schema.sql.
 *
 *  Saves are FULL-OVERWRITE (same semantics as the old backend): the app
 *  posts the whole META; this connector wipes + rewrites the domain tables in
 *  one shot. The client always keeps a localStorage copy, so a failed save
 *  never loses the working data — it just retries.
 * ===================================================================== */

/* ---------- config (override in config_supabase.php) ---------- */
$CFG = [
    'url'         => 'https://YOUR_PROJECT_REF.supabase.co',
    'service_key' => 'YOUR_SUPABASE_SERVICE_ROLE_KEY',   // legacy service_role (eyJ...) or sb_secret_...
    'bucket'      => 'constwms',
    'api_key'     => 'CONSTRUCT_SECRET_2026',             // matches the app's default GS_KEY
];
if (is_file(__DIR__ . '/config_supabase.php')) { $CFG = array_merge($CFG, (array)(require __DIR__ . '/config_supabase.php')); }
$CFG['url'] = rtrim($CFG['url'], '/');

/* ---------- headers ---------- */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok($d)       { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail($c, $m) { http_response_code($c); echo json_encode(['ok'=>false, 'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$action = $_GET['action'] ?? 'ping';

/* ---------- auth (ping is public; everything else needs the app key) ---------- */
function client_key() {
    foreach (['key','token','secret','k'] as $p) if (isset($_GET[$p]) && $_GET[$p] !== '') return preg_replace('/^Bearer\s+/i','',(string)$_GET[$p]);
    if (isset($_SERVER['HTTP_X_API_KEY'])) return (string)$_SERVER['HTTP_X_API_KEY'];
    return '';
}
if ($action !== 'ping') {
    $need = (string)($CFG['api_key'] ?? '');
    if ($need !== '' && !hash_equals($need, client_key())) fail(401, 'Unauthorized (key ไม่ตรง)');
}

/* ---------- read raw body (app posts text/plain to avoid CORS preflight) ---------- */
$method = $_SERVER['REQUEST_METHOD'];
$body = null;
if ($method === 'POST' || $method === 'PUT') {
    $raw  = file_get_contents('php://input');
    $clen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($raw === '' && $clen > 0) fail(413, 'body ถูกตัด — เกิน post_max_size ('.ini_get('post_max_size').') กรุณาเพิ่มค่าใน php.ini');
    $body = ($raw === '') ? null : json_decode($raw, true);
    if ($raw !== '' && $body === null) fail(400, 'JSON body ไม่ถูกต้อง ('.json_last_error_msg().')');
}

/* =====================================================================
 *  SCHEMA MAP — MUST mirror supabase_schema.sql exactly.
 *  parent partitionKey => [ sql table, key-field (child link source),
 *                           children{ recordField => childTable },
 *                           cols[ field => 'text'|'num'|'bool' ] ]
 * ===================================================================== */
$PARENTS = [
  'itemMaster' => ['t'=>'item_master','key'=>'code','children'=>['components'=>'item_components'],
      'cols'=>['code'=>'text','name'=>'text','type'=>'text','unit'=>'text','cat'=>'text','sup'=>'text','img'=>'text','minQty'=>'num','loc'=>'text','qty'=>'num','_isStockPos'=>'bool','isBOM'=>'bool']],
  'locationMaster' => ['t'=>'location_master','key'=>'code','children'=>[],
      'cols'=>['code'=>'text','warehouse'=>'text','type'=>'text','desc'=>'text','site'=>'text']],
  'suppliers' => ['t'=>'suppliers','key'=>'code','children'=>[],
      'cols'=>['code'=>'text','name'=>'text','contact'=>'text','phone'=>'text','address'=>'text','taxId'=>'text','email'=>'text','note'=>'text']],
  'customers' => ['t'=>'customers','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','name'=>'text','contact'=>'text','phone'=>'text','address'=>'text','taxId'=>'text','email'=>'text','note'=>'text']],
  'labelMaster' => ['t'=>'label_master','key'=>'barcode','children'=>[],
      'cols'=>['supplier'=>'text','item_code'=>'text','barcode'=>'text','before_digit'=>'num','after_digit'=>'num']],
  'boqMasters' => ['t'=>'boq_masters','key'=>'id','children'=>['items'=>'boq_master_items'],
      'cols'=>['id'=>'text','name'=>'text','desc'=>'text']],
  'boqTemplates' => ['t'=>'boq_templates','key'=>'id','children'=>['items'=>'boq_template_items'],
      'cols'=>['id'=>'text','name'=>'text','projectId'=>'text','date'=>'text','status'=>'text']],
  'bomMasters' => ['t'=>'bom_masters','key'=>'code','children'=>['components'=>'bom_components'],
      'cols'=>['code'=>'text','name'=>'text','unit'=>'text','cat'=>'text','minQty'=>'num','img'=>'text']],
  'requisitions' => ['t'=>'requisitions','key'=>'id','children'=>['items'=>'requisition_items'],
      'cols'=>['id'=>'text','projectId'=>'text','boqRef'=>'text','boqName'=>'text','date'=>'text','status'=>'text']],
  'workers' => ['t'=>'workers','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','name'=>'text','skill'=>'text','dailyRate'=>'num','status'=>'text','img'=>'text','role'=>'text','phone'=>'text','pin'=>'text']],
  'workerJobs' => ['t'=>'worker_jobs','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','workerId'=>'text','name'=>'text','projectId'=>'text','role'=>'text','date'=>'text','status'=>'text','dailyRate'=>'num','days'=>'num','amount'=>'num']],
  'history' => ['t'=>'history','key'=>'id','children'=>[],
      'cols'=>['id'=>'num','type'=>'text','subtype'=>'text','date'=>'text','time'=>'text','code'=>'text','name'=>'text','qty'=>'num','unit'=>'text','loc'=>'text','toLoc'=>'text','fromLoc'=>'text','project'=>'text','dept'=>'text','note'=>'text','soRef'=>'text','customer'=>'text','status'=>'text','orderNo'=>'text']],
  'stockLedger' => ['t'=>'stock_ledger','key'=>'code','children'=>[],
      'cols'=>['code'=>'text','loc'=>'text','qty'=>'num']],
  'assetMovements' => ['t'=>'asset_movements','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','assetCode'=>'text','projectId'=>'text','type'=>'text','date'=>'text','from'=>'text','to'=>'text','qty'=>'num','returnDate'=>'text','status'=>'text']],
  'repairs' => ['t'=>'repairs','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','assetCode'=>'text','code'=>'text','name'=>'text','sentDate'=>'text','date'=>'text','returnDate'=>'text','status'=>'text','note'=>'text','cost'=>'num','vendor'=>'text']],
  'projects' => ['t'=>'projects','key'=>'id','children'=>[],
      'cols'=>['id'=>'text','name'=>'text','client'=>'text','warehouse'=>'text','budget'=>'num','spent'=>'num','status'=>'text','progress'=>'num','startDate'=>'text','endDate'=>'text','manager'=>'text','lat'=>'num','lng'=>'num','address'=>'text','note'=>'text']],
  'purchaseOrders' => ['t'=>'purchase_orders','key'=>'id','children'=>['items'=>'purchase_order_items'],
      'cols'=>['id'=>'text','supplier'=>'text','date'=>'text','projectId'=>'text','recvWarehouse'=>'text','boqRef'=>'text','reqRef'=>'text','status'=>'text','totalAmount'=>'num']],
  'salesOrders' => ['t'=>'sales_orders','key'=>'id','children'=>['items'=>'sales_order_items','workers'=>'sales_order_workers'],
      'cols'=>['id'=>'text','customer'=>'text','date'=>'text','projectId'=>'text','note'=>'text','status'=>'text','createdAt'=>'text']],
];
$CHILDREN = [
  'item_components'      => ['link'=>'itemCode','cols'=>['code'=>'text','name'=>'text','qty'=>'num','unit'=>'text']],
  'boq_master_items'    => ['link'=>'boqmId','cols'=>['section'=>'text','code'=>'text','name'=>'text','unit'=>'text','qty'=>'num','unitPrice'=>'num']],
  'boq_template_items'  => ['link'=>'boqId','cols'=>['section'=>'text','code'=>'text','name'=>'text','unit'=>'text','qty'=>'num','unitPrice'=>'num','total'=>'num']],
  'bom_components'       => ['link'=>'bomCode','cols'=>['code'=>'text','name'=>'text','qty'=>'num','unit'=>'text']],
  'requisition_items'   => ['link'=>'reqId','cols'=>['code'=>'text','name'=>'text','unit'=>'text','qty'=>'num','unitPrice'=>'num','total'=>'num']],
  'purchase_order_items'=> ['link'=>'poId','cols'=>['code'=>'text','name'=>'text','unit'=>'text','qty'=>'num','unitPrice'=>'num','total'=>'num','received'=>'num']],
  'sales_order_items'   => ['link'=>'soId','cols'=>['code'=>'text','name'=>'text','unit'=>'text','qty'=>'num','qtyDispatched'=>'num','qtyClientRecv'=>'num','qtyClientReturn'=>'num','qtyRecvBack'=>'num']],
  'sales_order_workers' => ['link'=>'soId','cols'=>['name'=>'text','role'=>'text']],
];
$GLOBAL_COLS = [
  'companies' => ['id'=>'text','name'=>'text','taxId'=>'text','address'=>'text','licenseOn'=>'bool'],
  'users'     => ['username'=>'text','password'=>'text','fullName'=>'text','role'=>'text','company'=>'text','pin'=>'text','warehouse'=>'text','scopeProject'=>'text','department'=>'text','position'=>'text'],
];

function empty_partition() { global $PARENTS; $p = []; foreach ($PARENTS as $k=>$_) $p[$k] = []; return $p; }
function safe_id($s) { return preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', (string)$s) ? (string)$s : null; }

/* ---- split a record into typed columns + extra (non-matching values preserved in extra) ---- */
function split_record($rec, $cols, $skip = []) {
    $row = []; $extra = [];
    if (!is_array($rec)) $rec = [];
    foreach ($rec as $k => $v) {
        if (in_array($k, $skip, true)) continue;              // handled elsewhere (child arrays)
        if (!isset($cols[$k])) { $extra[$k] = $v; continue; } // unknown field -> safety net
        $typ = $cols[$k];
        if ($v === null) { $row[$k] = null; continue; }
        if ($typ === 'num')      { if (is_numeric($v)) $row[$k] = $v + 0; else $extra[$k] = $v; }
        elseif ($typ === 'bool') { if (is_bool($v)) $row[$k] = $v; elseif ($v===1||$v==='1'||$v==='true') $row[$k]=true; elseif ($v===0||$v==='0'||$v==='false'||$v==='') $row[$k]=false; else $extra[$k]=$v; }
        else                     { if (is_scalar($v)) $row[$k] = (string)$v; else $extra[$k] = $v; } // text
    }
    $row['extra'] = $extra ? $extra : null;
    return $row;
}
/* ---- rebuild a record object from a DB row (defined non-null cols + extra) ---- */
function rebuild_record($r, $cols, $skip = ['_id','company']) {
    $o = [];
    foreach ($cols as $name => $typ) {
        if (!array_key_exists($name, $r) || $r[$name] === null) continue;
        $v = $r[$name];
        if ($typ === 'num')  $v = $v + 0;
        if ($typ === 'bool') $v = (bool)$v;
        $o[$name] = $v;
    }
    if (isset($r['extra']) && is_array($r['extra'])) foreach ($r['extra'] as $k=>$v) $o[$k] = $v;
    return $o;
}

/* =====================================================================
 *  Supabase REST (PostgREST) + Storage over cURL
 * ===================================================================== */
function sb_req($method, $path, $body = null, $extraHeaders = [], $isStorage = false, $rawBody = null) {
    global $CFG;
    $url = $CFG['url'] . ($isStorage ? '/storage/v1' : '/rest/v1') . $path;
    $key = $CFG['service_key'];
    $headers = ['apikey: ' . $key];
    if (strncmp($key, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $key; // legacy JWT -> also Bearer
    $payload = null;
    if ($rawBody !== null) { $payload = $rawBody; }
    elseif ($body !== null) { $headers[] = 'Content-Type: application/json'; $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    foreach ($extraHeaders as $h) $headers[] = $h;

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_HTTPHEADER=>$headers]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $resp = curl_exec($ch);
    if ($resp === false) { $e = curl_error($ch); curl_close($ch); fail(502, 'Supabase เชื่อมต่อไม่ได้: ' . $e); }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http < 200 || $http >= 300) {
        $msg = 'Supabase error (http ' . $http . ')';
        $j = json_decode($resp, true);
        if (isset($j['message'])) $msg .= ': ' . $j['message'];
        elseif (is_string($resp) && $resp !== '') $msg .= ': ' . substr($resp, 0, 200);
        fail(502, $msg);
    }
    if ($resp === '') return [];
    $j = json_decode($resp, true);
    return ($j === null) ? $resp : $j;
}
function sb_get($path)          { return (array)sb_req('GET', $path); }

/* ---- parallel GET: fetch many tables at once (curl_multi) — big speedup for load ---- */
function sb_get_multi($paths) {
    global $CFG;
    $key = $CFG['service_key'];
    $headers = ['apikey: ' . $key];
    if (strncmp($key, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $key;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($paths as $name => $path) {
        $ch = curl_init($CFG['url'] . '/rest/v1' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_HTTPHEADER=>$headers]);
        curl_multi_add_handle($mh, $ch);
        $handles[$name] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0);
    $out = [];
    foreach ($handles as $name => $ch) {
        $resp = curl_multi_getcontent($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($http >= 200 && $http < 300) { $j = json_decode($resp, true); $out[$name] = is_array($j) ? $j : []; }
        else { $out[$name] = []; }
    }
    curl_multi_close($mh);
    return $out;
}
function sb_delete_all($table)  { sb_req('DELETE', "/$table?_id=gt.0", null, ['Prefer: return=minimal']); }
function sb_insert($table, $rows) {
    if (!$rows) return;
    // PostgREST bulk insert requires EVERY object to have the same key set.
    // Different records legitimately omit different columns, so pad every row
    // to the union of all keys (missing -> null). Same keys, same order.
    $keys = [];
    foreach ($rows as $r) foreach ($r as $k => $_) $keys[$k] = true;
    $keys = array_keys($keys);
    $norm = [];
    foreach ($rows as $r) {
        $o = [];
        foreach ($keys as $k) $o[$k] = array_key_exists($k, $r) ? $r[$k] : null;
        $norm[] = $o;
    }
    foreach (array_chunk($norm, 800) as $chunk)   // chunk to keep each POST reasonable
        sb_req('POST', "/$table", $chunk, ['Prefer: return=minimal']);
}

/* ---- normalize + chunk rows into POST requests (for parallel insert) ---- */
function _insReqs($table, $rows) {
    if (!$rows) return [];
    $keys = [];
    foreach ($rows as $r) foreach ($r as $k => $_) $keys[$k] = true;
    $keys = array_keys($keys);
    $norm = [];
    foreach ($rows as $r) { $o = []; foreach ($keys as $k) $o[$k] = array_key_exists($k, $r) ? $r[$k] : null; $norm[] = $o; }
    $reqs = [];
    foreach (array_chunk($norm, 800) as $chunk) $reqs[] = ['method'=>'POST', 'path'=>'/'.$table, 'json'=>$chunk];
    return $reqs;
}
/* ---- run many write requests in parallel (curl_multi) — big speedup for save ---- */
function sb_multi($reqs) {
    global $CFG;
    if (!$reqs) return;
    $key = $CFG['service_key'];
    $base = ['apikey: ' . $key];
    if (strncmp($key, 'eyJ', 3) === 0) $base[] = 'Authorization: Bearer ' . $key;
    foreach (array_chunk($reqs, 16) as $batch) {
        $mh = curl_multi_init(); $hs = [];
        foreach ($batch as $req) {
            $h = $base; $h[] = 'Prefer: return=minimal';
            $ch = curl_init($CFG['url'] . '/rest/v1' . $req['path']);
            $opt = [CURLOPT_CUSTOMREQUEST=>$req['method'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60];
            if (isset($req['json'])) { $h[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($req['json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
            $opt[CURLOPT_HTTPHEADER] = $h;
            curl_setopt_array($ch, $opt);
            curl_multi_add_handle($mh, $ch); $hs[] = [$ch, $req];
        }
        $running = null; do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0);
        foreach ($hs as $pair) {
            list($ch, $req) = $pair;
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $resp = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            if ($http < 200 || $http >= 300) {
                curl_multi_close($mh);
                $j = json_decode($resp, true);
                fail(502, 'Supabase write error (' . $req['method'] . ' ' . $req['path'] . ' http ' . $http . ')' . (isset($j['message']) ? ': ' . $j['message'] : ''));
            }
        }
        curl_multi_close($mh);
    }
}

/* ---- serialize concurrent requests with an advisory file lock ----
 * The old Apps Script backend used a ScriptLock; without it, two overlapping
 * full-overwrite saves interleave (delete,delete,insert,insert) and DOUBLE every
 * row. 'ex' = exclusive (saves, one at a time); 'sh' = shared (loads, so a read
 * never sees a half-wiped DB). Held for the whole request; auto-released on exit.
 * The static keeps the handle alive so the lock isn't dropped early. */
function save_lock($mode) {
    static $fp = null;
    global $CFG;
    if ($fp) return;
    $path = sys_get_temp_dir() . '/constwms_' . md5($CFG['url'] ?? 'x') . '.lock';
    $fp = @fopen($path, 'c');
    if ($fp === false) { $fp = null; return; }  // best-effort: if unavailable, proceed unlocked
    @flock($fp, $mode === 'ex' ? LOCK_EX : LOCK_SH);
}

/* ---- images: base64 data URI -> Supabase Storage (public URL), GD-downscaled, hash-named ---- */
function storage_put_jpeg($objPath, $bytes) {
    global $CFG;
    sb_req('POST', "/object/{$CFG['bucket']}/$objPath", null, ['Content-Type: image/jpeg', 'x-upsert: true'], true, $bytes);
    return $CFG['url'] . "/storage/v1/object/public/{$CFG['bucket']}/$objPath";
}
function downscale_jpeg($bytes) {
    if (!function_exists('imagecreatefromstring')) return $bytes;
    $im = @imagecreatefromstring($bytes); if ($im === false) return $bytes;
    $w = imagesx($im); $h = imagesy($im); $max = 1000;
    if ($w > $max || $h > $max) { $r = min($max/$w, $max/$h); $nw=(int)round($w*$r); $nh=(int)round($h*$r);
        $d = imagecreatetruecolor($nw,$nh); imagecopyresampled($d,$im,0,0,0,0,$nw,$nh,$w,$h); imagedestroy($im); $im=$d; }
    ob_start(); imagejpeg($im, null, 85); $out = ob_get_clean(); imagedestroy($im);
    return $out ?: $bytes;
}
/* walk any value; base64 image -> uploaded URL (dedup by content hash). $ctx['n'] counts conversions. */
function deep_images(&$v, &$ctx) {
    if (is_string($v)) {
        if (strncmp($v, 'data:image', 10) === 0 && strpos($v, 'base64,') !== false) {
            $b64 = substr($v, strpos($v, 'base64,') + 7);
            $bytes = base64_decode($b64, true);
            if ($bytes !== false && strlen($bytes) > 8) {
                $bytes = downscale_jpeg($bytes);
                $hash = substr(md5($bytes), 0, 24);
                try { $v = storage_put_jpeg("images/auto/img_$hash.jpg", $bytes); $ctx['n']++; }
                catch (Throwable $e) { $ctx['warn'][] = 'อัปโหลดรูปล้มเหลว'; }
            }
        }
        return;
    }
    if (is_array($v)) foreach ($v as $k => &$child) { deep_images($child, $ctx); } // by-ref recurse
}

/* =====================================================================
 *  ACTIONS
 * ===================================================================== */

if ($action === 'ping') {
    ok(['ok'=>true, 'pong'=>true, 'backend'=>'supabase', 'server'=>'CONST-WMS Supabase',
        'time'=>gmdate('c'), 'post_max_size'=>ini_get('post_max_size')]);
}

if ($action === 'version') {
    $m = sb_get('/meta?select=version,lastModified&id=eq.1');
    $row = $m[0] ?? [];
    ok(['ok'=>true, 'version'=>(int)($row['version'] ?? 0), 'lastModified'=>$row['lastModified'] ?? '']);
}

if ($action === 'load' || $action === 'export') {
    save_lock('sh'); // wait out any in-flight save so we never read a half-wiped DB

    // ---- fetch EVERYTHING in ONE parallel batch (fast) ----
    $paths = [
        '_meta'      => '/meta?select=*&id=eq.1',
        '_companies' => '/companies?select=*&order=_id',
        '_users'     => '/users?select=*&order=_id',
        '_settings'  => '/settings?select=company,data',
    ];
    foreach ($CHILDREN as $ct => $_)   $paths['c_' . $ct]   = "/$ct?select=*&order=_id";
    foreach ($PARENTS  as $pk => $P)   $paths['p_' . $pk]   = "/{$P['t']}?select=*&order=_id";
    $R = sb_get_multi($paths);

    $meta = ($R['_meta'][0] ?? []);

    // globals
    $companies = [];
    foreach ($R['_companies'] as $r) $companies[] = rebuild_record($r, $GLOBAL_COLS['companies'], ['_id']);
    $users = [];
    foreach ($R['_users'] as $r) $users[] = rebuild_record($r, $GLOBAL_COLS['users'], ['_id']);

    // settings
    $settings = new stdClass();
    foreach ($R['_settings'] as $r) $settings->{$r['company']} = $r['data'] ?? new stdClass();

    // child rows first: childMap[table][cid][linkVal] = [ records... ]
    $childMap = [];
    foreach ($CHILDREN as $ct => $def) {
        $link = $def['link']; $childMap[$ct] = [];
        foreach (($R['c_' . $ct] ?? []) as $r) {
            $cid = $r['company']; $lv = (string)($r[$link] ?? '');
            $childMap[$ct][$cid][$lv][] = rebuild_record($r, $def['cols'], ['_id','company',$link]);
        }
    }

    // parents -> partitions, attaching children
    $partitions = [];
    foreach ($companies as $co) { $cid = $co['id'] ?? null; if ($cid) $partitions[$cid] = empty_partition(); }
    foreach ($PARENTS as $pkey => $P) {
        foreach (($R['p_' . $pkey] ?? []) as $r) {
            $cid = $r['company'];
            if (!isset($partitions[$cid])) $partitions[$cid] = empty_partition();
            $rec = rebuild_record($r, $P['cols'], ['_id','company']);
            foreach ($P['children'] as $field => $ct) {
                $lv = (string)($rec[$P['key']] ?? '');
                $list = $childMap[$ct][$cid][$lv] ?? [];
                if ($list || $pkey !== 'itemMaster') $rec[$field] = $list;
            }
            $partitions[$cid][$pkey][] = $rec;
        }
    }

    // companies trap: if none persisted but data exists, rebuild from data (pitfalls §2)
    if (!$companies) {
        $ids = array_keys($partitions);
        foreach ((array)$settings as $sid=>$_) if (!in_array($sid, $ids, true)) $ids[] = $sid;
        foreach ($ids as $id) $companies[] = ['id'=>$id, 'name'=>$id];
    }

    ok(['ok'=>true, 'version'=>(int)($meta['version'] ?? 0), 'data'=>[
        'companies'  => $companies,
        'users'      => $users,
        'partitions' => (object)$partitions,
        'settings'   => $settings,
        'txnSeq'   => (int)($meta['txnSeq']   ?? 100),
        'assetSeq' => (int)($meta['assetSeq'] ?? 10),
        'poSeq'    => (int)($meta['poSeq']    ?? 100),
        'boqSeq'   => (int)($meta['boqSeq']   ?? 0),
        'soSeq'    => (int)($meta['soSeq']    ?? 0),
        'version'  => (int)($meta['version']  ?? 0),
        '_savedAt' => (int)($meta['_savedAt'] ?? 0),
    ]]);
}

if ($action === 'save' || $action === 'sync') {
    $d = ($action === 'sync') ? ($body['data'] ?? null) : $body;
    if (!is_array($d) || !isset($d['companies']) || !is_array($d['companies']) || !isset($d['partitions']) || !is_array($d['partitions']))
        fail(400, 'rejected: payload ไม่สมบูรณ์ (ต้องมี companies + partitions)');

    $ctx = ['n'=>0, 'warn'=>[]];
    // move base64 images -> Storage (in partitions + settings) before splitting
    if (isset($d['partitions'])) deep_images($d['partitions'], $ctx);
    if (isset($d['settings']))   deep_images($d['settings'],   $ctx);

    /* ---- serialize saves: prevents concurrent delete/insert races (duplicate rows) ---- */
    save_lock('ex');

    /* ---- build all row-sets (written in parallel below) ---- */
    $coRows = [];
    foreach ((array)$d['companies'] as $co) { if (!is_array($co)) continue; $coRows[] = split_record($co, $GLOBAL_COLS['companies']); }
    $uRows = [];
    foreach ((array)($d['users'] ?? []) as $u) { if (!is_array($u)) continue; $uRows[] = split_record($u, $GLOBAL_COLS['users']); }
    $setRows = [];
    foreach ((array)($d['settings'] ?? []) as $cid => $blob) { $cid = safe_id($cid); if (!$cid) continue; $setRows[] = ['company'=>$cid, 'data'=>$blob ?: new stdClass()]; }

    /* ---- domain tables: full wipe then rewrite from ALL partitions ---- */
    $parentRows = []; foreach ($PARENTS as $pk=>$P) $parentRows[$P['t']] = [];
    $childRows  = []; foreach ($CHILDREN as $ct=>$_) $childRows[$ct] = [];

    foreach ((array)$d['partitions'] as $cid => $tables) {
        $cid = safe_id($cid); if (!$cid || !is_array($tables)) continue;
        foreach ($PARENTS as $pkey => $P) {
            $arr = (isset($tables[$pkey]) && is_array($tables[$pkey])) ? $tables[$pkey] : [];
            $childFields = array_keys($P['children']);
            foreach ($arr as $rec) {
                if (!is_array($rec)) continue;
                $row = split_record($rec, $P['cols'], $childFields);
                $row['company'] = $cid;
                $parentRows[$P['t']][] = $row;
                // split nested arrays -> child tables, linked by parent key value
                $linkVal = isset($rec[$P['key']]) ? (string)$rec[$P['key']] : '';
                foreach ($P['children'] as $field => $ct) {
                    if (empty($rec[$field]) || !is_array($rec[$field])) continue;
                    $link = $CHILDREN[$ct]['link'];
                    foreach ($rec[$field] as $c) {
                        if (!is_array($c)) continue;
                        $crow = split_record($c, $CHILDREN[$ct]['cols']);
                        $crow['company'] = $cid; $crow[$link] = $linkVal;
                        $childRows[$ct][] = $crow;
                    }
                }
            }
        }
    }
    // ---- wipe ALL tables in parallel, then insert ALL in parallel ----
    $delReqs = [
        ['method'=>'DELETE', 'path'=>'/companies?_id=gt.0'],
        ['method'=>'DELETE', 'path'=>'/users?_id=gt.0'],
        ['method'=>'DELETE', 'path'=>'/settings?company=not.is.null'],
    ];
    foreach ($PARENTS  as $P)       $delReqs[] = ['method'=>'DELETE', 'path'=>'/'.$P['t'].'?_id=gt.0'];
    foreach ($CHILDREN as $ct => $_) $delReqs[] = ['method'=>'DELETE', 'path'=>'/'.$ct.'?_id=gt.0'];
    sb_multi($delReqs);

    $insReqs = array_merge(_insReqs('companies', $coRows), _insReqs('users', $uRows), _insReqs('settings', $setRows));
    foreach ($parentRows as $t => $rows) $insReqs = array_merge($insReqs, _insReqs($t, $rows));
    foreach ($childRows  as $t => $rows) $insReqs = array_merge($insReqs, _insReqs($t, $rows));
    sb_multi($insReqs);

    /* ---- meta: update seqs + _savedAt (version was already bumped by triggers) ---- */
    sb_req('PATCH', '/meta?id=eq.1', [
        'txnSeq'   => (int)($d['txnSeq']   ?? 100),
        'assetSeq' => (int)($d['assetSeq'] ?? 10),
        'poSeq'    => (int)($d['poSeq']    ?? 100),
        'boqSeq'   => (int)($d['boqSeq']   ?? 0),
        'soSeq'    => (int)($d['soSeq']    ?? 0),
        '_savedAt' => (int)($d['_savedAt'] ?? (int)(microtime(true)*1000)),
        '_savedBy' => isset($d['_savedBy']) ? (string)$d['_savedBy'] : 'web',
    ], ['Prefer: return=minimal']);

    $m = sb_get('/meta?select=version&id=eq.1');
    ok(['ok'=>true, 'version'=>(int)($m[0]['version'] ?? 0), 'converted'=>$ctx['n'], 'warnings'=>$ctx['warn'], 'ts'=>gmdate('c')]);
}

/* ---- uploadimg (mobile): {cid, code, data} -> images/<cid>/<code>.jpg ---- */
if ($action === 'uploadimg') {
    $cid  = safe_id($body['cid'] ?? ($body['company'] ?? 'global')) ?: 'global';
    $code = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($body['code'] ?? ''));
    $data = (string)($body['data'] ?? '');
    if ($code === '' || strlen($code) > 60) fail(400, 'invalid code');
    if (strncmp($data, 'data:image', 10) !== 0) fail(400, 'invalid image data');
    $b64 = substr($data, strpos($data, 'base64,') + 7);
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 8) fail(400, 'invalid base64 image');
    if (strlen($bytes) > 8*1024*1024) fail(413, 'image too large (max 8MB)');
    $url = storage_put_jpeg("images/$cid/$code.jpg", downscale_jpeg($bytes));
    ok(['ok'=>true, 'url'=>$url . '?v=' . time()]);
}

/* ---- uploadfile (web): {name, data(dataURI|base64), folder} -> files/<folder>/<name> ---- */
if ($action === 'uploadfile') {
    global $CFG;
    $name = preg_replace('/[^A-Za-z0-9ก-๙ _\-.]/u', '_', (string)($body['name'] ?? 'file'));
    if ($name === '') $name = 'file';
    $raw = (string)($body['data'] ?? '');
    $mime = 'application/octet-stream';
    if (preg_match('#^data:([^;]+);base64,(.*)$#s', $raw, $mm)) { $mime = $mm[1]; $raw = $mm[2]; }
    elseif (strpos($raw, 'base64,') !== false) { $raw = substr($raw, strpos($raw, 'base64,') + 7); }
    $bytes = base64_decode($raw, true);
    if ($bytes === false || strlen($bytes) < 1) fail(400, 'invalid file data');
    if (strlen($bytes) > 25*1024*1024) fail(413, 'file too large (max 25MB)');

    $folder = trim(preg_replace('#[^A-Za-z0-9ก-๙_\-./]#u', '_', (string)($body['folder'] ?? '')), '/');
    $isImg = (strncmp($mime, 'image/', 6) === 0);
    if ($isImg) { $bytes = downscale_jpeg($bytes); if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '') $name .= '.jpg'; $mime = 'image/jpeg'; }
    $objPath = 'files/' . ($folder ? $folder . '/' : '') . $name;
    sb_req('POST', "/object/{$CFG['bucket']}/$objPath", null, ['Content-Type: ' . $mime, 'x-upsert: true'], true, $bytes);
    $url = $CFG['url'] . "/storage/v1/object/public/{$CFG['bucket']}/$objPath";
    ok(['ok'=>true, 'id'=>$objPath, 'name'=>$name, 'url'=>$url, 'webUrl'=>$url]);
}

if ($action === 'delimg') { ok(['ok'=>true]); }   // unreferenced objects may stay (harmless); no-op like the old backend

fail(400, 'Unknown action: ' . $action);
