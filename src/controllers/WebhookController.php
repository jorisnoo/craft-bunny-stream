<?php

namespace jorisnoo\bunnystream\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Json;
use craft\web\Controller;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\helpers\BunnyStreamHelper;
use yii\web\BadRequestHttpException;

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

        $webhookJson = $this->request->getRawBody();

        if (empty($webhookJson)) {
            throw new BadRequestHttpException();
        }

        $webhookData = Json::decode($webhookJson);

        $videoId = $webhookData['VideoGuid'] ?? null;

        Craft::info("Bunny Stream webhook triggered for video \"$videoId\"", __METHOD__);

        if (!$videoId) {
            Craft::info("Webhook failed, no Bunny asset ID found in payload for event \"$webhookJson\"", __METHOD__);
            return false;
        }

        // Get the asset
        // To quote Værsågod: This is a bit awkward, because we have to go via the MuxMate fields
        // (we can't know which field to query on, in cases where there are multiple volumes w/ multiple different MuxMate fields in their layouts)
        $asset = null;
        $bunnyStreamFields = Craft::$app->getFields()->getFieldsByType(BunnyStreamField::class, 'global');
        foreach ($bunnyStreamFields as $bunnyStreamField) {
            $bunnyStreamFieldHandle = $bunnyStreamField->handle;
            $asset = Asset::find()
                ->kind(Asset::KIND_VIDEO)
                ->$bunnyStreamFieldHandle($videoId)
                ->one();
            if ($asset) {
                break;
            }
        }

        if (!$asset) {
            return true;
        }

        BunnyStreamHelper::updateBunnyStreamData($asset);

        return true;
    }

}
