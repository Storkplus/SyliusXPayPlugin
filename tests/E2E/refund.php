<?php
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/tests/TestApplication/.env');
$kernel = new Sylius\TestApplication\Kernel('test', true); $kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine.orm.entity_manager');
$payment = $em->createQuery("SELECT p FROM Sylius\Component\Core\Model\Payment p JOIN p.method m WHERE m.code = 'xpay' AND p.state = 'completed' ORDER BY p.id DESC")->setMaxResults(1)->getSingleResult();
echo "refunding payment #", $payment->getId(), " amount ", $payment->getAmount(), " pi=", $payment->getDetails()['xpay']['payment_intent_id'], "\n";
$sm = $c->get('sylius_abstraction.state_machine');
$sm->apply($payment, 'sylius_payment', 'refund');
$em->flush();
$em->clear();
$p = $em->find(Sylius\Component\Core\Model\Payment::class, $payment->getId());
echo "payment state: ", $p->getState(), "\n";
echo "refunds: ", json_encode($p->getDetails()['xpay']['refunds'] ?? null), "\n";
$pr = $c->get('sylius.repository.payment_request')->findOneBy(['payment' => $p, 'action' => 'refund']);
echo "refund request: ", $pr?->getState(), ' ', json_encode($pr?->getResponseData()), "\n";
