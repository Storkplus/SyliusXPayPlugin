<?php
const APP = 'http://xpay-app:8000';
$jar = tempnam('/tmp', 'jar');
function req($m, $u, $data = null) { global $jar; $ch = curl_init(APP . $u); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Host: localhost'], CURLOPT_FOLLOWLOCATION => false]); if ($data) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); } $b = curl_exec($ch); return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, curl_getinfo($ch, CURLINFO_REDIRECT_URL)]; }
[$c, $b] = req('GET', '/admin/login');
preg_match('/name="_csrf_admin_security_token" value="([^"]+)"/', $b, $m) || preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m);
$fields = ['_username' => 'sylius', '_password' => 'sylius'];
if (preg_match('/name="(_csrf[^"]*)" value="([^"]+)"/', $b, $mm)) { $fields[$mm[1]] = $mm[2]; }
[$c, $b, $loc] = req('POST', '/admin/login-check', $fields);
echo "login -> $c $loc\n";
[$c, $b] = req('GET', '/admin/payment-methods/new/xpay');
echo "create form -> $c\n";
foreach (['XPay webhook', 'http://localhost/payment/xpay/webhook', 'secret_key', 'webhook_secret', 'payment_method_configuration_id', 'checkout.session.async_payment_succeeded'] as $needle) { echo (str_contains($b, $needle) ? '  ✔ ' : '  ✘ '), $needle, "\n"; }
preg_match('/<title>([^<]+)/', $b, $t); if ($c >= 400) { echo substr($t[1] ?? '', 0, 300), "\n"; }
// gateway factory listed in the "create" dropdown
[$c, $b] = req('GET', '/admin/payment-methods/');
echo (str_contains($b, 'payment-methods/new/xpay') ? '  ✔ ' : '  ✘ '), "XPay offered in the create dropdown\n";
// edit page with code -> webhook URL
[$c, $b] = req('GET', '/admin/payment-methods/3/edit');
echo "edit form -> $c\n";
echo (str_contains($b, 'http://localhost/payment/xpay/webhook') ? '  ✔ ' : '  ✘ '), "static webhook URL shown on edit\n";
// validation: submit edit with a publishable key
preg_match_all('/name="(sylius_admin_payment_method\[[^"]+)"[^>]*?(?:value="([^"]*)")?/', $b, $all, PREG_SET_ORDER);
$form = []; foreach ($all as $f) { $form[$f[1]] = html_entity_decode($f[2] ?? ''); }
$form['sylius_admin_payment_method[gatewayConfig][config][secret_key]'] = 'pk_test_wrong';
$form['sylius_admin_payment_method[gatewayConfig][config][webhook_secret]'] = 'nope';
$form['_method'] = 'PUT';
preg_match('/<form[^>]+name="sylius_admin_payment_method"[^>]*action="([^"]*)"/', $b, $fa) || preg_match('/<form[^>]*action="([^"]*payment-methods[^"]*)"/', $b, $fa);
$action = $fa[1] ?? '/admin/payment-methods/3/edit'; $action = preg_replace('#^https?://localhost#', '', $action) ?: '/admin/payment-methods/3/edit';
echo "form action: $action
";
[$c, $b] = req('POST', $action, $form);
echo "submit invalid keys -> $c\n";
foreach (['Publishable pk_ keys cannot be used', 'starts with whsec_'] as $needle) { echo (str_contains($b, $needle) ? '  ✔ ' : '  ✘ '), "validation: $needle\n"; }
