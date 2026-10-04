<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Console;

use Doctrine\ORM\EntityManagerInterface;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'storkplus:xpay:webhook',
    description: 'Check an XPay payment method and print (or register) its webhook endpoint.',
)]
final class WebhookCommand extends Command
{
    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly XPayClientInterface $client,
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Payment method code')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL of the shop, e.g. https://mystore.storkplus.com')
            ->addOption('register', null, InputOption::VALUE_NONE, 'Create the endpoint on XPay and save its signing secret on the payment method')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $code */
        $code = $input->getArgument('code');
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);
        $gatewayConfig = $paymentMethod?->getGatewayConfig();
        if (null === $gatewayConfig || XPayGateway::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            $io->error(sprintf('"%s" is not an XPay payment method.', $code));

            return Command::FAILURE;
        }

        $config = $gatewayConfig->getConfig();
        $secretKey = is_string($config[XPayGateway::CONFIG_SECRET_KEY] ?? null) ? $config[XPayGateway::CONFIG_SECRET_KEY] : '';

        try {
            $account = $this->client->retrieveAccount($secretKey);
        } catch (XPayApiException $exception) {
            $io->error(sprintf('The secret key was rejected: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Payment method' => $code],
            ['Key mode' => str_contains($secretKey, '_live_') ? 'live' : 'test'],
            ['Merchant' => sprintf('%s (%s)', self::str($account['displayName'] ?? null), self::str($account['id'] ?? null))],
            ['Live payments enabled' => true === ($account['livePaymentsEnabled'] ?? null) ? 'yes' : 'no'],
            ['Webhook secret set' => '?' !== self::str($config[XPayGateway::CONFIG_WEBHOOK_SECRET] ?? null) ? 'yes' : 'NO'],
        );

        /** @var string|null $baseUrl */
        $baseUrl = $input->getOption('base-url');
        $path = $this->router->generate('storkplus_sylius_xpay_webhook', [], UrlGeneratorInterface::ABSOLUTE_PATH);
        $url = null !== $baseUrl ? rtrim($baseUrl, '/') . $path : $this->router->generate('storkplus_sylius_xpay_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $io->writeln(sprintf('Webhook URL: <info>%s</info>', $url));

        if (!$input->getOption('register')) {
            return Command::SUCCESS;
        }

        if (!str_starts_with($url, 'https://')) {
            $io->error('XPay requires an https:// webhook URL; pass --base-url.');

            return Command::FAILURE;
        }

        try {
            $endpoint = $this->client->createWebhookEndpoint($secretKey, $url, XPayGateway::HANDLED_EVENTS, sprintf('Sylius payment method %s', $code));
        } catch (XPayApiException $exception) {
            $io->error(sprintf('XPay refused to create the endpoint (%s). Add it from the dashboard instead.', $exception->getMessage()));

            return Command::FAILURE;
        }

        $secret = $endpoint['secret'] ?? $endpoint['signingSecret'] ?? null;
        if (!is_string($secret) || !str_starts_with($secret, 'whsec_')) {
            $io->warning('The endpoint was created but XPay did not return its signing secret. Recreate it from the dashboard to see the secret.');

            return Command::FAILURE;
        }

        $config[XPayGateway::CONFIG_WEBHOOK_SECRET] = $secret;
        $gatewayConfig->setConfig($config);
        $this->entityManager->flush();

        $io->success(sprintf('Endpoint %s registered and its signing secret saved.', self::str($endpoint['id'] ?? null)));

        return Command::SUCCESS;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) && '' !== $value ? $value : '?';
    }
}
