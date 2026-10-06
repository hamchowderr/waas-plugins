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
