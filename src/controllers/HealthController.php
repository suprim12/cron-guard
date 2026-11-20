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
use craft\helpers\App;
use craft\web\Controller;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Checker;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The health URL for uptime monitors, with the token as `?cronGuardToken=` (Craft keeps `?token=` for its own) or an
 * `X-Cron-Guard-Token` header: 200 while every command is fine, 503 when one isn’t.
 * It also runs the check, so alerts still go out on a site nobody visits.
 */
class HealthController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $token = (string)App::parseEnv(Plugin::getInstance()->getSettings()->healthToken);
        $given = $this->request->getQueryParam('cronGuardToken') ?? $this->request->getHeaders()->get('X-Cron-Guard-Token', '');
        $given = is_string($given) ? $given : '';
        if ($token === '' || !hash_equals($token, $given)) {
            throw new NotFoundHttpException();
        }

        $checker = Plugin::getInstance()->checker;
        $checker->checkIfDue();
        $statuses = $checker->statuses();
        $problems = array_values(array_filter($statuses, fn($s) => in_array($s['state'], Checker::PROBLEMS, true)));

        $response = $this->asJson([
            'status' => $problems ? 'problem' : 'ok',
            'problems' => array_map(fn($s) => ['command' => $s['command'], 'state' => $s['state'], 'message' => $s['message']], $problems),
            'commands' => array_map(fn($s) => [
                'command' => $s['command'],
                'state' => $s['state'],
                'lastStartedAt' => $s['lastStart'] ? gmdate('c', $s['lastStart']) : null,
            ], $statuses),
        ]);
        $response->setStatusCode($problems ? 503 : 200);
        $this->response->setNoCacheHeaders();

        return $response;
    }
}
