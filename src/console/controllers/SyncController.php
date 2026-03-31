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
        $assets = Asset::find()
            ->kind(Asset::KIND_VIDEO)
            ->all();

        $total = count($assets);
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        $this->stdout("Found {$total} video assets.\n");

        foreach ($assets as $asset) {
            $videoId = BunnyStreamHelper::getBunnyStreamVideoId($asset);

            if (!$videoId) {
                $this->stdout("  Skipping #{$asset->id} ({$asset->filename}) — no video ID\n");
                $skipped++;
                continue;
            }

            $this->stdout("  Syncing #{$asset->id} ({$asset->filename}) — {$videoId} ... ");

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
