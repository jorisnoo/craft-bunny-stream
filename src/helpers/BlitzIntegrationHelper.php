<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\base\NestedElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;

class BlitzIntegrationHelper
{
    public static function isAvailable(): bool
    {
        return self::getBlitz() !== null;
    }

    private static function getBlitz(): ?Blitz
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('blitz');
        return $plugin instanceof Blitz ? $plugin : null;
    }

    public static function preventCachingCurrentRequest(): void
    {
        self::getBlitz()?->generateCache->options->cachingEnabled(false);
    }

    public static function refreshCachesForAsset(Asset $asset): void
    {
        $blitz = self::getBlitz();
        if (!$blitz) {
            return;
        }

        $entries = Craft::$app->getElements()->createElementQuery(Entry::class)
            ->relatedTo(['targetElement' => $asset])
            ->status(Entry::STATUS_LIVE)
            ->siteId('*')
            ->all();

        $siteUris = [];
        foreach ($entries as $entry) {
            $page = $entry;
            while ($page !== null) {
                if ($page->uri !== null) {
                    $key = "{$page->siteId}:{$page->uri}";
                    $siteUris[$key] ??= new SiteUriModel([
                        'siteId' => $page->siteId,
                        'uri' => $page->uri,
                    ]);
                }
                $page = $page instanceof NestedElementInterface ? $page->getOwner() : null;
            }
        }

        if (!$siteUris) {
            return;
        }

        $blitz->refreshCache->refreshSiteUris(array_values($siteUris));
    }
}
