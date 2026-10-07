<?php

// The parts of FluentCart 1.7.0 that the gateway uses, cut down so it loads without FluentCart.
// Signatures follow app/Modules/PaymentMethods/Core/*.php.

namespace FluentCart\App\Modules\PaymentMethods\Core {

    if (!interface_exists(PaymentGatewayInterface::class)) {
        interface PaymentGatewayInterface
        {
            public function has(string $feature): bool;
            public function meta(): array;
            public function makePaymentFromPaymentInstance(\FluentCart\App\Services\Payments\PaymentInstance $paymentInstance);
            public function handleIPN();
            public function getOrderInfo(array $data);
            public function fields();
        }
    }

    if (!class_exists(AbstractSubscriptionModule::class)) {
        abstract class AbstractSubscriptionModule
        {
        }
    }

    if (!class_exists(BaseGatewaySettings::class)) {
        abstract class BaseGatewaySettings
        {
            /** Stored settings by methodHandler, standing in for FluentCart's meta table. */
            public static array $allSettings = [];

            public $settings;
            public $methodHandler;

            public function __construct()
            {
                $settings = self::$allSettings[$this->methodHandler] ?? [];
                $this->settings = array_merge(static::getDefaults(), is_array($settings) ? $settings : []);
            }

            abstract public function get($key = '');
            abstract public function getMode();
            abstract public function isActive();
        }
    }

    if (!class_exists(AbstractPaymentGateway::class)) {
        abstract class AbstractPaymentGateway implements PaymentGatewayInterface
        {
            public array $supportedFeatures = [];
            public ?AbstractSubscriptionModule $subscriptions;
            public BaseGatewaySettings $settings;

            public function __construct(BaseGatewaySettings $settings, ?AbstractSubscriptionModule $subscriptions = null)
            {
                $this->settings = $settings;
                if ($subscriptions) {
                    $this->supportedFeatures[] = 'subscriptions';
                }
                $this->subscriptions = $subscriptions;
            }

            public function has(string $feature): bool
            {
                return in_array($feature, $this->supportedFeatures);
            }

            public static function validateSettings($data): array
            {
                return $data;
            }

            public static function beforeSettingsUpdate($data, $oldSettings): array
            {
                return $data;
            }

            public function processRefund($transaction, $amount, $args)
            {
                return new \WP_Error('not_implemented', 'Refund process is not implemented for this payment gateway.');
            }

            public function getEnqueueScriptSrc($hasSubscription): array
            {
                return [];
            }

            public function getEnqueueStyleSrc(): array
            {
                return [];
            }

            public function getLocalizeData(): array
            {
                return [];
            }

            public function getTransactionUrl($url, $data)
            {
                return $url;
            }
        }
    }
}

namespace FluentCart\App\Services\Payments {
    if (!class_exists(PaymentInstance::class)) {
        class PaymentInstance
        {
            public $order;
            public $transaction;
            public $subscription;

            public function __construct($order = null, $transaction = null, $subscription = null)
            {
                $this->order = $order;
                $this->transaction = $transaction;
                $this->subscription = $subscription;
            }
        }
    }
}

namespace FluentCart\App\Helpers {
    if (!class_exists(Helper::class)) {
        // Reversible stand-in for FluentCart's openssl key encryption (app/Helpers/Helper.php:889-950):
        // idempotent encrypt, and decrypt passes plain values through.
        class Helper
        {
            public static function encryptKey($value)
            {
                if (!$value || strpos($value, 'enc:') === 0) {
                    return $value;
                }
                return 'enc:' . base64_encode($value);
            }

            public static function decryptKey($value)
            {
                if (!$value || strpos($value, 'enc:') !== 0) {
                    return $value;
                }
                return base64_decode(substr($value, 4));
            }
        }
    }

    if (!class_exists(Status::class)) {
        class Status
        {
            public const TRANSACTION_SUCCEEDED = 'succeeded';
            public const TRANSACTION_PENDING = 'pending';
            public const TRANSACTION_FAILED = 'failed';
            public const TRANSACTION_REFUNDED = 'refunded';
        }
    }

    if (!class_exists(StatusHelper::class)) {
        class StatusHelper
        {
            /** Orders whose statuses were synced, for assertions. */
            public static array $synced = [];

            private $order;

            public function __construct($order = null)
            {
                $this->order = $order;
            }

            public function syncOrderStatuses($latestTransaction = null)
            {
                self::$synced[] = [$this->order, $latestTransaction];
                return $this->order;
            }
        }
    }
}

namespace FluentCart\Api {
    if (!class_exists(StoreSettings::class)) {
        class StoreSettings
        {
            public function get($key = '')
            {
                return $key === 'store_name' ? 'Peptide Test Store' : null;
            }
        }
    }
}

namespace FluentCart\App\Models {
    if (!class_exists(OrderTransaction::class)) {
        class OrderTransaction
        {
            public $saved = 0;

            public function __construct(array $attributes = [])
            {
                $this->fill($attributes);
            }

            public function fill(array $attributes)
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
                return $this;
            }

            public function save()
            {
                $this->saved++;
                return true;
            }

            public function getReceiptPageUrl($filtered = true)
            {
                return 'https://store.test/receipt/?trx_hash=' . $this->uuid;
            }
        }
    }
}
