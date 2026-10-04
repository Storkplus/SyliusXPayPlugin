<?php
// Creates the "xpay" payment method on every channel, through the plugin's own form type keys.
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/tests/TestApplication/.env');
$kernel = new Sylius\TestApplication\Kernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine.orm.entity_manager');
$repo = $c->get('sylius.repository.payment_method');
$pm = $repo->findOneBy(['code' => 'xpay']) ?? $c->get('sylius.factory.payment_method')->createWithGateway('xpay');
$pm->setCode('xpay');
$pm->setCurrentLocale('en_US'); $pm->setFallbackLocale('en_US');
$pm->setName('XPay');
$pm->getGatewayConfig()->setGatewayName('xpay');
$pm->getGatewayConfig()->setUsePayum(false); // what the admin form does for non-Payum factories
$pm->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_e2e', 'webhook_secret' => 'whsec_e2e_secret', 'locale' => 'auto', 'payment_method_configuration_id' => '']);
foreach ($c->get('sylius.repository.channel')->findAll() as $ch) { $pm->addChannel($ch); echo "channel ", $ch->getCode(), " ", $ch->getBaseCurrency()->getCode(), " host=", $ch->getHostname() ?? '-', "\n"; }
$em->persist($pm); $em->flush();
echo "payment method created id=", $pm->getId(), "\n";
