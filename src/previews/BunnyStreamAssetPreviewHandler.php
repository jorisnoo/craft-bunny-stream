<?php

namespace Noo\CraftBunnyStream\previews;

use craft\base\AssetPreviewHandler;
use craft\helpers\Html;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;

class BunnyStreamAssetPreviewHandler extends AssetPreviewHandler
{
    public function getPreviewHtml(array $variables = []): string
    {
        $iframe = BunnyStreamHelper::getIframeHtml($this->asset, ['preload' => 'metadata'], [
            'style' => 'border: none; width: 100%; max-width: 960px; aspect-ratio: 16/9; border-radius: 4px;',
        ]);

        return Html::tag('div', $iframe ?? '', [
            'style' => 'display: flex; align-items: center; justify-content: center; height: 100%; padding: 24px;',
        ]);
    }
}
