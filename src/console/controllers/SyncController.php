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

    /**
     * Whether to also create Bunny Stream videos for assets that don't have one yet.
     */
    public bool $bootstrap = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'metadata') {
            $options[] = 'bootstrap';
        }

        return $options;
    }

    public function actionMetadata(): int
    {
        $synced = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Asset::find()->kind(Asset::KIND_VIDEO)->each() as $asset) {
            $videoId = BunnyStreamHelper::getBunnyStreamVideoId($asset);

            if (!$videoId) {
                if (!$this->bootstrap) {
                    $skipped++;
                    continue;
                }

                $this->stdout("  Creating Bunny Stream video for #{$asset->id} ({$asset->filename}) ... ");

                try {
                    if (BunnyStreamHelper::updateOrCreateBunnyStreamAsset($asset)) {
                        $this->stdout("done\n");
                        $created++;
                    } else {
                        $this->stdout("skipped (no Bunny Stream field on volume)\n");
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    $this->stderr("failed: {$e->getMessage()}\n");
                    $failed++;
                }

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

        $this->stdout("\nDone. Synced: {$synced}, Created: {$created}, Skipped: {$skipped}, Failed: {$failed}\n");

        return ExitCode::OK;
    }
}
