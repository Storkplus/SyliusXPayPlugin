<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin;

/**
 * Names shared by every part of the plugin: the gateway factory name stored on
 * the payment method, the gateway config keys and the keys written into the
 * payment details.
 */
final class XPayGateway
{
    public const FACTORY_NAME = 'xpay';

    public const CONFIG_SECRET_KEY = 'secret_key';

    public const CONFIG_WEBHOOK_SECRET = 'webhook_secret';

    public const CONFIG_LOCALE = 'locale';

    public const CONFIG_PAYMENT_METHOD_CONFIGURATION_ID = 'payment_method_configuration_id';

    public const LOCALE_AUTO = 'auto';

    /** Key under which the Checkout Session snapshot lives in Payment::$details. */
    public const DETAILS_KEY = 'xpay';

    /** Metadata key carrying the Sylius payment request hash on the Checkout Session. */
    public const METADATA_PAYMENT_REQUEST_HASH = 'sylius_payment_request_hash';

    public const METADATA_ORDER_NUMBER = 'sylius_order_number';

    public const METADATA_PAYMENT_ID = 'sylius_payment_id';

    /** Request attribute holding the signature-verified webhook event. */
    public const REQUEST_ATTRIBUTE_EVENT = '_xpay_event';

    public const EVENT_SESSION_COMPLETED = 'checkout.session.completed';

    public const EVENT_SESSION_ASYNC_SUCCEEDED = 'checkout.session.async_payment_succeeded';

    public const EVENT_SESSION_ASYNC_FAILED = 'checkout.session.async_payment_failed';

    public const EVENT_SESSION_EXPIRED = 'checkout.session.expired';

    /** The webhook events the plugin acts on. Everything else is acknowledged and ignored. */
    public const HANDLED_EVENTS = [
        self::EVENT_SESSION_COMPLETED,
        self::EVENT_SESSION_ASYNC_SUCCEEDED,
        self::EVENT_SESSION_ASYNC_FAILED,
        self::EVENT_SESSION_EXPIRED,
    ];

    public const SESSION_STATUS_OPEN = 'open';

    public const SESSION_STATUS_COMPLETE = 'complete';

    public const SESSION_STATUS_EXPIRED = 'expired';

    public const PAYMENT_STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_UNPAID = 'unpaid';

    public const PAYMENT_STATUS_NO_PAYMENT_REQUIRED = 'no_payment_required';

    /** Payment Intent states meaning "the customer submitted, the outcome is not known yet". */
    public const PAYMENT_INTENT_PENDING_STATUSES = [
        'PROCESSING',
        'REQUIRES_ACTION',
        'REQUIRES_CONFIRMATION',
        'UNCAPTURED',
    ];

    /** Currencies accepted by POST /checkout/sessions. */
    public const SUPPORTED_CURRENCIES = [
        'EGP', 'USD', 'EUR', 'GBP', 'SAR', 'AED', 'QAR', 'KWD', 'JOD', 'OMR', 'BHD', 'LYD', 'AUD', 'CAD', 'CNY',
    ];

    private function __construct()
    {
    }
}
