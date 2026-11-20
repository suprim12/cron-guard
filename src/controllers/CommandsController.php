<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\controllers;

use Craft;
use craft\helpers\Db;
use craft\web\Controller;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Runs;
use yii\web\Response;

/**
 * The utility’s buttons: run the check, mute, forget, and the settings page’s test alert.
 */
class CommandsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePostRequest();
        $this->requirePermission('utility:cron-guard');

        return true;
    }

    public function actionCheck(): Response
    {
        $checker = Plugin::getInstance()->checker;
        $checker->resetDue();
        $alert = $checker->check();
        if ($alert === false) {
            $this->setFailFlash(Craft::t('cron-guard', 'Another check is running. Try again in a moment.'));
        } else {
            $this->setSuccessFlash($alert ? Craft::t('cron-guard', 'Checked, and sent an alert.') : Craft::t('cron-guard', 'Checked: nothing new to send.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionMute(): Response
    {
        $muted = (bool)$this->request->getRequiredBodyParam('muted');
        Db::update(Runs::TABLE_COMMANDS, ['muted' => $muted, 'alertState' => null, 'alertedAt' => null], ['id' => $this->request->getRequiredBodyParam('id')]);
        Craft::$app->getCache()->delete('cron-guard:badge');
        $this->setSuccessFlash($muted ? Craft::t('cron-guard', 'Command muted.') : Craft::t('cron-guard', 'Command watched again.'));

        return $this->redirectToPostedUrl();
    }

    public function actionForget(): Response
    {
        Db::delete(Runs::TABLE_COMMANDS, ['id' => $this->request->getRequiredBodyParam('id')]);
        Craft::$app->getCache()->delete('cron-guard:badge');
        $this->setSuccessFlash(Craft::t('cron-guard', 'Command and its runs forgotten.'));

        return $this->redirect('utilities/cron-guard');
    }

    public function actionTestAlert(): Response
    {
        $this->requireAdmin(false);
        $results = Plugin::getInstance()->alerts->sendTest();
        if (!$results) {
            $this->setFailFlash(Craft::t('cron-guard', 'Save an email address or webhook first.'));
        } elseif (in_array(true, $results, true) && count(array_filter($results, fn($r) => $r !== true)) === 0) {
            $this->setSuccessFlash(Craft::t('cron-guard', 'Test alert sent to {channels}.', ['channels' => implode(', ', array_keys($results))]));
        } else {
            $this->setFailFlash(implode(' ', array_map(fn($channel, $result) => $result === true ? "$channel: sent." : "$channel: $result", array_keys($results), $results)));
        }

        return $this->redirectToPostedUrl();
    }
}
