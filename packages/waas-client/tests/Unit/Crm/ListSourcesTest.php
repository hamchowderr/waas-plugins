<?php

namespace Tests\Unit\Crm;

use Tests\Unit\TestCase;
use WaaSClient\Crm\MailPoetSubscribers;
use WaaSClient\Crm\NewsletterSubscribers;

class ListSourcesTest extends TestCase
{
    public function test_mailpoet_statuses_come_across_faithfully(): void
    {
        $this->assertSame('subscribed', MailPoetSubscribers::status('subscribed'));
        $this->assertSame('pending', MailPoetSubscribers::status('unconfirmed'));
        $this->assertSame('unsubscribed', MailPoetSubscribers::status('unsubscribed'));
        $this->assertSame('bounced', MailPoetSubscribers::status('bounced'));
        $this->assertSame('unsubscribed', MailPoetSubscribers::status('inactive'));
    }

    public function test_mailpoet_lists_trash_and_automatic_segments(): void
    {
        $result = MailPoetSubscribers::from_rows(
            [
                ['id' => 1, 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => '', 'status' => 'subscribed', 'subscribed_ip' => '1.2.3.4', 'confirmed_ip' => null, 'created_at' => '2025-01-01 00:00:00', 'deleted_at' => null],
                ['id' => 2, 'email' => 'b@example.com', 'first_name' => '', 'last_name' => '', 'status' => 'bounced', 'subscribed_ip' => null, 'confirmed_ip' => null, 'created_at' => null, 'deleted_at' => null],
                ['id' => 3, 'email' => 't@example.com', 'first_name' => '', 'last_name' => '', 'status' => 'subscribed', 'subscribed_ip' => null, 'confirmed_ip' => null, 'created_at' => null, 'deleted_at' => '2025-02-01 00:00:00'],
            ],
            [
                ['id' => 1, 'name' => 'WordPress Users', 'type' => 'wp_users', 'deleted_at' => null],
                ['id' => 3, 'name' => 'Newsletter mailing list', 'type' => 'default', 'deleted_at' => null],
                ['id' => 4, 'name' => 'Old list', 'type' => 'default', 'deleted_at' => '2025-01-01 00:00:00'],
            ],
            [
                ['subscriber_id' => 1, 'segment_id' => 3, 'status' => 'subscribed'],
                ['subscriber_id' => 1, 'segment_id' => 1, 'status' => 'subscribed'],
                ['subscriber_id' => 2, 'segment_id' => 3, 'status' => 'unsubscribed'],
            ]
        );

        $this->assertCount(2, $result['records']);
        $this->assertSame(['Newsletter mailing list'], $result['records'][0]['lists']);
        $this->assertSame('1.2.3.4', $result['records'][0]['ip']);
        $this->assertSame([], $result['records'][1]['lists']);
        $this->assertSame('bounced', $result['records'][1]['status']);
        $this->assertSame(1, $result['stats']['trashed']);
        $this->assertSame(['Newsletter mailing list' => 1], $result['stats']['lists']);
        $this->assertSame(['WordPress Users (wp_users)'], $result['stats']['not_copied']);
    }

    public function test_newsletter_statuses_and_lists(): void
    {
        $result = NewsletterSubscribers::from_rows(
            [
                ['email' => 'c@example.com', 'name' => 'C', 'surname' => 'D', 'status' => 'C', 'list_1' => 1, 'list_2' => 0, 'country' => 'IT'],
                ['email' => 'u@example.com', 'name' => '', 'surname' => '', 'status' => 'U', 'list_1' => 0, 'list_2' => 1],
                ['email' => 'p@example.com', 'name' => '', 'surname' => '', 'status' => 'P'],
                ['email' => 'x@example.com', 'name' => '', 'surname' => '', 'status' => 'T', 'list_1' => 1],
            ],
            ['list_1' => 'Weekly', 'list_2' => 'Offers', 'list_3' => '']
        );

        $this->assertSame(['subscribed', 'unsubscribed', 'complained'], array_column($result['records'], 'status'));
        $this->assertSame(['Weekly'], $result['records'][0]['lists']);
        $this->assertSame('D', $result['records'][0]['last_name']);
        $this->assertSame(1, $result['stats']['skipped']);
        $this->assertSame(['Weekly' => 1, 'Offers' => 1], $result['stats']['lists']);
        $this->assertSame('pending', NewsletterSubscribers::status('S'));
    }
}
