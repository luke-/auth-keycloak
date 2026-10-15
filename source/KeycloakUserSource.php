<?php

/**
 * Keycloak Sign-In
 * @link https://github.com/cuzy-app/auth-keycloak
 * @license https://github.com/cuzy-app/auth-keycloak/blob/master/docs/LICENCE.md
 * @author [Marc FARRE](https://marc.fun) for [CUZY.APP](https://www.cuzy.app)
 */

namespace humhub\modules\authKeycloak\source;

use humhub\modules\authKeycloak\authclient\Keycloak;
use humhub\modules\authKeycloak\components\KeycloakApi;
use humhub\modules\authKeycloak\models\ConfigureForm;
use humhub\modules\authKeycloak\Module;
use humhub\modules\user\models\Auth;
use humhub\modules\user\models\forms\Registration;
use humhub\modules\user\models\User;
use humhub\modules\user\services\UserSourceService;
use humhub\modules\user\source\BaseUserSource;
use humhub\modules\user\source\UserSourceInterface;
use Yii;
use yii\helpers\VarDumper;

/**
 * KeycloakUserSource owns users provisioned through the Keycloak auth client.
 *
 * Replaces the pre-1.19 `PrimaryClient` marker that set `user.auth_mode = 'Keycloak'`.
 * Registered by {@see \humhub\modules\authKeycloak\Events::onUserSourceCollectionSet()}
 * when {@see Module::$provideUserSource} is enabled (default: true).
 *
 * @since 1.6.0
 */
class KeycloakUserSource extends BaseUserSource
{
    public const SOURCE_ID = Keycloak::DEFAULT_NAME;

    /**
     * Results of a user sync, see {@see syncUsers()}
     */
    public const SYNC_CREATED = 'created';
    public const SYNC_UPDATED = 'updated';
    public const SYNC_UNCHANGED = 'unchanged';
    public const SYNC_ENABLED = 'enabled';
    public const SYNC_DISABLED = 'disabled';
    public const SYNC_SKIPPED = 'skipped';
    public const SYNC_FAILED = 'failed';

    public function init()
    {
        parent::init();

        if ($this->id === '') {
            $this->id = self::SOURCE_ID;
        }
        if ($this->allowedAuthClientIds === []) {
            $this->allowedAuthClientIds = [self::SOURCE_ID];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title !== '' ? $this->title : 'Keycloak';
    }

    /**
     * Managed attributes are derived from the existing module settings so
     * pre-1.19 sync configuration is preserved verbatim:
     *  - `updateHumhubEmailFromBrokerEmail`    → `email` is locked & synced
     *  - `updateHumhubUsernameFromBrokerUsername` → `username` is locked & synced
     *
     * Admins who previously disabled auto-sync keep the same behaviour and
     * the affected fields stay editable in the admin UI.
     *
     * With the `syncUsers` setting, the first and last names are also synced (hourly and on sign in).
     */
    public function getManagedAttributes(): array
    {
        $module = $this->getKeycloakModule();
        if ($module === null) {
            return [];
        }

        $attrs = [];
        if ((bool) $module->settings->get('updateHumhubEmailFromBrokerEmail')) {
            $attrs[] = 'email';
        }
        if ((bool) $module->settings->get('updateHumhubUsernameFromBrokerUsername')) {
            $attrs[] = 'username';
        }
        if ((bool) $module->settings->get('syncUsers')) {
            $attrs[] = 'firstname';
            $attrs[] = 'lastname';
        }

        return $attrs;
    }

    public function requiresApproval(?string $authClientId = null): bool
    {
        if ($authClientId !== null && in_array($authClientId, $this->trustedAuthClientIds, true)) {
            return false;
        }

        /** @var \humhub\modules\user\Module $userModule */
        $userModule = Yii::$app->getModule('user');
        return (bool) $userModule->settings->get('auth.needApproval');
    }

    public function getUsernameStrategy(): string
    {
        return UserSourceInterface::USERNAME_AUTO_GENERATE;
    }

    /**
     * Creates a HumHub user with `user_source = 'Keycloak'`.
     *
     * Note: the user_auth row is written by the auth client's
     * {@see Keycloak::createOrUpdateAuthRecord()} (which also stores
     * `keycloak_sid`). It is NOT created here.
     */
    public function createUser(array $attributes): ?User
    {
        return $this->registerUser($attributes, $this->requiresApproval(self::SOURCE_ID));
    }

    /**
     * @param bool $requiresApproval users created by the sync are not approved by an admin, as for LDAP
     */
    private function registerUser(array $attributes, bool $requiresApproval): ?User
    {
        $registration = $this->buildRegistration($attributes, $requiresApproval);
        if ($registration === null) {
            return null;
        }

        $registration->getUser()->user_source = $this->getId();

        if (!$registration->register()) {
            Yii::warning(
                'KeycloakUserSource: could not create user. Errors: '
                . VarDumper::dumpAsString($registration->getErrors()),
                'auth-keycloak',
            );
            return null;
        }

        return $registration->getUser();
    }

    private function buildRegistration(array $attributes, bool $requiresApproval): ?Registration
    {
        $registration = new Registration(enableEmailField: true, enablePasswordForm: false);
        $registration->enableUserApproval = $requiresApproval;

        unset(
            $attributes['id'],
            $attributes['guid'],
            $attributes['contentcontainer_id'],
            $attributes['user_source'],
            $attributes['status'],
        );

        // Users created by the sync cannot fill a registration form, so the username must be valid and unique
        $username = $this->getUsernameResolver()->resolve($attributes, $this->getUsernameStrategy());
        if ($username === null) {
            return null;
        }
        $attributes['username'] = $username;

        $registration->getUser()->setAttributes($attributes, false);
        $registration->getProfile()->setAttributes($attributes, false);
        $registration->getGroupUser()->setAttributes($attributes, false);
        $registration->setModels();

        return $registration;
    }

    /**
     * Full users sync: creates, updates, re-enables and deactivates the HumHub users from the Keycloak users.
     * Called by {@see \humhub\modules\authKeycloak\jobs\UsersFullSync} and the `auth-keycloak/sync-users` console command.
     *
     * Only users owned by this source are updated or deactivated. Keycloak users whose email is already used
     * by another HumHub account are skipped (they are linked on their first sign in with Keycloak, if allowed).
     *
     * @return array|null number of users per result (SYNC_* constants), null if the sync could not run
     */
    public function syncUsers(): ?array
    {
        $authClient = Yii::$app->authClientCollection->hasClient(Keycloak::DEFAULT_NAME)
            ? Yii::$app->authClientCollection->getClient(Keycloak::DEFAULT_NAME)
            : null;
        if (!$authClient instanceof Keycloak) {
            Yii::error('Users sync aborted: the Keycloak auth client is not enabled', 'auth-keycloak');
            return null;
        }

        // Abort on any error: with a partial list, missing users would be deactivated
        $keycloakUsers = Yii::createObject(KeycloakApi::class)->getAllUsers();
        if ($keycloakUsers === null) {
            Yii::error('Users sync aborted: the Keycloak users could not be retrieved', 'auth-keycloak');
            return null;
        }
        if ($keycloakUsers === []) {
            Yii::error('Users sync aborted: no users were returned by Keycloak', 'auth-keycloak');
            return null;
        }

        $results = array_fill_keys([
            self::SYNC_CREATED,
            self::SYNC_UPDATED,
            self::SYNC_UNCHANGED,
            self::SYNC_ENABLED,
            self::SYNC_DISABLED,
            self::SYNC_SKIPPED,
            self::SYNC_FAILED,
        ], 0);
        $apiUsername = mb_strtolower(trim((string)(new ConfigureForm())->apiUsername));
        $syncedUserIds = [];

        foreach ($keycloakUsers as $keycloakUser) {
            if (empty($keycloakUser['id'])) {
                continue;
            }
            $attributes = $authClient->normalizeApiUserAttributes($keycloakUser);
            $isEnabledOnKeycloak = (bool)($keycloakUser['enabled'] ?? true);

            $user = $this->findUser($attributes);
            if ($user !== null) {
                $syncedUserIds[$user->id] = true;
                $results[$this->syncUser($user, $attributes, $isEnabledOnKeycloak)]++;
                continue;
            }

            // Do not create accounts for disabled users, the API user, service accounts and already used emails
            if (
                !$isEnabledOnKeycloak
                || mb_strtolower((string)($keycloakUser['username'] ?? '')) === $apiUsername
                || !empty($keycloakUser['serviceAccountClientId'])
                || (!empty($attributes['email']) && User::find()->where(['email' => $attributes['email']])->exists())
            ) {
                $results[self::SYNC_SKIPPED]++;
                continue;
            }

            $user = $this->registerUser($attributes, false);
            if ($user === null) {
                Yii::warning('Users sync: could not create the HumHub user for the Keycloak user ID ' . $keycloakUser['id'], 'auth-keycloak');
                $results[self::SYNC_FAILED]++;
                continue;
            }
            $this->addAuth($user, $attributes['id']);
            $syncedUserIds[$user->id] = true;
            $results[self::SYNC_CREATED]++;
        }

        // Deactivate users removed from Keycloak
        $query = User::find()->where(['user_source' => $this->getId(), 'status' => User::STATUS_ENABLED]);
        foreach ($query->each() as $user) {
            /** @var User $user */
            if (!isset($syncedUserIds[$user->id]) && (new UserSourceService($user))->deleteUser()) {
                Yii::warning('Users sync: deactivated user ' . $user->username . ' (' . $user->id . '), not found on Keycloak', 'auth-keycloak');
                $results[self::SYNC_DISABLED]++;
            }
        }

        Yii::info('Users sync done: ' . VarDumper::dumpAsString($results), 'auth-keycloak');

        return $results;
    }

    /**
     * Finds the HumHub user linked to the Keycloak user.
     * A user of this source not linked yet (e.g. created before the Auth record) is linked by email.
     *
     * @param array $attributes normalized Keycloak user attributes
     * @return User|null
     */
    public function findUser(array $attributes): ?User
    {
        $auth = Auth::findOne(['source' => Keycloak::DEFAULT_NAME, 'source_id' => (string)$attributes['id']]);
        if ($auth !== null && $auth->user !== null) {
            return $auth->user;
        }

        // The email is missing if not verified on Keycloak and only verified emails are trusted
        if (empty($attributes['email'])) {
            return null;
        }
        $user = User::findOne(['email' => $attributes['email'], 'user_source' => $this->getId()]);
        if (
            $user === null
            // Another Keycloak user is already linked to this account
            || Auth::find()->where(['source' => Keycloak::DEFAULT_NAME, 'user_id' => $user->id])->exists()
        ) {
            return null;
        }

        $this->addAuth($user, $attributes['id']);
        return $user;
    }

    /**
     * @return string sync result (SYNC_* constant)
     */
    private function syncUser(User $user, array $attributes, bool $isEnabledOnKeycloak): string
    {
        // Users owned by another source (e.g. local users who also sign in with Keycloak) are not managed
        if (
            $user->user_source !== $this->getId()
            || $user->status === User::STATUS_SOFT_DELETED
        ) {
            return self::SYNC_SKIPPED;
        }

        $service = new UserSourceService($user);
        $managedValuesBefore = $this->getManagedValues($user);
        $isUpdated = $service->updateUser($attributes);
        if (!$isUpdated) {
            // Discard the invalid values, so that the status can be saved
            $user->refresh();
        }

        // Even if the attributes could not be updated (e.g. email already used), the status must be synced
        if (!$isEnabledOnKeycloak && $user->status === User::STATUS_ENABLED) {
            return $service->deleteUser() ? self::SYNC_DISABLED : self::SYNC_FAILED;
        }
        if ($isEnabledOnKeycloak && $user->status === User::STATUS_DEACTIVATED) {
            return $this->enableUser($user) ? self::SYNC_ENABLED : self::SYNC_FAILED;
        }
        if (!$isUpdated) {
            return self::SYNC_FAILED;
        }

        return $managedValuesBefore === $this->getManagedValues($user) ? self::SYNC_UNCHANGED : self::SYNC_UPDATED;
    }

    private function getManagedValues(User $user): array
    {
        $values = [];
        foreach ($this->getManagedAttributes() as $attribute) {
            $values[$attribute] = $user->hasAttribute($attribute)
                ? $user->getAttribute($attribute)
                : $user->profile?->getAttribute($attribute);
        }
        return $values;
    }

    private function addAuth(User $user, string $keycloakUserId): void
    {
        $auth = new Auth([
            'user_id' => $user->id,
            'source' => Keycloak::DEFAULT_NAME,
            'source_id' => $keycloakUserId,
        ]);
        if (!$auth->save()) {
            Yii::warning(
                'Users sync: could not link the user ID ' . $user->id . ' to the Keycloak user ID ' . $keycloakUserId
                . '. Errors: ' . VarDumper::dumpAsString($auth->getErrors()),
                'auth-keycloak',
            );
        }
    }

    private function getKeycloakModule(): ?Module
    {
        /** @var Module|null $module */
        $module = Yii::$app->getModule('auth-keycloak');
        return $module;
    }
}
