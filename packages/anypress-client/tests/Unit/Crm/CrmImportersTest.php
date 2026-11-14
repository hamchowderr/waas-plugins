<?php

namespace FluentCrm\App\Services\CrmMigrator {
    // FluentCRM's importer parent, enough to load the AnyPress importers without FluentCRM.
    if (!class_exists(BaseMigrator::class)) {
        abstract class BaseMigrator
        {
        }
    }
}

namespace Tests\Unit\Crm {

    use AnyPressClient\Crm\Importers\BrevoMigrator;
use PHPUnit\Framework\Attributes\DataProvider;
    use AnyPressClient\Crm\Importers\CrmImporters;
    use AnyPressClient\Crm\Importers\MailerLiteMigrator;
    use Brain\Monkey;
    use Tests\Unit\TestCase;

    class CrmImportersTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Monkey\Functions\when('sanitize_text_field')->alias(fn ($t) => trim(strip_tags((string) $t)));
        }

        public function test_brevo_contact_keeps_names_date_and_blocklist(): void
        {
            $data = BrevoMigrator::contact('bob@anypress.example', ['FIRSTNAME' => 'Bob', 'LASTNAME' => 'Ruiz'], true, '2024-03-02T10:00:00.000+02:00', ['phone' => '+1555']);

            $this->assertSame('unsubscribed', $data['status']);
            $this->assertSame('Bob', $data['first_name']);
            $this->assertSame('Ruiz', $data['last_name']);
            $this->assertSame('+1555', $data['phone']);
            $this->assertSame('2024-03-02 08:00:00', $data['created_at']);
            $this->assertSame('Brevo', $data['source']);
        }

        public function test_brevo_french_attribute_names_and_empty_values(): void
        {
            $data = BrevoMigrator::contact('a@anypress.example', ['PRENOM' => 'Anne', 'NOM' => ''], false, 'not a date', []);

            $this->assertSame('subscribed', $data['status']);
            $this->assertSame('Anne', $data['first_name']);
            $this->assertArrayNotHasKey('last_name', $data);
            $this->assertArrayNotHasKey('created_at', $data);
        }

        #[DataProvider('mailerlite_statuses')]
        public function test_mailerlite_status_maps_to_fluentcrm(string $remote, string $local): void
        {
            $data = MailerLiteMigrator::contact('x@anypress.example', ['status' => $remote, 'fields' => ['name' => 'X']], []);

            $this->assertSame($local, $data['status']);
        }

        public static function mailerlite_statuses(): array
        {
            return [['active', 'subscribed'], ['unsubscribed', 'unsubscribed'], ['unconfirmed', 'pending'], ['bounced', 'bounced'], ['junk', 'complained'], ['something-new', 'subscribed']];
        }

        public function test_mailerlite_contact_keeps_fields_ip_and_sign_up_date(): void
        {
            $data = MailerLiteMigrator::contact('d@anypress.example', [
                'status' => 'active', 'ip_address' => '203.0.113.1', 'subscribed_at' => '2023-05-02 09:30:00', 'created_at' => '2023-05-01 09:00:00',
                'fields' => ['name' => 'Dina', 'last_name' => 'Lee', 'city' => null],
            ], ['city' => 'Oakland']);

            $this->assertSame(['Dina', 'Lee', 'Oakland', '203.0.113.1', '2023-05-02 09:30:00'], [$data['first_name'], $data['last_name'], $data['city'], $data['ip'], $data['created_at']]);
        }

        public function test_driver_filter_keeps_fluentcrm_own_and_adds_ours(): void
        {
            $this->assertSame('Their\\Class', CrmImporters::driver('Their\\Class', 'anypress_brevo'));
            $this->assertSame(BrevoMigrator::class, CrmImporters::driver(null, 'anypress_brevo'));
            $this->assertSame(MailerLiteMigrator::class, CrmImporters::driver(null, 'anypress_mailerlite'));
            $this->assertNull(CrmImporters::driver(null, 'Drip'));
        }
    }
}
