<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\CommandProvider;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Command\CancelPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\CaptureEndPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\CapturePaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\NotifyPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\RefundPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\StatusPaymentRequest;
use Storkplus\SyliusXPayPlugin\CommandProvider\XPayCommandProvider;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class XPayCommandProviderTest extends TestCase
{
    public function test_a_new_capture_creates_the_session(): void
    {
        self::assertInstanceOf(CapturePaymentRequest::class, (new XPayCommandProvider())->provide($this->request(PaymentRequestInterface::ACTION_CAPTURE)));
    }

    public function test_a_processing_capture_reads_the_outcome_and_knows_where_the_customer_comes_from(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request(['xpay_return' => 'cancel']));

        $command = (new XPayCommandProvider($stack))->provide($this->request(PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING));
        self::assertInstanceOf(CaptureEndPaymentRequest::class, $command);
        self::assertTrue($command->isReturnedFromGateway());

        $stack->push(new Request());
        $command = (new XPayCommandProvider($stack))->provide($this->request(PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING));
        self::assertInstanceOf(CaptureEndPaymentRequest::class, $command);
        self::assertFalse($command->isReturnedFromGateway());
    }

    public function test_other_actions(): void
    {
        $provider = new XPayCommandProvider();

        self::assertInstanceOf(StatusPaymentRequest::class, $provider->provide($this->request(PaymentRequestInterface::ACTION_STATUS)));
        self::assertInstanceOf(NotifyPaymentRequest::class, $provider->provide($this->request(PaymentRequestInterface::ACTION_NOTIFY)));
        self::assertInstanceOf(RefundPaymentRequest::class, $provider->provide($this->request(PaymentRequestInterface::ACTION_REFUND)));
        self::assertInstanceOf(CancelPaymentRequest::class, $provider->provide($this->request(PaymentRequestInterface::ACTION_CANCEL)));
        self::assertFalse($provider->supports($this->request('payout')));
    }

    private function request(string $action, string $state = PaymentRequestInterface::STATE_NEW): PaymentRequest
    {
        $request = new PaymentRequest(new Payment(), new PaymentMethod());
        $request->setAction($action);
        $request->setState($state);

        return $request;
    }
}
