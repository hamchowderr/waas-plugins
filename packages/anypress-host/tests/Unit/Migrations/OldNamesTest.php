<?php

namespace Tests\Unit\Migrations;

use Brain\Monkey\Functions;
use AnyPressHost\Core\SecretBox;
use AnyPressHost\Migrations\OldNames;
use Tests\Unit\TestCase;

class OldNamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('salt-for-tests');
    }

    public function test_names_move_to_the_anypress_prefix(): void
    {
        $this->assertSame('anypress_api_key', OldNames::rename('waas_api_key'));
        $this->assertSame('_anypress_tenant_private_key', OldNames::rename('_waas_tenant_private_key'));
        $this->assertSame('_is_anypress_product', OldNames::rename('_is_waas_product'));
        $this->assertSame('anypress_poll_store_status', OldNames::rename('waas_poll_store_status'));
        $this->assertSame('fluent_cart_settings', OldNames::rename('fluent_cart_settings'));
    }

    /** A secret sealed the way 2.10 did it opens again under the new names. */
    public function test_a_secret_sealed_under_the_old_names_is_sealed_again(): void
    {
        $key = sodium_crypto_generichash('waas-host-webhook-secret|salt-for-tests', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $old = 'waasenc:v1:' . base64_encode($nonce . sodium_crypto_secretbox('whsec-123', $nonce, $key));

        $new = OldNames::reseal_value($old, 'webhook');

        $this->assertTrue(SecretBox::is_sealed($new));
        $this->assertSame('whsec-123', SecretBox::open($new, 'anypress-host-webhook-secret'));
    }

    /** A storefront moved before this release keeps its site types, saved under their old hyphenated name. */
    public function test_a_storefront_moved_before_gets_its_site_types_back(): void
    {
        $roles = (object) ['website' => (object) ['title' => 'Website']];
        $options = ['anypress_old_names_moved' => '1', 'waas-host-roles-options' => $roles];
        Functions\when('get_option')->alias(fn ($name, $default = false) => array_key_exists($name, $options) ? $options[$name] : $default);
        Functions\expect('add_option')->once()->with('anypress-host-roles-options', $roles);
        Functions\expect('delete_option')->once()->with('waas-host-roles-options');
        Functions\expect('update_option')->once()->with(OldNames::DONE_OPTION, OldNames::DONE);

        OldNames::maybe_run();
    }

    public function test_site_types_saved_under_the_new_name_win(): void
    {
        $options = ['waas-host-roles-options' => ['old'], 'anypress-host-roles-options' => ['new']];
        Functions\when('get_option')->alias(fn ($name, $default = false) => array_key_exists($name, $options) ? $options[$name] : $default);
        Functions\expect('add_option')->never();
        Functions\expect('delete_option')->once()->with('waas-host-roles-options');

        OldNames::move_named_options();
    }

    public function test_a_damaged_old_secret_comes_back_empty(): void
    {
        $this->assertSame('', OldNames::reseal_value('waasenc:v1:not-base64!', 'private-key'));
    }
}
