<?php

namespace Tests\Unit\Crm;

use Tests\Unit\TestCase;
use WaaSClient\Crm\ContactPlan;

class ContactPlanTest extends TestCase
{
    public function test_merges_sources_by_email_and_the_first_source_gives_the_fields(): void
    {
        $contacts = ContactPlan::merge([
            ['email' => 'Ann@Example.com', 'source' => 'WooCommerce', 'contact_type' => 'customer', 'first_name' => 'Ann', 'phone' => '555', 'status' => null, 'tags' => ['Mug: Past'], 'custom' => ['woocommerce_orders' => 2]],
            ['email' => 'ann@example.com', 'source' => 'MailPoet', 'first_name' => 'Annie', 'last_name' => 'Lee', 'status' => 'subscribed', 'lists' => ['News']],
            ['email' => 'ann@example.com ', 'source' => 'Newsletter', 'status' => 'pending', 'lists' => ['News', 'Offers']],
        ]);

        $this->assertSame(['ann@example.com'], array_keys($contacts));
        $ann = $contacts['ann@example.com'];
        $this->assertSame('Ann', $ann['first_name']);
        $this->assertSame('Lee', $ann['last_name']);
        $this->assertSame('555', $ann['phone']);
        $this->assertSame('customer', $ann['contact_type']);
        $this->assertSame(['WooCommerce', 'MailPoet', 'Newsletter'], $ann['sources']);
        $this->assertSame(['Mug: Past'], $ann['tags']);
        $this->assertSame(['News', 'Offers'], $ann['lists']);
        $this->assertSame(['woocommerce_orders' => 2], $ann['custom']);
        $this->assertSame('subscribed', $ann['status']);
        $this->assertSame('record', $ann['consent']);
    }

    public function test_without_a_consent_record_a_buyer_is_transactional_or_the_chosen_status(): void
    {
        $record = [['email' => 'g@example.com', 'source' => 'WooCommerce', 'status' => null]];

        $this->assertSame('transactional', ContactPlan::merge($record)['g@example.com']['status']);
        $this->assertSame('none', ContactPlan::merge($record)['g@example.com']['consent']);
        $this->assertSame('unsubscribed', ContactPlan::merge($record, 'unsubscribed')['g@example.com']['status']);
    }

    public function test_a_suppression_anywhere_wins(): void
    {
        $this->assertSame('unsubscribed', ContactPlan::status(['subscribed', 'unsubscribed']));
        $this->assertSame('bounced', ContactPlan::status(['MailPoet' => 'bounced', 'Newsletter' => 'subscribed']));
        $this->assertSame('complained', ContactPlan::status(['bounced', 'complained']));
        $this->assertSame('subscribed', ContactPlan::status(['pending', 'subscribed']));
        $this->assertSame('pending', ContactPlan::status(['pending']));
    }

    public function test_existing_contacts_are_never_resubscribed_or_downgraded(): void
    {
        $this->assertNull(ContactPlan::status_for_existing('subscribed', 'unsubscribed'));
        $this->assertNull(ContactPlan::status_for_existing('subscribed', 'bounced'));
        $this->assertNull(ContactPlan::status_for_existing('transactional', 'subscribed'));
        $this->assertNull(ContactPlan::status_for_existing('pending', 'subscribed'));
        $this->assertNull(ContactPlan::status_for_existing('transactional', 'pending'));
        $this->assertNull(ContactPlan::status_for_existing('subscribed', 'subscribed'));
        $this->assertSame('unsubscribed', ContactPlan::status_for_existing('unsubscribed', 'subscribed'));
        $this->assertSame('bounced', ContactPlan::status_for_existing('bounced', 'transactional'));
        $this->assertSame('subscribed', ContactPlan::status_for_existing('subscribed', 'transactional'));
        $this->assertSame('subscribed', ContactPlan::status_for_existing('subscribed', 'pending'));
    }

    public function test_changes_fill_only_empty_fields_and_add_only_missing_tags(): void
    {
        $contact = ContactPlan::merge([[
            'email' => 'b@example.com', 'source' => 'WooCommerce', 'contact_type' => 'customer', 'first_name' => 'Bob', 'last_name' => 'Ray', 'city' => 'Oslo',
            'status' => null, 'tags' => ['Mug: Past', 'Cap: Past'], 'custom' => ['woocommerce_orders' => 3, 'woocommerce_spent' => '30.00'],
        ]])['b@example.com'];
        $row = ['first_name' => 'Robert', 'last_name' => '', 'city' => null, 'status' => 'subscribed', 'contact_type' => 'lead'];

        $changes = ContactPlan::changes($contact, $row, ['Mug: Past', 'Cap: Interested', 'VIP'], [], ['woocommerce_orders' => '3']);

        $this->assertSame(['last_name' => 'Ray', 'city' => 'Oslo', 'contact_type' => 'customer'], $changes['fields']);
        $this->assertNull($changes['status']);
        $this->assertSame(['Cap: Past'], $changes['tags']);
        $this->assertSame(['woocommerce_spent' => '30.00'], $changes['custom']);
        $this->assertSame(['Cap: Interested'], $changes['remove_tags']);
        $this->assertFalse(ContactPlan::is_empty($changes));
    }

    public function test_the_fallback_status_never_changes_an_existing_contact(): void
    {
        $contact = ContactPlan::merge([['email' => 'e@example.com', 'source' => 'WooCommerce', 'status' => null]], 'unsubscribed')['e@example.com'];

        $this->assertNull(ContactPlan::changes($contact, ['status' => 'subscribed'], [], [], [])['status']);
    }

    public function test_a_second_run_has_no_changes(): void
    {
        $contact = ContactPlan::merge([['email' => 'c@example.com', 'source' => 'MailPoet', 'first_name' => 'Cy', 'status' => 'subscribed', 'lists' => ['News']]])['c@example.com'];
        $changes = ContactPlan::changes($contact, ['first_name' => 'Cy', 'status' => 'subscribed', 'contact_type' => 'lead'], [], ['News'], []);

        $this->assertTrue(ContactPlan::is_empty($changes));
    }

    public function test_interest_is_exclusive(): void
    {
        $this->assertSame(['Mug: Interested'], ContactPlan::interested_to_remove(['Mug: Interested', 'Mug: Past', 'Cap: Interested'], ['Mug: Interested', 'Cap: Interested']));
        $this->assertSame([], ContactPlan::interested_to_remove(['Mug: Past'], ['Mug: Past']));
    }

    public function test_past_tag(): void
    {
        $this->assertSame('Hoodie: Past', ContactPlan::past_tag("  Hoodie\n"));
        $this->assertSame('', ContactPlan::past_tag(''));
    }

    public function test_numbers_compare_as_numbers(): void
    {
        $this->assertTrue(ContactPlan::same_value('25', '25.00'));
        $this->assertTrue(ContactPlan::same_value(0, '0.00'));
        $this->assertFalse(ContactPlan::same_value('25', '25.50'));
        $this->assertFalse(ContactPlan::same_value('a', 'b'));
    }
}
