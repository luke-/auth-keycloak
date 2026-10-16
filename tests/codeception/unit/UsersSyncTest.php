<?php

/**
 * Keycloak Sign-In
 * @link https://github.com/cuzy-app/auth-keycloak
 * @license https://github.com/cuzy-app/auth-keycloak/blob/main/docs/LICENCE.md
 */

namespace humhub\modules\authKeycloak\tests\codeception\unit;

use humhub\modules\authKeycloak\components\KeycloakApi;
use humhub\modules\authKeycloak\source\KeycloakUserSource;
use humhub\modules\authKeycloak\tests\codeception\_support\KeycloakTestCase;
use humhub\modules\authKeycloak\tests\codeception\_support\KeycloakTestServer;
use humhub\modules\user\models\User;
use Yii;

class UsersSyncTest extends KeycloakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureModule([
            'syncUsers' => true,
            'attributeMapping' => "username=preferred_username\nphone_work=phoneNumber\ncity=locality",
        ]);
    }

    public function testCreateUpdateAndDeactivate()
    {
        $countsBefore = $this->getUserSource()->countUsersByStatus();
        $keycloakUserId = KeycloakTestServer::createUser([
            'username' => 'kc.alice',
            'email' => 'alice@keycloak.test',
            'firstName' => 'Alice',
            'lastName' => 'Keycloak',
            'attributes' => ['phoneNumber' => ['+49 111'], 'locality' => ['Berlin', 'Munich']],
        ]);

        $results = $this->getUserSource()->syncUsers();
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_CREATED]);
        $user = User::findOne(['email' => 'alice@keycloak.test']);
        $this->assertNotNull($user);
        $this->assertSame(KeycloakUserSource::SOURCE_ID, $user->user_source);
        $this->assertSame('kc.alice', $user->username);
        $this->assertSame('Alice', $user->profile->firstname);
        $this->assertSame('+49 111', $user->profile->phone_work);
        $this->assertSame('Berlin', $user->profile->city);

        // Attribute changed and attribute removed on Keycloak
        KeycloakTestServer::updateUser($keycloakUserId, ['attributes' => ['phoneNumber' => ['+49 222']]]);
        $results = $this->getUserSource()->syncUsers();
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_UPDATED]);
        $user->refresh();
        $user->profile->refresh();
        $this->assertSame('+49 222', $user->profile->phone_work);
        $this->assertEmpty($user->profile->city);

        // Disabled, then enabled again on Keycloak
        KeycloakTestServer::updateUser($keycloakUserId, ['enabled' => false]);
        $results = $this->getUserSource()->syncUsers();
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_DISABLED]);
        $user->refresh();
        $this->assertSame(User::STATUS_DEACTIVATED, (int)$user->status);

        KeycloakTestServer::updateUser($keycloakUserId, ['enabled' => true]);
        $results = $this->getUserSource()->syncUsers();
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_ENABLED]);
        $user->refresh();
        $this->assertSame(User::STATUS_ENABLED, (int)$user->status);

        // Removed on Keycloak (another user remains, otherwise the sync is aborted)
        KeycloakTestServer::createUser(['username' => 'kc.bob', 'email' => 'bob@keycloak.test', 'firstName' => 'Bob', 'lastName' => 'Keycloak']);
        KeycloakTestServer::request('DELETE', '/users/' . $keycloakUserId);
        $source = $this->getUserSource();
        $results = $source->syncUsers();
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_DISABLED]);
        $user->refresh();
        $this->assertSame(User::STATUS_DEACTIVATED, (int)$user->status);

        $this->assertSame(1, $source->lastSyncKeycloakUsersCount);
        // Alice (deactivated) and Bob (enabled)
        $counts = $source->countUsersByStatus();
        $this->assertSame(array_sum($countsBefore) + 2, array_sum($counts));
        $this->assertSame(1, $counts[User::STATUS_ENABLED]);
    }

    public function testNoDeactivationIfTheListIsIncomplete()
    {
        $keycloakUserId = KeycloakTestServer::createUser(['username' => 'kc.alice', 'email' => 'alice@keycloak.test', 'firstName' => 'Alice', 'lastName' => 'Keycloak']);
        KeycloakTestServer::createUser(['username' => 'kc.bob', 'email' => 'bob@keycloak.test', 'firstName' => 'Bob', 'lastName' => 'Keycloak']);
        $this->getUserSource()->syncUsers();
        KeycloakTestServer::request('DELETE', '/users/' . $keycloakUserId);

        // Keycloak reports more users than listed (e.g. user federation importing users while listing)
        Yii::$container->set(KeycloakApi::class, new class () extends KeycloakApi {
            public function getUserCount(): ?int
            {
                return 1000;
            }
        });
        try {
            static::logInitialize();
            $results = $this->getUserSource()->syncUsers();
        } finally {
            Yii::$container->clear(KeycloakApi::class);
        }

        $this->assertSame(0, $results[KeycloakUserSource::SYNC_DISABLED]);
        $this->assertSame(User::STATUS_ENABLED, (int)User::findOne(['email' => 'alice@keycloak.test'])->status);
        static::assertLogRegexCount(KeycloakApi::MAX_USERS_LIST_PASSES, '/users listed, but the realm has 1000 users/');
        static::assertLog('Users sync: the list of Keycloak users may be incomplete, so no HumHub user is deactivated for not being found on Keycloak');
    }

    public function testUsersWithExistingEmailAreSkipped()
    {
        // The email of the fixture user "User1", owned by the local user source
        $keycloakUserId = KeycloakTestServer::createUser(['username' => 'kc.user1', 'email' => 'user1@example.com', 'firstName' => 'User', 'lastName' => 'One']);

        static::logInitialize();
        $results = $this->getUserSource()->syncUsers();
        $this->assertSame(0, $results[KeycloakUserSource::SYNC_CREATED]);
        $this->assertSame(1, $results[KeycloakUserSource::SYNC_SKIPPED]);
        $this->assertSame('local', User::findOne(['email' => 'user1@example.com'])->user_source);

        // The skipped users are logged with the reason
        static::assertLog(
            "Users sync: 1 Keycloak users skipped (Keycloak ID, username, first name, last name: reason):\n"
            . $keycloakUserId . ' kc.user1 User One: email user1@example.com already used by the HumHub user User1 (ID 2, user source "local")',
        );
    }
}
