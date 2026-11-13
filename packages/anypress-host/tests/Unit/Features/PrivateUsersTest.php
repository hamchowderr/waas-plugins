<?php

namespace Tests\Unit\Features;

use AnyPressHost\Features\PrivateUsers;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;

class PrivateUsersTest extends TestCase
{
    private function request(string $route): object
    {
        return new class ($route) {
            public function __construct(private string $route)
            {
            }

            public function get_route(): string
            {
                return $this->route;
            }
        };
    }

    public function test_visitors_cannot_list_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('__')->returnArg();
        Functions\when('rest_authorization_required_code')->justReturn(401);

        foreach (['/wp/v2/users', '/wp/v2/users/1'] as $route) {
            $result = PrivateUsers::rest(null, null, $this->request($route));
            $this->assertInstanceOf(\WP_Error::class, $result, $route);
        }
        $this->assertNull(PrivateUsers::rest(null, null, $this->request('/wp/v2/posts')));
        $this->assertNull(PrivateUsers::rest(null, null, $this->request('/wp/v2/usersettings')));
    }

    public function test_signed_in_people_still_can(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        $this->assertNull(PrivateUsers::rest(null, null, $this->request('/wp/v2/users')));
    }

    public function test_users_sitemap_is_off(): void
    {
        $this->assertFalse(PrivateUsers::sitemap('provider', 'users'));
        $this->assertSame('provider', PrivateUsers::sitemap('provider', 'posts'));
    }
}
