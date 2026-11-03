<?php

// The parts of FluentCart 1.7 that our FluentCart classes extend, so they load without FluentCart.

namespace FluentCart\App\Modules\Integrations;

if (!class_exists(BaseIntegrationManager::class)) {
    abstract class BaseIntegrationManager
    {
        protected $title = '';
        protected $description = '';
        protected $integrationKey = '';
        protected $priority = 10;
        public $disableGlobalSettings = false;
        public $scopes = ['global', 'product'];

        public function __construct($title, $integrationKey, $priority = 10)
        {
            $this->title = $title;
            $this->integrationKey = $integrationKey;
            $this->priority = $priority;
        }

        public function register()
        {
        }

        public function actionFields(): array
        {
            return ['key' => 'event_trigger'];
        }

        abstract public function processAction($order, $eventData);

        abstract public function getIntegrationDefaults($settings);

        abstract public function getSettingsFields($settings, $args = []);
    }
}

namespace FluentCart\App\Models;

if (!class_exists(StubQuery::class)) {
    /** Enough of FluentCart's query builder for FluentCartStore's feed and variation lookups, over in-memory rows. */
    class StubQuery
    {
        private array $rows;
        private array $order = [];

        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        public function where($column, $value)
        {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => ($row->$column ?? null) == $value));
            return $this;
        }

        public function orderBy($column, $direction = 'ASC')
        {
            $this->order[] = [$column, strtoupper($direction)];
            return $this;
        }

        public function get(): array
        {
            $rows = $this->rows;
            usort($rows, function ($a, $b) {
                foreach ($this->order as [$column, $direction]) {
                    $cmp = ($a->$column ?? null) <=> ($b->$column ?? null);
                    if ($cmp !== 0) {
                        return $direction === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
            return $rows;
        }

        public function first()
        {
            return $this->get()[0] ?? null;
        }

        public function exists(): bool
        {
            return $this->rows !== [];
        }
    }
}

if (!class_exists(ProductMeta::class)) {
    class ProductMeta
    {
        /** @var object[] rows: object_id, object_type, meta_key, meta_value */
        public static array $rows = [];

        public static function query(): StubQuery
        {
            return new StubQuery(self::$rows);
        }
    }
}

if (!class_exists(ProductVariation::class)) {
    class ProductVariation
    {
        /** @var object[] rows: id, post_id, serial_index */
        public static array $rows = [];

        public static function query(): StubQuery
        {
            return new StubQuery(self::$rows);
        }
    }
}
