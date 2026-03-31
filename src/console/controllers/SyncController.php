<?php

namespace Noo\CraftBunnyStream\console\controllers;

use craft\console\Controller;
use craft\elements\Asset;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Throwable;
use yii\console\ExitCode;

class SyncController extends Controller
{
    public $defaultAction = 'metadata';

    public function actionMetadata(): int
    {
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Asset::find()->kind(Asset::KIND_VIDEO)->each() as $asset) {
            $videoId = BunnyStreamHelper::getBunnyStreamVideoId($asset);

            if (!$videoId) {
                $skipped++;
                continue;
            }

            $this->stdout("  Syncing #{$asset->id} ({$asset->filename}) ... ");

            try {
                BunnyStreamHelper::updateBunnyStreamData($asset);
                $this->stdout("done\n");
                $synced++;
            } catch (Throwable $e) {
                $this->stderr("failed: {$e->getMessage()}\n");
                $failed++;
            }
        }

        $this->stdout("\nDone. Synced: {$synced}, Skipped: {$skipped}, Failed: {$failed}\n");

        return ExitCode::OK;
    }
}
