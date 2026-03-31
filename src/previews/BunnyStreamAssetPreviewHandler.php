<?php

namespace Noo\CraftBunnyStream\previews;

use craft\base\AssetPreviewHandler;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;

class BunnyStreamAssetPreviewHandler extends AssetPreviewHandler
{
    public function getPreviewHtml(array $variables = []): string
    {
        $embedUrl = BunnyStreamHelper::getDirectUrl($this->asset) . '?autoplay=false&preload=metadata';

        return <<<HTML
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; padding: 24px;">
                <iframe
                    src="{$embedUrl}"
                    loading="lazy"
                    style="border: none; width: 100%; max-width: 960px; aspect-ratio: 16/9; border-radius: 4px;"
                    allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture"
                    allowfullscreen
                ></iframe>
            </div>
        HTML;
    }
}
