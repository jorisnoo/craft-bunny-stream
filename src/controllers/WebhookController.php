<?php

namespace Noo\CraftBunnyStream\controllers;

use craft\helpers\Json;
use craft\helpers\Queue;
use craft\web\Controller;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\helpers\WebhookTokenHelper;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

class WebhookController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /**
     * @return bool
     * @throws BadRequestHttpException
     */
    public function actionIndex(): bool
    {
        $this->requirePostRequest();
        $this->requireValidToken();

        try {
            $payload = Json::decode($this->request->getRawBody());
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        $videoId = is_array($payload) ? BunnyStreamFieldAttributes::normalizeVideoId($payload['VideoGuid'] ?? null) : null;
        if ($videoId === null) {
            throw new BadRequestHttpException('A valid VideoGuid is required.');
        }

        Queue::push(new RefreshBunnyStreamMetadataJob(['videoId' => $videoId]));

        return true;
    }

    /**
     * @throws ForbiddenHttpException
     */
    private function requireValidToken(): void
    {
        $expectedToken = BunnyStream::getInstance()->getSettings()->bunnyStreamWebhookToken;
        $providedToken = $this->request->getQueryParam('token');

        if (!WebhookTokenHelper::isValid($expectedToken, $providedToken)) {
            throw new ForbiddenHttpException('Invalid Bunny Stream webhook token.');
        }
    }
}
