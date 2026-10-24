<?php
/**
 * CM AI Docking Port — Reception (Korak 3.3.1: fallback-only forward)
 * POST /app/public/ai/reception.php
 *
 * Agent govori samo sem. Interni availability_multi.php in nodes.json
 * ostaneta skrita. Forward na sosede je fallback-only (Spec §16.2/§21/§22):
 * samo če lokalni rezultat ni "true", brez write operacij.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

$NODE_ID = 'CM-AI-DEMO-058';
$PROTOCOL = 'CM-AI-Docking-Port';
$VERSION = '0.1';
$INTERNAL_API = 'http://127.0.0.1/app/public/api/availability_multi.php';
$INTERNAL_HOST = 'ai-cmfree.duckdns.org';
$NODE_LAT = 46.34;
$NODE_LON = 14.17;
$LIMITS_DEFAULT_MAX_DISTANCE_KM = 50;
$LIMITS_DEFAULT_MAX_DEPTH = 2;
$LIMITS_DEFAULT_MAX_RESULTS = 20;
$LIMITS_DEFAULT_MAX_TTL_SECONDS = 60; // Spec §5, SKLENJENO 2026-10-04 (glej spodaj)

/**
 * Korak 3.1: varno branje nodes.json (izven docroot, glej Spec §16.1).
 * $nodes_cfg se v tem koraku NE uporablja za forwarding - Neighbours smejo
 * biti []. Fail-safe na vsaki stopnji pade nazaj na Korak-2 obnašanje
 * (ok:true, depth:0, lokalno) - agent nikoli ne vidi konfiguracijske napake,
 * razlog gre samo v error_log().
 */
// Deploy-specific: set this to a path outside the web root on your own
// server. The example below is illustrative, not this repo's own layout.
$NODES_PATH = '/home/YOUR_USER/cm-private/ai/nodes.json';
$forwarded_to_log = []; // populated just before a real forward, read by log_visit()

function load_nodes_config($path, $expected_node_id) {
    if (!is_readable($path)) {
        error_log("CM-AI Reception: nodes.json not readable: {$path}");
        return [
            'ok' => false,
            'max_fanout' => 2,
            'neighbours' => [],
            'reason' => 'missing_or_unreadable',
        ];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error_log("CM-AI Reception: nodes.json invalid JSON: {$path}");
        return [
            'ok' => false,
            'max_fanout' => 2,
            'neighbours' => [],
            'reason' => 'invalid_json',
        ];
    }
    $file_id = isset($data['node_id']) ? (string) $data['node_id'] : '';
    if ($file_id !== $expected_node_id) {
        error_log("CM-AI Reception: nodes.json node_id mismatch: got '{$file_id}', expected '{$expected_node_id}'");
        return [
            'ok' => false,
            'max_fanout' => 2,
            'neighbours' => [],
            'reason' => 'node_id_mismatch',
        ];
    }
    $fanout = 2;
    if (isset($data['max_fanout']) && is_numeric($data['max_fanout'])) {
        $fanout = max(1, (int) $data['max_fanout']);
    }
    $neighbours = [];
    if (isset($data['neighbours']) && is_array($data['neighbours'])) {
        $neighbours = $data['neighbours'];
    }
    return [
        'ok' => true,
        'max_fanout' => $fanout,
        'neighbours' => $neighbours,
        'reason' => 'ok',
    ];
}

$nodes_cfg = load_nodes_config($NODES_PATH, $NODE_ID);

/**
 * Korak 3.2: geografski filter PRED lokalnim klicem (Spec §16.2,
 * effective_distance_km SKLENJENO). Brez forwardinga - $nodes_cfg se
 * tukaj ne uporablja za HTTP klice.
 */
function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $R = 6371.0;
    $p = M_PI / 180;
    $dlat = ($lat2 - $lat1) * $p;
    $dlon = ($lon2 - $lon1) * $p;
    $a = sin($dlat / 2) ** 2
       + cos($lat1 * $p) * cos($lat2 * $p) * sin($dlon / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

function effective_distance_km($request, $default_km) {
    $radius = null;
    $maxd = null;
    if (isset($request['target']['radius_km']) && is_numeric($request['target']['radius_km'])) {
        $radius = (float) $request['target']['radius_km'];
    }
    if (isset($request['limits']['max_distance_km']) && is_numeric($request['limits']['max_distance_km'])) {
        $maxd = (float) $request['limits']['max_distance_km'];
    }
    if ($radius !== null && $maxd !== null) {
        return min($radius, $maxd);
    }
    if ($radius !== null) {
        return $radius;
    }
    if ($maxd !== null) {
        return $maxd;
    }
    return (float) $default_km;
}

function target_coords($request) {
    if (!isset($request['target']['location']) || !is_array($request['target']['location'])) {
        return null;
    }
    $loc = $request['target']['location'];
    if (!isset($loc['lat'], $loc['lon']) || !is_numeric($loc['lat']) || !is_numeric($loc['lon'])) {
        return null;
    }
    return [(float) $loc['lat'], (float) $loc['lon']];
}

/**
 * Korak 3.3.1: fallback-only forward na sosede iz nodes.json (Spec §21/22).
 * Agent nikoli ne vidi LAN URL-jev, IP-jev ali curl napak - samo error_log().
 */
function entry_ceiling_for($request, $ceiling) {
    if (isset($request['limits']['max_depth']) && is_numeric($request['limits']['max_depth'])) {
        return min((int) $request['limits']['max_depth'], $ceiling);
    }
    return $ceiling;
}

function max_results_limit_for($request, $default) {
    if (isset($request['limits']['max_results']) && is_numeric($request['limits']['max_results'])) {
        return max(1, (int) $request['limits']['max_results']);
    }
    return $default;
}

function select_neighbours($nodes_cfg, $visited, $own_node_id, $target_lat, $target_lon, $eff_distance_km) {
    $candidates = [];
    $neighbours = (isset($nodes_cfg['neighbours']) && is_array($nodes_cfg['neighbours'])) ? $nodes_cfg['neighbours'] : [];
    foreach ($neighbours as $n) {
        if (!is_array($n) || !isset($n['node_id']) || !is_string($n['node_id'])) {
            continue;
        }
        if ($n['node_id'] === $own_node_id || in_array($n['node_id'], $visited, true)) {
            continue;
        }
        if (!isset($n['location']['lat'], $n['location']['lon'])
            || !is_numeric($n['location']['lat']) || !is_numeric($n['location']['lon'])) {
            continue;
        }
        if (!isset($n['reception_url']) || !is_string($n['reception_url'])
            || !preg_match('#^https?://#i', $n['reception_url'])) {
            continue;
        }
        $dist = haversine_km((float) $n['location']['lat'], (float) $n['location']['lon'], $target_lat, $target_lon);
        if ($dist > $eff_distance_km) {
            continue;
        }
        $candidates[] = ['node' => $n, 'dist' => $dist];
    }
    usort($candidates, function ($a, $b) {
        return $a['dist'] <=> $b['dist'];
    });
    $max_fanout = 2;
    if (isset($nodes_cfg['max_fanout']) && is_numeric($nodes_cfg['max_fanout'])) {
        $max_fanout = max(1, (int) $nodes_cfg['max_fanout']);
    }
    return array_slice($candidates, 0, $max_fanout);
}

function forward_to_neighbours($selected, $body_json) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($selected as $i => $c) {
        $ch = curl_init($c['node']['reception_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body_json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'node_id' => $c['node']['node_id']];
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh);
        }
    } while ($running > 0);
    $out = [];
    foreach ($handles as $h) {
        $body = curl_multi_getcontent($h['ch']);
        $code = (int) curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        $err = curl_error($h['ch']);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
        $out[] = ['node_id' => $h['node_id'], 'http_code' => $code, 'body' => $body, 'curl_error' => $err];
    }
    curl_multi_close($mh);
    return $out;
}

function parse_child_envelope($raw, $expected_protocol) {
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        return null;
    }
    if (($d['protocol'] ?? null) !== $expected_protocol) {
        return null;
    }
    if (!isset($d['results']) || !is_array($d['results'])) {
        return null;
    }
    return $d;
}

/**
 * Korak 7/8 (Spec §23, SKLENJENO): sortiranje po razdalji do target uporabi
 * node_location, ki potuje z vsakim vnosom samim - nikoli iskanje v lastnem
 * nodes.json (ta pozna samo neposredne sosede, ne globljih hopov).
 */
function sort_by_distance_to_target($results, $target_lat, $target_lon) {
    usort($results, function ($a, $b) use ($target_lat, $target_lon) {
        $da = (isset($a['node_location']['lat'], $a['node_location']['lon']))
            ? haversine_km((float) $a['node_location']['lat'], (float) $a['node_location']['lon'], $target_lat, $target_lon)
            : INF;
        $db = (isset($b['node_location']['lat'], $b['node_location']['lon']))
            ? haversine_km((float) $b['node_location']['lat'], (float) $b['node_location']['lon'], $target_lat, $target_lon)
            : INF;
        return $da <=> $db;
    });
    return $results;
}

/**
 * Korak 7/8: checked[] dedup po node_id, obdrži vnos z manjšo globino
 * (Gemini, SKLENJENO §23, priprava za gostejše topologije).
 */
function dedup_checked_by_min_depth($checked) {
    $by_id = [];
    foreach ($checked as $c) {
        if (!is_array($c) || !isset($c['node_id']) || !is_string($c['node_id'])) {
            continue;
        }
        $id = $c['node_id'];
        $depth = (isset($c['depth']) && is_numeric($c['depth'])) ? (int) $c['depth'] : 0;
        if (!isset($by_id[$id]) || $depth < $by_id[$id]['depth']) {
            $c['depth'] = $depth;
            $by_id[$id] = $c;
        }
    }
    return array_values($by_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Spec §40 (GET vrne primer ovojnice, SKLENJENO panel-konsenz 2026-10-04:
 * Grega/DeepSeek/GPT/Gemini/Grok): generirano iz TEH ISTIH node-ovih
 * konstant, ki jih uporablja POST pot - ne ločena, ročno vzdrževana kopija,
 * ne more zaiti iz sinhronizacije. request_expiry je vedno svež (trenutni
 * čas + lasten TTL), nikoli statičen datum.
 */
function sample_request_envelope($protocol, $version, $node_lat, $node_lon, $node_label, $default_depth, $default_distance_km, $default_results, $ttl_seconds) {
    return [
        'protocol' => $protocol,
        'version' => $version,
        'request_id' => 'req-example-' . time(),
        'request_expiry' => gmdate('c', time() + $ttl_seconds),
        'origin' => 'ai-agent',
        'target' => [
            'location' => ['lat' => $node_lat, 'lon' => $node_lon, 'label' => $node_label],
            'radius_km' => $default_distance_km,
        ],
        'query' => [
            'type' => 'availability',
            'from' => gmdate('Y-m-d', strtotime('+7 days')),
            'to' => gmdate('Y-m-d', strtotime('+9 days')),
            'guests' => 2,
            'units' => null,
        ],
        'limits' => [
            'max_depth' => $default_depth,
            'max_distance_km' => $default_distance_km,
            'max_results' => $default_results,
        ],
        'visited' => [],
    ];
}

function envelope($node_id, $protocol, $version, $request, $overrides) {
    $request_id = 'req-unknown';
    if (is_array($request) && !empty($request['request_id']) && is_string($request['request_id'])) {
        $request_id = $request['request_id'];
    }
    $target = null;
    if (is_array($request) && isset($request['target']['location']) && is_array($request['target']['location'])) {
        $target = $request['target']['location'];
    }
    // 2026-10-04 (Spec §30/§35, SKLENJENO): preimenovano iz max_depth_limit -
    // ime ni opisalo, čigav strop je (vsak node ima SVOJ ceiling, ne globalno
    // mejo verige - §30 dokazal max_depth_used:3 hkrati z starim
    // max_depth_limit:2 na istem odgovoru, kar je zavajalo agenta). Staro ime
    // ne živi vzporedno - glej §36.
    $entry_ceiling = is_array($request) ? entry_ceiling_for($request, 2) : 2;
    $base = [
        'protocol' => $protocol,
        'version' => $version,
        'request_id' => $request_id,
        'ok' => false,
        'partial' => false,
        'search_meta' => [
            'entry_node' => $node_id,
            'target_location' => $target,
            'nodes_queried' => 0,
            'nodes_reached' => 0,
            'max_depth_used' => 0,
            'entry_ceiling' => $entry_ceiling,
        ],
        'results' => [],
        'checked' => [],
        'errors' => [],
    ];
    foreach ($overrides as $k => $v) {
        if ($k === 'search_meta' && is_array($v)) {
            $base['search_meta'] = array_merge($base['search_meta'], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

/**
 * Privaten dnevnik obiskov (ni javno dostopen, izven docroot - glej Spec §18).
 * Namenoma brez IP naslova - zanima nas samo ali/kdo (user agent) dejansko
 * kliče Reception, ne identiteta obiskovalca.
 *
 * 2026-10-04: razširjeno za monitor dashboard (admin/ai_docking_monitor.php -
 * gostitelju/auditorju omogoči VIDETI promet, ne samo zaupati besedi). Novi
 * stolpci ostanejo znotraj istih privatnostnih mej kot prej: from_node in
 * forwarded_to sta node_id-ji (del protokola samega, ne osebni podatki),
 * location/from/to so iskalni parametri (ne gostovi/cene - tiste vrne šele
 * $INTERNAL_API in jih ta dnevnik nikoli ne vidi).
 */
function log_visit($code, $payload) {
    global $request, $forwarded_to_log, $NODE_ID;

    $from_node = 'external';
    if (isset($request['visited']) && is_array($request['visited']) && !empty($request['visited'])) {
        $last = end($request['visited']);
        if (is_string($last) && $last !== '') {
            $from_node = $last;
        }
    }

    $location = null;
    if (isset($request['target']['location']) && is_array($request['target']['location'])) {
        $loc = $request['target']['location'];
        if (isset($loc['lat'], $loc['lon']) && is_numeric($loc['lat']) && is_numeric($loc['lon'])) {
            $location = ['lat' => (float) $loc['lat'], 'lon' => (float) $loc['lon']];
        }
    }

    // 2026-10-04 (Spec §36, GPT-jev rob): optional target.location.label -
    // že dokumentirano v public/ai/index.html primeru (label ob lat/lon v
    // target_location), samo doslej nikjer dejansko prebrano. Agent ga sme
    // poslati za čitljivejši monitor; Reception ga nikoli ne izmišlja (brez
    // reverse-geocodinga), samo prepiše naprej, če obstaja.
    $target_label = null;
    if (isset($request['target']['location']['label']) && is_string($request['target']['location']['label']) && $request['target']['location']['label'] !== '') {
        $target_label = $request['target']['location']['label'];
    }

    // 2026-10-04 (Spec §36): provenienca iz dejanske ovojnice - šteje se
    // PONUDBE (results[] vnosi), ne klicane node-e, brez dvojnega štetja.
    // local_hit = je TA node sam prispeval rezultat na globini 0 (edini
    // primer, ko je to mogoče - glej $local_true v glavnem toku zgoraj).
    $results = (isset($payload['results']) && is_array($payload['results'])) ? $payload['results'] : [];
    $result_sources = [];
    $local_hit = false;
    foreach ($results as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rid = (isset($r['node_id']) && is_string($r['node_id'])) ? $r['node_id'] : null;
        $rdepth = (isset($r['depth']) && is_numeric($r['depth'])) ? (int) $r['depth'] : null;
        $result_sources[] = ['node_id' => $rid, 'depth' => $rdepth];
        if ($rdepth === 0 && $rid === $NODE_ID) {
            $local_hit = true;
        }
    }
    $remote_hits = count($results) - ($local_hit ? 1 : 0);

    $entry = [
        'ts' => gmdate('c'),
        'http_code' => $code,
        'ok' => $payload['ok'] ?? null,
        'query_type' => (isset($request) && is_array($request) && isset($request['query']['type'])) ? $request['query']['type'] : null,
        'error_code' => !empty($payload['errors']) ? $payload['errors'][0]['code'] : null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'from_node' => $from_node,
        'location' => $location,
        'target_label' => $target_label,
        'from' => (isset($request['query']['from']) && is_string($request['query']['from'])) ? $request['query']['from'] : null,
        'to' => (isset($request['query']['to']) && is_string($request['query']['to'])) ? $request['query']['to'] : null,
        'results_count' => count($results),
        'checked_count' => (isset($payload['checked']) && is_array($payload['checked'])) ? count($payload['checked']) : 0,
        'forwarded_to' => is_array($forwarded_to_log ?? null) ? $forwarded_to_log : [],
        'local_hit' => $local_hit,
        'remote_hits' => $remote_hits,
        'result_sources' => $result_sources,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    // Same deploy-specific path convention as $NODES_PATH above.
    @file_put_contents('/home/YOUR_USER/cm-private/ai-logs/visits.jsonl', $line, FILE_APPEND | LOCK_EX);
}

function send($code, $payload) {
    log_visit($code, $payload);
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send(405, envelope($NODE_ID, $PROTOCOL, $VERSION, null, [
        'errors' => [['code' => 'method_not_allowed', 'message' => 'Reception accepts POST only. See sample_request for a usable Request Envelope.']],
        'sample_request' => sample_request_envelope(
            $PROTOCOL, $VERSION, $NODE_LAT, $NODE_LON, 'Lancovo / Radovljica area (Demo 58)',
            $LIMITS_DEFAULT_MAX_DEPTH, $LIMITS_DEFAULT_MAX_DISTANCE_KM, $LIMITS_DEFAULT_MAX_RESULTS, $LIMITS_DEFAULT_MAX_TTL_SECONDS
        ),
    ]));
}

$raw = file_get_contents('php://input');
$request = json_decode($raw, true);

if (!is_array($request) || ($request['protocol'] ?? null) !== $PROTOCOL) {
    send(400, envelope($NODE_ID, $PROTOCOL, $VERSION, is_array($request) ? $request : null, [
        'errors' => [['code' => 'invalid_envelope', 'message' => 'Invalid or missing Request Envelope.']],
    ]));
}

// Spec §9: request_expiry je obvezno polje na vsakem zahtevku.
if (!isset($request['request_expiry']) || $request['request_expiry'] === '') {
    send(400, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'errors' => [['code' => 'missing_request_expiry', 'message' => 'request_expiry is required on every Request Envelope.']],
    ]));
}

// Spec §5/§36, SKLENJENO 2026-10-04 (Grega/DeepSeek/GPT/Claude soglasje):
// 1) absoluten UTC čas (RFC3339/ISO8601 - isto kot ostali časovni žigi tega
//    protokola, npr. gmdate('c'));
// 2) preverjeno na VHODU vsakega hopa - potečen ali neveljaven zahtevek se
//    zavrne takoj, brez lokalnega klica ali forwarda;
// 3) med izvajanjem se NE prekinja - gate je samo tukaj, na vhodu. Če je
//    zahtevek ob prejemu veljaven, ta hop (lokalno + morebitni forward) gre
//    do konca. To pomeni, da naslednji hop preprosto nikoli ne začne, če je
//    TTL do takrat že mimo - to je namen, ne napaka;
// 4) ločeno od CURLOPT_TIMEOUT (budget ENEGA klica) - expiry je življenje
//    CELE verige iskanja.
$request_expiry_ts = strtotime((string) $request['request_expiry']);
if ($request_expiry_ts === false) {
    send(400, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'errors' => [['code' => 'invalid_request_expiry', 'message' => 'request_expiry must be an absolute UTC timestamp (RFC3339).']],
    ]));
}
if ($request_expiry_ts <= time()) {
    send(400, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'errors' => [['code' => 'request_expired', 'message' => 'request_expiry has already passed.']],
    ]));
}

// Agent ne sme usmerjati - visited piše samo Reception.
// Korak 3.4.0 (Grok popravek, Spec §23): count(visited) proti entry_ceiling
// se preveri na PRIHAJAJOČEM visited (brez sebe) - sebe dodamo šele v izhodni
// envelope. Posledica: entry_ceiling zdaj pomeni natanko toliko hopov,
// kolikor je dovoljenih (prej je bilo potrebno +1, ker se je self dodal pred
// preverbo). Ne vpliva na nobenega od 12 testov §22 (razlika se pokaže šele
// na drugem hopu, ki ga noben od njih ni dosegel).
$visited_incoming = [];
if (isset($request['visited']) && is_array($request['visited'])) {
    foreach ($request['visited'] as $v) {
        if (is_string($v) && !in_array($v, $visited_incoming, true)) {
            $visited_incoming[] = $v;
        }
    }
}
$visited_outgoing = $visited_incoming;
if (!in_array($NODE_ID, $visited_outgoing, true)) {
    $visited_outgoing[] = $NODE_ID;
}

$query = (isset($request['query']) && is_array($request['query'])) ? $request['query'] : [];
$type = $query['type'] ?? null;

if ($type !== 'availability') {
    send(200, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'errors' => [['code' => 'unsupported_query_type', 'message' => 'MVP allows query.type "availability" only.']],
    ]));
}

$from = isset($query['from']) ? trim((string) $query['from']) : '';
$to   = isset($query['to'])   ? trim((string) $query['to'])   : '';
if ($from === '' || $to === '') {
    send(200, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'errors' => [['code' => 'missing_dates', 'message' => 'query.from and query.to are required.']],
    ]));
}

$coords = target_coords($request);
$eff = effective_distance_km($request, $LIMITS_DEFAULT_MAX_DISTANCE_KM);
$local_ok = false;

if ($coords === null) {
    error_log("CM-AI Reception: local lookup skipped (missing target.location lat/lon)");
} else {
    $dist = haversine_km($NODE_LAT, $NODE_LON, $coords[0], $coords[1]);
    if ($dist <= $eff) {
        $local_ok = true;
    } else {
        error_log("CM-AI Reception: local lookup skipped (distance={$dist} km, effective={$eff} km)");
    }
}

// Korak 3.3.1: lokalni klic se poskusi kot prej (3.2), a rezultat se vrne
// takoj SAMO, če je "true" (ok && available_count>0). Sicer se obravnava
// enako kot "lokalno preskočeno" in se nadaljuje na forward-ali-prazno.
$local_attempted = false;
$local_reached = false;
$local_true = false;
$local_result_entry = null;
$local_checked_entry = null;

if ($local_ok) {
    $local_attempted = true;

    $units_param = 'all';
    if (isset($query['units']) && $query['units'] !== null) {
        if (is_array($query['units'])) {
            $units_param = implode(',', $query['units']);
        } else {
            $units_param = (string) $query['units'];
        }
        if (trim($units_param) === '') {
            $units_param = 'all';
        }
    }

    $qs = http_build_query([
        'from' => $from,
        'to' => $to,
        'units' => $units_param,
        'mode' => 'basic',
    ]);
    $api_url = $INTERNAL_API . '?' . $qs;

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Host: ' . $INTERNAL_HOST, 'Accept: application/json'],
    ]);
    $api_body = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($api_body !== false && $http_code === 200) {
        $data = json_decode($api_body, true);
        if (is_array($data)) {
            $local_reached = true;
            $available_count = isset($data['available_count']) ? (int) $data['available_count'] : 0;
            if (($data['ok'] ?? false) === true && $available_count > 0) {
                $local_true = true;
                $local_result_entry = [
                    'node_id' => $NODE_ID,
                    'node_location' => ['lat' => $NODE_LAT, 'lon' => $NODE_LON],
                    'depth' => 0,
                    'source' => 'local_availability_api',
                    'freshness' => [
                        'generated_at' => isset($data['generated_at']) ? $data['generated_at'] : gmdate('c'),
                    ],
                    'data' => $data,
                ];
            } elseif (($data['ok'] ?? false) === true) {
                // Korak 7/8: uspešno kontaktiran, potrjena ničla - gre v checked[],
                // ne v results[] (Spec §23 SKLENJENO). Minimalna shema (Gemini).
                $local_checked_entry = [
                    'node_id' => $NODE_ID,
                    'depth' => 0,
                    'location' => ['lat' => $NODE_LAT, 'lon' => $NODE_LON],
                ];
                error_log("CM-AI Reception: local call ok but not a true result (available_count={$available_count}) - considering forward");
            } else {
                error_log('CM-AI Reception: local availability call reported ok:false - not a confirmed zero, considering forward');
            }
        } else {
            error_log('CM-AI Reception: local availability call returned invalid payload');
        }
    } else {
        if ($curl_err !== '') {
            error_log('CM-AI Reception: local availability call failed: ' . $curl_err);
        }
    }
}

if ($local_true) {
    send(200, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'ok' => true,
        'search_meta' => ['nodes_queried' => 1, 'nodes_reached' => 1, 'max_depth_used' => 0],
        'results' => [$local_result_entry],
    ]));
}

// --- ni local_true: fallback-only forward na sosede, ali prazen uspeh ---

$entry_ceiling = entry_ceiling_for($request, $LIMITS_DEFAULT_MAX_DEPTH);

$sme_forward = ($nodes_cfg['ok'] === true)
    && ($coords !== null)
    && (count($visited_incoming) < $entry_ceiling);

$selected = $sme_forward
    ? select_neighbours($nodes_cfg, $visited_outgoing, $NODE_ID, $coords[0], $coords[1], $eff)
    : [];

$agg_checked = ($local_checked_entry !== null) ? [$local_checked_entry] : [];

if (empty($selected)) {
    send(200, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
        'ok' => true,
        'partial' => false,
        'search_meta' => [
            'nodes_queried' => $local_attempted ? 1 : 0,
            'nodes_reached' => $local_reached ? 1 : 0,
            'max_depth_used' => 0,
        ],
        'results' => [],
        'checked' => $agg_checked,
        'errors' => [],
    ]));
}

// Spec §5/§36 točka 4: agentov request_expiry se ob vsakem forwardu stisne
// navzdol na lasten ceiling (isti vzorec kot entry_ceiling/max_depth) -
// nikoli se ne podaljša, četudi je agent prvotno zahteval več.
$own_expiry_ceiling_ts = time() + $LIMITS_DEFAULT_MAX_TTL_SECONDS;
$outgoing_expiry_ts = min($request_expiry_ts, $own_expiry_ceiling_ts);

$forward_body = $request;
$forward_body['visited'] = $visited_outgoing;
$forward_body['request_expiry'] = gmdate('c', $outgoing_expiry_ts);
$forward_json = json_encode($forward_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$forwarded_to_log = array_map(function ($c) {
    return $c['node']['node_id'];
}, $selected);

$responses = forward_to_neighbours($selected, $forward_json);

$nodes_queried = $local_attempted ? 1 : 0;
$nodes_reached = $local_reached ? 1 : 0;
$max_depth_used = 0;
$partial = false;
$agg_results = [];

foreach ($responses as $r) {
    $child = ($r['http_code'] === 200) ? parse_child_envelope($r['body'], $PROTOCOL) : null;
    if ($child === null) {
        $partial = true;
        $nodes_queried += 1;
        if ($r['curl_error'] !== '') {
            error_log("CM-AI Reception: forward to {$r['node_id']} failed: {$r['curl_error']}");
        } else {
            error_log("CM-AI Reception: forward to {$r['node_id']} returned invalid response (http {$r['http_code']})");
        }
        continue;
    }

    $child_nq = (isset($child['search_meta']['nodes_queried']) && is_numeric($child['search_meta']['nodes_queried']))
        ? (int) $child['search_meta']['nodes_queried'] : 1;
    $child_nr = (isset($child['search_meta']['nodes_reached']) && is_numeric($child['search_meta']['nodes_reached']))
        ? (int) $child['search_meta']['nodes_reached'] : 1;
    $child_mdu = (isset($child['search_meta']['max_depth_used']) && is_numeric($child['search_meta']['max_depth_used']))
        ? (int) $child['search_meta']['max_depth_used'] : 0;

    $nodes_queried += $child_nq;
    $nodes_reached += $child_nr;
    $max_depth_used = max($max_depth_used, 1 + $child_mdu);

    if (!empty($child['partial'])) {
        $partial = true;
    }

    foreach ($child['results'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entry_depth = (isset($entry['depth']) && is_numeric($entry['depth'])) ? (int) $entry['depth'] : 0;
        $entry['depth'] = 1 + $entry_depth;
        $max_depth_used = max($max_depth_used, $entry['depth']);
        $agg_results[] = $entry;
    }

    // Korak 7/8: otrokov checked[] (če ga ima - D kot čist list ga morda nima,
    // manjkajoč ključ je enakovreden prazni listi) se prišteje z globino +1,
    // enako kot results[]. Node-i, ki so spodletel/timeout (že obravnavani
    // zgoraj kot $partial=true, continue), nikoli ne pridejo do te veje.
    $child_checked = (isset($child['checked']) && is_array($child['checked'])) ? $child['checked'] : [];
    foreach ($child_checked as $c) {
        if (!is_array($c) || !isset($c['node_id'])) {
            continue;
        }
        $c_depth = (isset($c['depth']) && is_numeric($c['depth'])) ? (int) $c['depth'] : 0;
        $c['depth'] = 1 + $c_depth;
        $agg_checked[] = $c;
    }
}

$agg_results = sort_by_distance_to_target($agg_results, $coords[0], $coords[1]);
$max_results = max_results_limit_for($request, $LIMITS_DEFAULT_MAX_RESULTS);
$agg_results = array_slice($agg_results, 0, $max_results);
$agg_checked = dedup_checked_by_min_depth($agg_checked);

send(200, envelope($NODE_ID, $PROTOCOL, $VERSION, $request, [
    'ok' => true,
    'partial' => $partial,
    'search_meta' => [
        'nodes_queried' => $nodes_queried,
        'nodes_reached' => $nodes_reached,
        'max_depth_used' => $max_depth_used,
    ],
    'results' => $agg_results,
    'checked' => $agg_checked,
    'errors' => [],
]));
