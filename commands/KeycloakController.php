<?php

/**
 * Keycloak Sign-In
 * @link https://github.com/cuzy-app/auth-keycloak
 * @license https://github.com/cuzy-app/auth-keycloak/blob/main/docs/LICENCE.md
 * @author [Marc FARRE](https://marc.fun) for [CUZY.APP](https://www.cuzy.app)
 */

namespace humhub\modules\authKeycloak\commands;

use humhub\modules\authKeycloak\authclient\Keycloak;
use humhub\modules\authKeycloak\components\KeycloakApi;
use humhub\modules\authKeycloak\models\ConfigureForm;
use humhub\modules\authKeycloak\source\KeycloakUserSource;
use humhub\modules\user\models\Auth;
use humhub\modules\user\services\UserSourceService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\console\widgets\Table;
use yii\helpers\Console;

/**
 * Console tools for the Keycloak users
 */
class KeycloakController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'sync-users';

    /**
     * Creates, updates and deactivates the HumHub users from the Keycloak users
     * (also done every hour if "Fetch/Update users automatically" is enabled)
     */
    public function actionSyncUsers()
    {
        $this->stdout("*** Keycloak users sync\n\n");

        if (($error = $this->checkApi()) !== null) {
            $this->stderr('Error: ' . $error . "\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $collection = UserSourceService::getCollection();
        if (!$collection->hasUserSource(KeycloakUserSource::SOURCE_ID)) {
            $this->stderr("Error: the Keycloak user source is disabled (`provideUserSource` module property).\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        /** @var KeycloakUserSource $source */
        $source = $collection->getUserSource(KeycloakUserSource::SOURCE_ID);
        $results = $source->syncUsers();
        if ($results === null) {
            $this->stderr("Error: the sync could not run, see the error log.\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($results as $result => $count) {
            $this->stdout(str_pad(ucfirst($result) . ':', 12) . $count . "\n");
        }
        $this->stdout("\nKeycloak users sync completed!\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists the Keycloak users and their linked HumHub user
     */
    public function actionListUsers()
    {
        $this->stdout("*** Keycloak users\n\n");

        if (($error = $this->checkApi()) !== null) {
            $this->stderr('Error: ' . $error . "\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $keycloakUsers = (new KeycloakApi())->getAllUsers();
        if ($keycloakUsers === null) {
            $this->stderr("Error: the Keycloak users could not be retrieved, see the error log.\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rows = [];
        foreach ($keycloakUsers as $keycloakUser) {
            $auth = Auth::findOne(['source' => Keycloak::DEFAULT_NAME, 'source_id' => (string)($keycloakUser['id'] ?? '')]);
            $rows[] = [
                $keycloakUser['id'] ?? '---',
                $keycloakUser['username'] ?? '---',
                $keycloakUser['email'] ?? '---',
                !empty($keycloakUser['enabled']) ? 'Yes' : 'No',
                $auth?->user?->username ?? '---',
            ];
        }

        echo Table::widget(['headers' => ['Keycloak ID', 'Username', 'Email', 'Enabled', 'HumHub user'], 'rows' => $rows]);
        $this->stdout("\n" . count($rows) . " Keycloak users.\n\n");

        return ExitCode::OK;
    }

    /**
     * @return string|null error message
     */
    private function checkApi(): ?string
    {
        $config = new ConfigureForm();
        if (!$config->enabled) {
            return 'the Keycloak auth client is not enabled.';
        }
        if (!$config->hasApiParams()) {
            return 'the Keycloak API user is not configured.';
        }
        if (!(new KeycloakApi())->isConnected()) {
            return 'the authentication to the Keycloak API failed, see the error log.';
        }
        return null;
    }
}
