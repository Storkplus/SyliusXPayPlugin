<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Form\Type;

use Storkplus\SyliusXPayPlugin\XPayGateway;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class XPayGatewayConfigurationType extends AbstractType
{
    private const GROUPS = ['sylius', XPayGateway::FACTORY_NAME];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(XPayGateway::CONFIG_SECRET_KEY, TextType::class, [
                'label' => 'storkplus_sylius_xpay.form.secret_key',
                'help' => 'storkplus_sylius_xpay.form.secret_key_help',
                'attr' => ['placeholder' => 'sk_test_…', 'autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(['message' => 'storkplus_sylius_xpay.secret_key.not_blank', 'groups' => self::GROUPS]),
                    new Regex([
                        'pattern' => '/^(sk|rk)_(test|live)_\S+$/',
                        'message' => 'storkplus_sylius_xpay.secret_key.invalid',
                        'groups' => self::GROUPS,
                    ]),
                ],
            ])
            ->add(XPayGateway::CONFIG_WEBHOOK_SECRET, TextType::class, [
                'label' => 'storkplus_sylius_xpay.form.webhook_secret',
                'help' => 'storkplus_sylius_xpay.form.webhook_secret_help',
                'attr' => ['placeholder' => 'whsec_…', 'autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(['message' => 'storkplus_sylius_xpay.webhook_secret.not_blank', 'groups' => self::GROUPS]),
                    new Regex([
                        'pattern' => '/^whsec_\S+$/',
                        'message' => 'storkplus_sylius_xpay.webhook_secret.invalid',
                        'groups' => self::GROUPS,
                    ]),
                ],
            ])
            ->add(XPayGateway::CONFIG_LOCALE, ChoiceType::class, [
                'label' => 'storkplus_sylius_xpay.form.locale',
                'choices' => [
                    'storkplus_sylius_xpay.form.locale_auto' => XPayGateway::LOCALE_AUTO,
                    'storkplus_sylius_xpay.form.locale_ar' => 'ar',
                    'storkplus_sylius_xpay.form.locale_en' => 'en',
                ],
                'empty_data' => XPayGateway::LOCALE_AUTO,
            ])
            ->add(XPayGateway::CONFIG_PAYMENT_METHOD_CONFIGURATION_ID, TextType::class, [
                'label' => 'storkplus_sylius_xpay.form.payment_method_configuration_id',
                'help' => 'storkplus_sylius_xpay.form.payment_method_configuration_id_help',
                'required' => false,
                'empty_data' => '',
            ])
        ;
    }
}
