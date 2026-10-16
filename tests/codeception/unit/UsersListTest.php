<?php

/**
 * Keycloak Sign-In
 * @link https://github.com/cuzy-app/auth-keycloak
 * @license https://github.com/cuzy-app/auth-keycloak/blob/main/docs/LICENCE.md
 */

namespace humhub\modules\authKeycloak\tests\codeception\unit;

use humhub\modules\authKeycloak\components\KeycloakApi;
use tests\codeception\_support\HumHubDbTestCase;

class UsersListTest extends HumHubDbTestCase
{
    /**
     * With a user federation, Keycloak may return pages having less than the maximum results before the last one
     */
    public function testShortPagesBeforeTheLastOne()
    {
        // 250 users, the first page has 1 user less (returned again in the second page)
        $users = array_map(static fn($i) => ['id' => 'user-' . $i], range(1, 250));
        $api = $this->getApi($users, 250, static fn($first, $max) => $first === 0
            ? array_slice($users, 0, $max - 1)
            : array_slice($users, $first - 1, $max + 1));

        $list = $api->getAllUsers($isComplete);

        $this->assertTrue($isComplete);
        $this->assertCount(250, $list);
        $this->assertSame($users, $list);
    }

    public function testIncompleteList()
    {
        $users = array_map(static fn($i) => ['id' => 'user-' . $i], range(1, 150));
        $api = $this->getApi($users, 200, static fn($first, $max) => array_slice($users, $first, $max));

        $list = $api->getAllUsers($isComplete);

        $this->assertFalse($isComplete);
        $this->assertCount(150, $list);
    }

    private function getApi(array $users, int $count, callable $getUsers): KeycloakApi
    {
        $api = new class ($count) extends KeycloakApi {
            public function __construct(private int $count)
            {
                // No connection to Keycloak
            }

            public function getUserCount(): ?int
            {
                return $this->count;
            }
        };
        $api->realm = ['realm' => 'test'];
        $api->api = new class ($getUsers) {
            public function __construct(private $getUsers)
            {
            }

            public function getUsers(array $args): array
            {
                return ($this->getUsers)($args['first'], $args['max']);
            }
        };
        return $api;
    }
}
