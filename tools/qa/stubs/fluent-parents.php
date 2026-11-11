<?php
/**
 * The other plugins' classes the AnyPress plugins extend, for PHPStan only. Those plugins aren't installed here and
 * publish no stubs, so each parent is an empty class whose members PHPStan treats as unknown (magic) instead of missing.
 */

namespace FluentCart\App\Modules\Integrations {
    abstract class BaseIntegrationManager
    {
        public function __construct(...$args) {}
        public function __call($name, $arguments) {}
        public function __get($name) {}
        public static function __callStatic($name, $arguments) {}
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core {
    abstract class AbstractPaymentGateway
    {
        public function __construct(...$args) {}
        public function __call($name, $arguments) {}
        public function __get($name) {}
        public static function __callStatic($name, $arguments) {}
    }

    abstract class BaseGatewaySettings
    {
        public function __construct(...$args) {}
        public function __call($name, $arguments) {}
        public function __get($name) {}
        public static function __callStatic($name, $arguments) {}
    }
}

namespace FluentForm\App\Services\Migrator\Classes {
    abstract class BaseMigratorStub
    {
        public function __construct(...$args) {}
        public function __call($name, $arguments) {}
        public function __get($name) {}
        public static function __callStatic($name, $arguments) {}
    }

    class ContactForm7Migrator extends BaseMigratorStub {}
    class NinjaFormsMigrator extends BaseMigratorStub {}
    class WpFormsMigrator extends BaseMigratorStub {}
    class GravityFormsMigrator extends BaseMigratorStub {}
}

namespace FluentCrm\App\Services\CrmMigrator {
    abstract class BaseMigrator
    {
        public function __construct(...$args) {}
        public function __call($name, $arguments) {}
        public function __get($name) {}
        public static function __callStatic($name, $arguments) {}
    }
}
