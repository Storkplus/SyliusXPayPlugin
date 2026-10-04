<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Provider;

use Storkplus\SyliusXPayPlugin\CommandProvider\XPayCommandProvider;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverterInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * The order is sent as a single line item carrying the payment amount. Sylius
 * totals include promotions, taxes and shipping that XPay line items cannot
 * express (no negative lines), so itemising would not reproduce the exact total.
 * The item list goes into the line description instead.
 */
final class CheckoutSessionPayloadProvider implements CheckoutSessionPayloadProviderInterface
{
    private const DESCRIPTION_MAX_LENGTH = 500;

    public function __construct(
        private readonly UrlProviderInterface $returnUrlProvider,
        private readonly AmountConverterInterface $amountConverter,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): array
    {
        $payment = $paymentRequest->getPayment();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $order = $payment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $currencyCode = strtoupper((string) $payment->getCurrencyCode());
        $amount = $this->amountConverter->toMinorUnits((int) $payment->getAmount(), $currencyCode);

        $config = $paymentRequest->getMethod()->getGatewayConfig()?->getConfig() ?? [];

        /** @var array<string, mixed> $requestPayload */
        $requestPayload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];
        // Both URLs land on the Sylius pay URL of this request; the query flag tells the
        // capture-end step that the customer is coming back from the hosted page.
        $payUrl = $this->returnUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $returnUrl = is_string($requestPayload['success_url'] ?? null)
            ? $requestPayload['success_url']
            : $this->withQuery($payUrl, 'success');
        $cancelUrl = is_string($requestPayload['cancel_url'] ?? null)
            ? $requestPayload['cancel_url']
            : $this->withQuery($payUrl, 'cancel');

        $payload = [
            'afterCompletion' => [
                'redirect' => ['url' => $returnUrl],
                'type' => 'redirect',
            ],
            'cancelUrl' => $cancelUrl,
            'currency' => $currencyCode,
            'lineItems' => [[
                'priceData' => [
                    'currency' => $currencyCode,
                    'productData' => array_filter([
                        'description' => $this->describeItems($order),
                        'name' => $this->getLineName($order),
                    ]),
                    'unitAmount' => $amount,
                ],
                'quantity' => 1,
            ]],
            'locale' => $this->resolveLocale($order, $config),
            'metadata' => [
                XPayGateway::METADATA_ORDER_NUMBER => (string) $order->getNumber(),
                XPayGateway::METADATA_PAYMENT_ID => (string) $payment->getId(),
                XPayGateway::METADATA_PAYMENT_REQUEST_HASH => (string) $paymentRequest->getId(),
            ],
            'mode' => 'payment',
            'uiMode' => 'hosted',
        ];

        $customerDetails = $this->getCustomerDetails($order);
        if ([] !== $customerDetails) {
            $payload['customerDetails'] = $customerDetails;
        }

        $configurationId = $config[XPayGateway::CONFIG_PAYMENT_METHOD_CONFIGURATION_ID] ?? null;
        if (is_string($configurationId) && '' !== trim($configurationId)) {
            $payload['paymentMethodConfigurationId'] = trim($configurationId);
        }

        return $payload;
    }

    private function withQuery(string $url, string $value): string
    {
        return sprintf(
            '%s%s%s=%s',
            $url,
            str_contains($url, '?') ? '&' : '?',
            XPayCommandProvider::RETURN_QUERY_PARAMETER,
            $value,
        );
    }

    private function getLineName(OrderInterface $order): string
    {
        $channelName = $order->getChannel()?->getName();
        $orderLabel = sprintf('#%s', (string) $order->getNumber());

        return null !== $channelName && '' !== $channelName ? sprintf('%s %s', $channelName, $orderLabel) : $orderLabel;
    }

    private function describeItems(OrderInterface $order): string
    {
        $lines = [];
        /** @var OrderItemInterface $item */
        foreach ($order->getItems() as $item) {
            $name = $item->getProductName() ?? $item->getVariantName() ?? '';
            if ('' === $name) {
                continue;
            }
            $lines[] = sprintf('%d × %s', $item->getQuantity(), $name);
        }

        $description = implode(', ', $lines);
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH - 1) . '…';
        }

        return $description;
    }

    /** @param array<string, mixed> $config */
    private function resolveLocale(OrderInterface $order, array $config): string
    {
        $configured = $config[XPayGateway::CONFIG_LOCALE] ?? XPayGateway::LOCALE_AUTO;
        if (in_array($configured, ['ar', 'en'], true)) {
            return $configured;
        }

        return str_starts_with((string) $order->getLocaleCode(), 'ar') ? 'ar' : 'en';
    }

    /** @return array<string, string> */
    private function getCustomerDetails(OrderInterface $order): array
    {
        $details = [];

        $email = $order->getCustomer()?->getEmail();
        if (null !== $email && '' !== $email) {
            $details['email'] = $email;
        }

        $address = $order->getBillingAddress();
        if (!$address instanceof AddressInterface) {
            return $details;
        }

        $name = trim(sprintf('%s %s', (string) $address->getFirstName(), (string) $address->getLastName()));
        if ('' !== $name) {
            $details['name'] = $name;
        }

        $phone = $this->normalizePhone($address->getPhoneNumber(), $address->getCountryCode());
        if (null !== $phone) {
            $details['phone'] = $phone;
        }

        return $details;
    }

    /**
     * XPay validates phone numbers; an unparseable one would fail the whole session,
     * so only E.164 numbers (or Egyptian local mobiles we can convert) are sent.
     */
    private function normalizePhone(?string $phone, ?string $countryCode): ?string
    {
        if (null === $phone) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        if (1 === preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            return $digits;
        }

        if ('EG' === $countryCode && 1 === preg_match('/^01[0125]\d{8}$/', $digits)) {
            return '+2' . $digits;
        }

        return null;
    }
}
