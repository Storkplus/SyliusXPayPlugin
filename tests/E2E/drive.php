<?php
// E2E driver: guest checkout via Shop API, then the XPay pay/return/webhook chain.
// Usage: php drive.php <scenario: card|decline|fawry|forged|reenter>
$scenario = $argv[1] ?? 'card';
const APP = 'http://xpay-app:8000';
const MOCK = 'http://xpay-mock:8001';
$jar = tempnam('/tmp', 'jar');
function req(string $method, string $url, $body = null, array $headers = [], bool $follow = false): array {
    global $jar;
    if (str_starts_with($url, '/')) { $url = APP . $url; }
    $url = preg_replace('#^https?://localhost(:\d+)?#', APP, $url);
    $ch = curl_init($url);
    $h = array_merge(['Host: localhost', 'Accept: application/ld+json'], $headers);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($raw, 0, $hs); $content = substr($raw, $hs);
    preg_match('/^location:\s*(.+)$/mi', $headersRaw, $m);
    return ['code' => $code, 'location' => isset($m[1]) ? trim($m[1]) : null, 'json' => json_decode($content, true), 'body' => $content];
}
function api(string $method, string $path, $body = null): array {
    $ct = $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json';
    $r = req($method, APP . $path, $body, ['Content-Type: ' . $ct]);
    if ($r['code'] >= 400) { fwrite(STDERR, "API $method $path -> {$r['code']} " . substr($r['body'], 0, 400) . "\n"); exit(1); }
    return $r['json'];
}
function mock(string $path, $body = null): array { $ch = curl_init(MOCK . $path); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass()), CURLOPT_RETURNTRANSFER => true]); return json_decode(curl_exec($ch), true) ?? []; }
function db(string $sql): array { $pdo = new PDO('mysql:host=xpay-e2e-db;dbname=xpay_e2e', 'root', 'root'); return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function step(string $s) { echo "→ $s\n"; }
function check(bool $ok, string $what) { echo ($ok ? "  ✔ " : "  ✘ ") . $what . "\n"; if (!$ok) { echo "FAIL
"; exit(1); } }
function path(string $url): string { $p = parse_url($url); return ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : ''); }

mock('/_mock/config', ['webhook_url' => APP . '/payment-methods/xpay']);

// --- checkout via Shop API
$variant = db("SELECT code FROM sylius_product_variant WHERE enabled = 1 ORDER BY id LIMIT 1")[0]['code'];
$order = api('POST', '/api/v2/shop/orders', ['localeCode' => 'en_US']);
$token = $order['tokenValue'];
api('POST', "/api/v2/shop/orders/$token/items", ['productVariant' => "/api/v2/shop/product-variants/$variant", 'quantity' => 2]);
$order = api('PUT', "/api/v2/shop/orders/$token", ['email' => "aya.$scenario@example.com", 'billingAddress' => [
    'firstName' => 'Aya', 'lastName' => 'Hassan', 'street' => '1 Tahrir Sq', 'city' => 'Cairo', 'postcode' => '11511', 'countryCode' => 'US', 'phoneNumber' => '+201001234567']]);
$shipmentId = $order['shipments'][0]['id'];
$ship = api('GET', "/api/v2/shop/orders/$token/shipments/$shipmentId/methods");
$members = $ship['hydra:member'] ?? $ship['member'] ?? [];
$shipCode = $members[0]['code'] ?? (isset($members[0]['shippingMethod']) ? basename((string) $members[0]['shippingMethod']) : null);
if (!$shipCode) { fwrite(STDERR, 'no shipping method: ' . substr(json_encode($ship), 0, 600) . "\n"); exit(1); }
api('PATCH', "/api/v2/shop/orders/$token/shipments/$shipmentId", ['shippingMethod' => "/api/v2/shop/shipping-methods/$shipCode"]);
$paymentId = $order['payments'][0]['id'];
api('PATCH', "/api/v2/shop/orders/$token/payments/$paymentId", ['paymentMethod' => '/api/v2/shop/payment-methods/xpay']);
$order = api('PATCH', "/api/v2/shop/orders/$token/complete", ['notes' => 'e2e']);
step("order {$order['number']} placed, total {$order['total']} {$order['currencyCode']}, checkout={$order['checkoutState']}");

// --- customer hits "pay"
$r = req('GET', APP . "/en_US/order/$token/pay");
step("GET /order/{token}/pay -> {$r['code']} " . path($r['location'] ?? ''));
$r = req('GET', $r['location']);
step("GET pay URL -> {$r['code']} {$r['location']}");
check($r['code'] === 303 && str_starts_with((string) $r['location'], 'https://checkout.xpay.app/c/cs_test_'), 'redirected to the XPay hosted checkout');
$sessionId = basename((string) $r['location']);
$state = mock('/_mock/state');
$s = $state['sessions'][$sessionId];
check($s['amountTotal'] === (int) $order['total'], "session amount {$s['amountTotal']} == order total {$order['total']}");
check(str_contains($s['afterCompletion']['redirect']['url'], 'xpay_return=success') && str_contains($s['cancelUrl'], 'xpay_return=cancel'), 'return + cancel URLs point back to /pay/{hash}');
check(($s['customerDetails']['email'] ?? null) === "aya.$scenario@example.com" && ($s['customerDetails']['phone'] ?? null) === '+201001234567', 'customer details prefilled');
check(isset($s['metadata']['sylius_payment_request_hash']), 'payment request hash in session metadata');
$payUrl = $s['afterCompletion']['redirect']['url'];
$cancelUrl = $s['cancelUrl'];

// replay: second POST with same idempotency key must not open a 2nd session
$r2 = req('GET', APP . "/en_US/order/$token/pay"); $r2 = req('GET', $r2['location']);
check(basename((string) $r2['location']) === $sessionId, 're-entering pay before paying returns to the same session');
check(count(array_filter(mock('/_mock/state')['log'], fn ($l) => str_starts_with($l, 'POST /checkout/sessions'))) === 1, 'only one session created');

$payState = fn () => db("SELECT p.state, p.details FROM sylius_payment p JOIN sylius_order o ON o.id = p.order_id WHERE o.token_value = '$token' ORDER BY p.id")[0];
$orderState = fn () => db("SELECT payment_state FROM sylius_order WHERE token_value = '$token'")[0]['payment_state'];

if ($scenario === 'card') {
    $w = mock("/_mock/pay/$sessionId");
    step("XPay sends signed checkout.session.completed -> HTTP {$w['webhook']['code']}");
    check($w['webhook']['code'] === 204, 'webhook accepted (204)');
    check($payState()['state'] === 'completed', 'payment completed by webhook');
    check($orderState() === 'paid', 'order payment_state = paid');
    $d = json_decode($payState()['details'], true)['xpay'] ?? [];
    check(($d['payment_intent_id'] ?? '') !== '' && $d['session_id'] === $sessionId, 'session + payment intent stored on payment');
    // duplicate delivery
    $w = mock("/_mock/pay/$sessionId");
    check($w['webhook']['code'] === 204 && $payState()['state'] === 'completed', 'redelivered webhook is a no-op');
    // customer comes back
    $r = req('GET', $payUrl);
    step("customer returns -> {$r['code']} " . path((string) $r['location']));
    $r = req('GET', $r['location']);
    step("after-pay -> {$r['code']} " . path((string) $r['location']));
    check(str_contains((string) $r['location'], 'thank-you'), 'customer lands on thank-you page');
}
if ($scenario === 'decline') {
    mock("/_mock/decline/$sessionId");
    $r = req('GET', $cancelUrl);
    step("customer bounced to cancelUrl -> {$r['code']} " . path((string) $r['location']));
    check(mock('/_mock/state')['sessions'][$sessionId]['status'] === 'expired', 'session expired on XPay');
    $payments = db("SELECT p.state FROM sylius_payment p JOIN sylius_order o ON o.id = p.order_id WHERE o.token_value = '$token' ORDER BY p.id");
    check($payments[0]['state'] === 'failed' && ($payments[1]['state'] ?? null) === 'new', 'payment failed, Sylius opened a new one for retry');
    $r = req('GET', $r['location']);
    step("after-pay -> {$r['code']} " . path((string) $r['location']));
    check(str_contains((string) $r['location'], "/order/$token"), 'customer sent to order page to retry');
    // retry with a fresh session and pay
    $r = req('GET', APP . "/en_US/order/$token/pay"); $r = req('GET', $r['location']);
    $newSession = basename((string) $r['location']);
    check($newSession !== $sessionId && str_starts_with($newSession, 'cs_test_'), 'retry opens a new XPay session');
    $w = mock("/_mock/pay/$newSession");
    check($w['webhook']['code'] === 204 && $orderState() === 'paid', 'retry paid -> order paid');
}
if ($scenario === 'fawry') {
    $w = mock("/_mock/fawry/$sessionId");
    check($w['webhook']['code'] === 204 && $payState()['state'] === 'processing', 'Fawry reference issued -> payment processing');
    $d = json_decode($payState()['details'], true)['xpay'];
    check(($d['voucher']['reference'] ?? null) === '7788990011', 'Fawry reference stored');
    $r = req('GET', $payUrl); $r = req('GET', $r['location']);
    check(str_contains((string) $r['location'], 'thank-you'), 'customer sees thank-you while awaiting Fawry payment');
    check($orderState() !== 'paid', 'order not paid yet');
    $w = mock("/_mock/fawry_paid/$sessionId");
    check($w['webhook']['code'] === 204 && $payState()['state'] === 'completed' && $orderState() === 'paid', 'async_payment_succeeded -> order paid');
}
if ($scenario === 'forged') {
    $w = mock("/_mock/forged/$sessionId");
    check($w['code'] === 400, "forged webhook rejected ({$w['code']})");
    check($payState()['state'] === 'new', 'payment untouched');
}
file_put_contents('/e2e/last_order_token', $token);
echo empty($GLOBALS['failed']) ? "PASS $scenario\n" : "FAIL $scenario\n";
