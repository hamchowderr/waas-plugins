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
            public const TRANSACTION_TYPE_CHARGE = 'charge';
            public const TRANSACTION_TYPE_REFUND = 'refund';
            public const ORDER_TYPE_RENEWAL = 'renewal';
            public const PAYMENT_PENDING = 'pending';
            public const PAYMENT_SCHEDULED = 'scheduled';
            public const SUBSCRIPTION_ACTIVE = 'active';
            public const SUBSCRIPTION_TRIALING = 'trialing';
            public const SUBSCRIPTION_PAST_DUE = 'past_due';
            public const SUBSCRIPTION_EXPIRED = 'expired';
            public const SUBSCRIPTION_CANCELED = 'canceled';
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
            /** Store settings tests set, e.g. ['order_mode' => 'live']. */
            public static array $values = [];

            public function get($key = '')
            {
                if (array_key_exists($key, self::$values)) {
                    return self::$values[$key];
                }
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

            /** Like FluentCart's models, an attribute that was never set reads as null. */
            public function __get($name)
            {
                return null;
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
                if (!isset($this->id)) {
                    $this->id = count(self::$table) ? max(array_keys(self::$table)) + 1 : 1;
                }
                self::$table[$this->id] = $this;
                return true;
            }

            /** Saved transactions by id, standing in for the fct_order_transactions table. */
            public static array $table = [];

            public static function query()
            {
                return new OrderTransactionQuery(self::$table);
            }

            public function delete()
            {
                unset(self::$table[$this->id]);
                return true;
            }

            public function getReceiptPageUrl($filtered = true)
            {
                return 'https://store.test/receipt/?trx_hash=' . $this->uuid;
            }
        }
    }

    if (!class_exists(OrderTransactionQuery::class)) {
        /** The where/orderBy/get subset of FluentCart's query builder that the plugin uses. */
        class OrderTransactionQuery
        {
            private array $rows;

            public function __construct(array $rows)
            {
                $this->rows = array_values($rows);
            }

            public function where($column, $value)
            {
                $this->rows = array_values(array_filter($this->rows, fn ($row) => ($row->{$column} ?? null) == $value));
                return $this;
            }

            public function orderBy($column, $direction = 'ASC')
            {
                usort($this->rows, fn ($a, $b) => strtoupper($direction) === 'DESC' ? $b->{$column} <=> $a->{$column} : $a->{$column} <=> $b->{$column});
                return $this;
            }

            public function get()
            {
                return $this->rows;
            }

            public function first()
            {
                return $this->rows[0] ?? null;
            }
        }
    }

    if (!class_exists(Subscription::class)) {
        /** A FluentCart subscription with its meta kept in memory. */
        class Subscription
        {
            /** Saved subscriptions by id, standing in for fct_subscriptions. */
            public static array $table = [];

            public $id;
            public $uuid = '';
            public $status = 'active';
            public $collection_method = 'system';
            public $customer_id = 42;
            public $parent_order_id = 7;
            public $vendor_customer_id = '';
            public $item_name = 'Website plan';
            public $order;
            public $meta = [];
            public $logs = [];
            public $saved = 0;

            public function __construct(array $attributes = [])
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
                if ($this->id) {
                    self::$table[$this->id] = $this;
                }
            }

            public static function query()
            {
                return new OrderTransactionQuery(self::$table);
            }

            public function getMeta($key, $default = null)
            {
                return $this->meta[$key] ?? $default;
            }

            public function updateMeta($key, $value)
            {
                $this->meta[$key] = $value;
            }

            public function deleteMeta($key)
            {
                unset($this->meta[$key]);
            }

            public function save()
            {
                $this->saved++;
                return true;
            }

            public function addLog($title, $description = '', $type = 'info')
            {
                $this->logs[] = [$title, $description, $type];
            }

            public function isSystem(): bool
            {
                return $this->collection_method === 'system';
            }
        }
    }

    if (!class_exists(SubscriptionMeta::class)) {
        /** fct_subscription_meta, read from the in-memory subscriptions' meta (stored as JSON, like FluentCart). */
        class SubscriptionMeta
        {
            public static function query()
            {
                $rows = [];
                foreach (Subscription::$table as $subscription) {
                    foreach ($subscription->meta as $key => $value) {
                        $rows[] = (object) ['subscription_id' => $subscription->id, 'meta_key' => $key, 'meta_value' => json_encode($value)];
                    }
                }
                return new SubscriptionMetaQuery($rows);
            }
        }

        class SubscriptionMetaQuery
        {
            private array $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function where($column, $operator, $value = null)
            {
                if (func_num_args() === 2) {
                    [$operator, $value] = ['=', $operator];
                }
                $this->rows = array_values(array_filter($this->rows, function ($row) use ($column, $operator, $value) {
                    $actual = $row->{$column};
                    switch ($operator) {
                        case '!=':
                            return $actual != $value;
                        case 'like':
                            $pattern = '/^' . str_replace('%', '.*', preg_quote($value, '/')) . '$/s';
                            return (bool) preg_match($pattern, (string) $actual);
                        default:
                            return $actual == $value;
                    }
                }));
                return $this;
            }

            public function exists(): bool
            {
                return $this->rows !== [];
            }
        }
    }

    if (!class_exists(Order::class)) {
        class Order
        {
            /** Orders by id, standing in for fct_orders. */
            public static array $table = [];

            public function __construct(array $attributes = [])
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
                if (isset($this->id)) {
                    self::$table[$this->id] = $this;
                }
            }

            public static function query()
            {
                return new OrderTransactionQuery(self::$table);
            }
        }
    }
}

namespace FluentCart\App\Modules\Subscriptions\Services {
    if (!class_exists(SystemChargeService::class)) {
        /** Records the calls the gateway makes into FluentCart's charge engine. */
        class SystemChargeService
        {
            public static array $calls = [];
            /** What chargeNow() answers. */
            public static $chargeNowResult = ['status' => 'paid', 'message' => 'Paid.'];

            public static function demoteToManual($subscription, string $reason)
            {
                self::$calls[] = ['demoteToManual', $subscription->id, $reason];
                $subscription->collection_method = 'manual';
            }

            public static function clearFailureState($subscription)
            {
                self::$calls[] = ['clearFailureState', $subscription->id];
            }

            public static function chargeNow($invoice, $subscription, $actorId = 0)
            {
                self::$calls[] = ['chargeNow', $invoice->id, $subscription->id, $actorId];
                return self::$chargeNowResult;
            }
        }
    }
}

namespace FluentCart\Api\Resource {
    if (!class_exists(CustomerResource::class)) {
        class CustomerResource
        {
            public static $current = null;

            public static function getCurrentCustomer(bool $createIfNotExists = false): ?object
            {
                return self::$current;
            }
        }
    }
}

namespace FluentCart\App\Services\Payments {
    if (!class_exists(Refund::class)) {
        class Refund
        {
            /** Refunds recordRefund() created, for assertions. */
            public static array $recorded = [];

            // The matching of FluentCart 1.8.0 app/Services/Payments/Refund.php:107-140, without the events.
            public static function createOrRecordRefund($refundData, $parentTransaction)
            {
                $refunds = \FluentCart\App\Models\OrderTransaction::query()
                    ->where('order_id', $parentTransaction->order_id)
                    ->where('transaction_type', 'refund')
                    ->get();

                foreach ($refunds as $refund) {
                    if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                        $refund->wasRecentlyCreated = false;
                        return $refund;
                    }
                    if (!$refund->vendor_charge_id && $refund->total == $refundData['total'] && ($refund->meta['parent_id'] ?? '') == $parentTransaction->id) {
                        $refund->vendor_charge_id = $refundData['vendor_charge_id'];
                        $refund->wasRecentlyCreated = false;
                        $refund->save();
                        return $refund;
                    }
                }

                $refund = new \FluentCart\App\Models\OrderTransaction(array_merge([
                    'order_id'         => $parentTransaction->order_id,
                    'transaction_type' => 'refund',
                    'status'           => 'refunded',
                    'meta'             => ['parent_id' => $parentTransaction->id, 'reason' => $refundData['reason'] ?? ''],
                ], array_diff_key($refundData, ['reason' => 1])));
                $refund->save();
                $refund->wasRecentlyCreated = true;
                PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $refund->total);
                self::$recorded[] = $refund;

                return $refund;
            }
        }
    }

    if (!class_exists(PaymentHelper::class)) {
        class PaymentHelper
        {
            // Same as FluentCart 1.7.0 app/Services/Payments/PaymentHelper.php:151-159.
            public static function updateTransactionRefundedTotal($parentTransaction, $refundedAmount)
            {
                $meta = $parentTransaction->meta;
                $alreadyRefunded = $meta['refunded_total'] ?? 0;
                $meta['refunded_total'] = $alreadyRefunded + $refundedAmount;
                $parentTransaction->meta = $meta;
                $parentTransaction->save();
            }
        }
    }
}
