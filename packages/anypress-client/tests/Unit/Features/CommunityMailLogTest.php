<?php

namespace Tests\Unit\Features;

use Tests\Unit\TestCase;
use AnyPressClient\Features\CommunityMailLog;

/**
 * FluentCommunity's notification emails stay out of FluentSMTP's log; every other email keeps FluentSMTP's answer.
 */
class CommunityMailLogTest extends TestCase
{
    public function testFindsFluentCommunitysMailerAmongTheCallers(): void
    {
        $this->assertTrue(CommunityMailLog::sentByCommunity([
            ['class' => 'FluentMail\App\Services\Mailer\BaseHandler', 'function' => 'shouldBeLogged'],
            ['function' => 'wp_mail'],
            ['class' => CommunityMailLog::MAILER, 'function' => 'send'],
        ]));
        $this->assertFalse(CommunityMailLog::sentByCommunity([
            ['class' => 'FluentMail\App\Services\Mailer\BaseHandler', 'function' => 'shouldBeLogged'],
            ['function' => 'wp_mail'],
            ['class' => 'FluentCart\App\Services\Email', 'function' => 'send'],
        ]));
    }

    public function testKeepsFluentSmtpsAnswerForOtherEmails(): void
    {
        $log = new CommunityMailLog();
        $this->assertTrue($log->willLog(true));
        $this->assertFalse($log->willLog(false));
    }
}
