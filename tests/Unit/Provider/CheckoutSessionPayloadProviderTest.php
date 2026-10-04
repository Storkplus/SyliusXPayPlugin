<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverter;
use Storkplus\SyliusXPayPlugin\Provider\CheckoutSessionPayloadProvider;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CheckoutSessionPayloadProviderTest extends TestCase
{
    public function test_it_builds_a_hosted_session_for_the_payment_amount(): void
    {
        $payload = $this->provider()->provide($this->paymentRequest());

        self::assertSame('payment', $payload['mode']);
        self::assertSame('hosted', $payload['uiMode']);
        self::assertSame('EGP', $payload['currency']);
        self::assertSame('https://shop.test/en_US/pay/hash-1?xpay_return=success', $payload['afterCompletion']['redirect']['url']);
        self::assertSame('redirect', $payload['afterCompletion']['type']);
        self::assertSame('https://shop.test/en_US/pay/hash-1?xpay_return=cancel', $payload['cancelUrl']);

        self::assertCount(1, $payload['lineItems']);
        self::assertSame(1, $payload['lineItems'][0]['quantity']);
        self::assertSame(149900, $payload['lineItems'][0]['priceData']['unitAmount']);
        self::assertSame('EGP', $payload['lineItems'][0]['priceData']['currency']);
        self::assertSame('Smash Store #000042', $payload['lineItems'][0]['priceData']['productData']['name']);
        self::assertSame('2 × Burger', $payload['lineItems'][0]['priceData']['productData']['description']);

        self::assertSame('000042', $payload['metadata']['sylius_order_number']);
        self::assertSame('ar', $payload['locale']);
        self::assertSame(['email' => 'aya@example.com', 'name' => 'Aya Hassan', 'phone' => '+201001234567'], $payload['customerDetails']);
        self::assertArrayNotHasKey('paymentMethodConfigurationId', $payload);
    }

    public function test_it_respects_the_configured_locale_and_payment_method_configuration(): void
    {
        $payload = $this->provider()->provide($this->paymentRequest(['locale' => 'en', 'payment_method_configuration_id' => ' pmc_123 ']));

        self::assertSame('en', $payload['locale']);
        self::assertSame('pmc_123', $payload['paymentMethodConfigurationId']);
    }

    public function test_it_drops_phone_numbers_xpay_would_reject(): void
    {
        $payload = $this->provider()->provide($this->paymentRequest(phone: '12345'));

        self::assertArrayNotHasKey('phone', $payload['customerDetails']);
    }

    public function test_it_keeps_e164_numbers_and_converts_00_prefixes(): void
    {
        self::assertSame('+971501234567', $this->provider()->provide($this->paymentRequest(phone: '00971 50 123 4567'))['customerDetails']['phone']);
    }

    public function test_urls_from_the_payload_override_the_shop_urls(): void
    {
        $request = $this->paymentRequest();
        $request->setPayload(['success_url' => 'https://app.test/done', 'cancel_url' => 'https://app.test/failed']);

        $payload = $this->provider()->provide($request);

        self::assertSame('https://app.test/done', $payload['afterCompletion']['redirect']['url']);
        self::assertSame('https://app.test/failed', $payload['cancelUrl']);
    }

    private function provider(): CheckoutSessionPayloadProvider
    {
        $urlProvider = $this->createMock(UrlProviderInterface::class);
        $urlProvider->method('getUrl')->with(self::anything(), UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://shop.test/en_US/pay/hash-1');

        return new CheckoutSessionPayloadProvider($urlProvider, new AmountConverter());
    }

    /** @param array<string, mixed> $config */
    private function paymentRequest(array $config = ['locale' => 'auto'], string $phone = '01001234567'): PaymentRequest
    {
        $channel = new Channel();
        $channel->setName('Smash Store');

        $customer = new Customer();
        $customer->setEmail('aya@example.com');

        $address = new Address();
        $address->setFirstName('Aya');
        $address->setLastName('Hassan');
        $address->setPhoneNumber($phone);
        $address->setCountryCode('EG');

        $item = new OrderItem();
        $item->setProductName('Burger');
        $item->setVariantName('Burger');

        $order = new Order();
        $order->setNumber('000042');
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setBillingAddress($address);
        $order->setLocaleCode('ar');
        $order->setCurrencyCode('EGP');
        $order->addItem($item);
        $reflection = new \ReflectionProperty(OrderItem::class, 'quantity');
        $reflection->setValue($item, 2);

        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('xpay');
        $gatewayConfig->setGatewayName('xpay');
        $gatewayConfig->setConfig(array_merge(['secret_key' => 'sk_test_1'], $config));

        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setAmount(149900);
        $payment->setCurrencyCode('EGP');
        $payment->setMethod($method);
        $order->addPayment($payment);

        return new PaymentRequest($payment, $method);
    }
}
