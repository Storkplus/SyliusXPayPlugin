<?php
// Minimal XPay API mock implementing the documented contract used by the plugin.
// State in a JSON file. Control endpoints (/_mock/...) simulate the customer paying.
$db = '/e2e/mock_state.json'; // git-ignored
$state = is_file($db) ? json_decode(file_get_contents($db), true) : ['sessions' => [], 'refunds' => [], 'idem' => [], 'log' => []];
$save = function () use (&$state, $db) { file_put_contents($db, json_encode($state, JSON_PRETTY_PRINT)); };
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$idem = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$state['log'][] = "$method $path" . ($idem ? " idem=$idem" : '');
header('Content-Type: application/json');
$json = function ($code, $data) use ($save) { $save(); http_response_code($code); echo json_encode($data); exit; };
const SECRET = 'whsec_e2e_secret';
$sign = function (array $event) { $raw = json_encode($event); $t = time(); return [$raw, "t=$t,v1=" . hash_hmac('sha256', "$t.$raw", SECRET)]; };
$send = function (string $type, array $session) use ($sign, &$state) {
    $event = ['id' => 'evt_test_' . bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => $session]];
    [$raw, $header] = $sign($event);
    $ch = curl_init($state['webhook_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'XPay-Signature: ' . $header]]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $state['log'][] = "WEBHOOK $type -> $code";
    return ['code' => $code, 'body' => $resp];
};

if (str_starts_with($path, '/_mock/')) {
    $parts = explode('/', trim($path, '/'));
    if ($parts[1] === 'config') { $state['webhook_url'] = $body['webhook_url']; $json(200, ['ok' => true]); }
    if ($parts[1] === 'state') { $json(200, $state); }
    $id = $parts[2] ?? '';
    $s = &$state['sessions'][$id];
    if ($s === null) { $json(404, ['error' => 'no session']); }
    if ($parts[1] === 'pay') { // card approved
        $s['status'] = 'complete'; $s['paymentStatus'] = 'paid';
        $s['paymentIntent'] = ['id' => 'pi_test_' . substr($id, 8), 'status' => 'SUCCEEDED'];
        $w = $send('checkout.session.completed', $s);
        $json(200, ['webhook' => $w, 'session' => $s]);
    }
    if ($parts[1] === 'race') { // paid; webhook is delivered while the shop is reading the session on return
        $s['status'] = 'complete'; $s['paymentStatus'] = 'paid';
        $s['paymentIntent'] = ['id' => 'pi_test_' . substr($id, 8), 'status' => 'SUCCEEDED'];
        $s['race'] = true;
        $json(200, ['session' => $s]);
    }
    if ($parts[1] === 'decline') { // card declined, customer bounced to cancelUrl
        $s['paymentIntent'] = ['id' => 'pi_test_' . substr($id, 8), 'status' => 'PAYMENT_FAILED', 'lastPaymentError' => ['code' => 'card_declined', 'message' => 'Declined']];
        $json(200, ['session' => $s]);
    }
    if ($parts[1] === 'fawry') { // voucher issued, then paid
        $s['status'] = 'complete'; $s['paymentStatus'] = 'unpaid';
        $s['paymentIntent'] = ['id' => 'pi_test_' . substr($id, 8), 'status' => 'REQUIRES_ACTION', 'nextAction' => ['displayVoucherDetails' => ['reference' => '7788990011', 'expiresAt' => '2026-10-06T00:00:00Z']]];
        $w = $send('checkout.session.completed', $s);
        $json(200, ['webhook' => $w, 'session' => $s]);
    }
    if ($parts[1] === 'fawry_paid') {
        $s['paymentStatus'] = 'paid'; $s['paymentIntent']['status'] = 'SUCCEEDED'; unset($s['paymentIntent']['nextAction']);
        $w = $send('checkout.session.async_payment_succeeded', $s);
        $json(200, ['webhook' => $w]);
    }
    if ($parts[1] === 'forged') { // bad signature
        $event = ['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => array_merge($s, ['paymentStatus' => 'paid'])]];
        $ch = curl_init($state['webhook_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($event), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['XPay-Signature: t=' . time() . ',v1=deadbeef']]);
        curl_exec($ch); $json(200, ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE)]);
    }
    $json(400, ['error' => 'unknown control']);
}

if (!preg_match('/^Bearer sk_test_\w+$/', $auth)) { $json(401, ['error' => ['type' => 'authentication_error', 'code' => 'invalid_api_key', 'message' => 'Invalid API key']]); }

if ($method === 'POST' && $path === '/checkout/sessions') {
    if ($idem && isset($state['idem'][$idem])) { header('Idempotent-Replayed: true'); $json(200, $state['sessions'][$state['idem'][$idem]]); }
    foreach (['afterCompletion', 'lineItems'] as $req) { if (empty($body[$req])) { $json(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_missing', 'param' => $req, 'message' => "$req is required"]]); } }
    $total = 0; foreach ($body['lineItems'] as $li) { $total += $li['priceData']['unitAmount'] * $li['quantity']; }
    $id = 'cs_test_' . bin2hex(random_bytes(8));
    $session = ['id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'paymentStatus' => 'unpaid', 'mode' => 'payment', 'uiMode' => 'hosted',
        'url' => "https://checkout.xpay.app/c/$id", 'amountTotal' => $total, 'currency' => $body['currency'], 'livemode' => false,
        'metadata' => $body['metadata'] ?? [], 'afterCompletion' => $body['afterCompletion'], 'cancelUrl' => $body['cancelUrl'] ?? null,
        'customerDetails' => $body['customerDetails'] ?? null, 'locale' => $body['locale'] ?? null, 'lineItems' => $body['lineItems'], 'paymentIntent' => null];
    $state['sessions'][$id] = $session; if ($idem) { $state['idem'][$idem] = $id; }
    $json(200, $session);
}
if ($method === 'GET' && preg_match('#^/checkout/sessions/([\w]+)$#', $path, $m)) {
    if (!empty($state['sessions'][$m[1]]['race'])) {
        unset($state['sessions'][$m[1]]['race']);
        $save();
        $send('checkout.session.completed', $state['sessions'][$m[1]]);
    }
    isset($state['sessions'][$m[1]]) ? $json(200, $state['sessions'][$m[1]]) : $json(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such session']]);
}
if ($method === 'POST' && preg_match('#^/checkout/sessions/([\w]+)/expire$#', $path, $m)) {
    $s = &$state['sessions'][$m[1]];
    if ($s['status'] !== 'open') { $json(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_invalid_state', 'message' => 'Session is not open']]); }
    $s['status'] = 'expired'; $json(200, $s);
}
if ($method === 'POST' && $path === '/refunds') {
    if ($idem && isset($state['refunds'][$idem])) { $json(200, $state['refunds'][$idem]); }
    $r = ['id' => 're_test_' . bin2hex(random_bytes(6)), 'object' => 'refund', 'amount' => $body['amount'], 'paymentIntentId' => $body['paymentIntentId'], 'reason' => $body['reason'] ?? null, 'status' => 'succeeded'];
    $state['refunds'][$idem ?? $r['id']] = $r; $json(200, $r);
}
if ($method === 'GET' && $path === '/account') { $json(200, ['id' => 'acct_test', 'displayName' => 'E2E Store', 'livePaymentsEnabled' => false]); }
$json(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No route $method $path"]]);
