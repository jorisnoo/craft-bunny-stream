<?php

namespace Noo\CraftBunnyStream;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\AssetPreviewEvent;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\DefineRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Fields;
use craft\console\Application as ConsoleApplication;
use craft\web\UrlManager;
use Noo\CraftBunnyStream\behaviors\BunnyStreamAssetBehavior;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Noo\CraftBunnyStream\models\Settings;
use Noo\CraftBunnyStream\previews\BunnyStreamAssetPreviewHandler;

use yii\base\Event;
use yii\base\ModelEvent;
use yii\log\FileTarget;

/**
 * Bunny Stream plugin
 *
 * @method static BunnyStream getInstance()
 * @method Settings getSettings()
 */
class BunnyStream extends Plugin
{
    public string $schemaVersion = '0.0.3';

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'Noo\CraftBunnyStream\console\controllers';
        }

        Craft::$app->onInit(function() {
            $this->defineLogTarget();
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    private function defineLogTarget(): void
    {
        $logTarget = new FileTarget();
        $logTarget->logFile = Craft::getAlias('@storage/logs/bunny-stream-' . date('Y-m-d') . '.log');
        $logTarget->setLevels(['error', 'warning', 'info']);
        $logTarget->categories = ['bunny-stream'];
        $logTarget->maxFileSize = 10240;
        $logTarget->maxLogFiles = 30;
        $logTarget->logVars = [];

        Craft::$app->log->targets[] = $logTarget;
    }

    private function attachEventHandlers(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BunnyStreamField::class;
        });

        // Register asset behavior
        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_BEHAVIORS,
            static function(DefineBehaviorsEvent $event) {
                $event->behaviors['bunnyStreamAssetBehavior'] = [
                    'class' => BunnyStreamAssetBehavior::class,
                ];
            }
        );

        // Create Bunny Stream Videos when videos are saved
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_PROPAGATE,
            static function(ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (
                    $asset->resaving ||
                    $asset->kind !== Asset::KIND_VIDEO ||
                    BunnyStreamHelper::getBunnyStreamVideoId($asset)
                ) {
                    return;
                }

                BunnyStreamHelper::updateOrCreateBunnyStreamAsset($asset);
            }
        );

        // Make sure the Bunny Stream attributes are wiped when assets are replaced
        Event::on(
            Assets::class,
            Assets::EVENT_BEFORE_REPLACE_ASSET,
            static function(ReplaceAssetEvent $event) {
                $asset = $event->asset;

                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                BunnyStreamHelper::deleteBunnyStreamVideo($asset);
            }
        );

        // Delete Bunny Stream video when videos are deleted
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) {
                $asset = $event->sender;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                BunnyStreamHelper::deleteBunnyStreamVideo($asset);
            }
        );

        // Register Bunny Stream preview handler for video assets
        Event::on(
            Assets::class,
            Assets::EVENT_REGISTER_PREVIEW_HANDLER,
            static function(AssetPreviewEvent $event) {
                if (
                    $event->asset->kind === Asset::KIND_VIDEO &&
                    BunnyStreamHelper::getBunnyStreamVideoId($event->asset)
                ) {
                    $event->previewHandler = new BunnyStreamAssetPreviewHandler($event->asset);
                }
            }
        );

        // Show Bunny Stream player in the asset editor sidebar
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            static function(DefineHtmlEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (
                    $asset->kind !== Asset::KIND_VIDEO ||
                    !BunnyStreamHelper::getBunnyStreamVideoId($asset)
                ) {
                    return;
                }

                $embedUrl = BunnyStreamHelper::getDirectUrl($asset) . '?autoplay=false&preload=metadata';
                $player = <<<HTML
                    <div class="meta" style="padding: 0; overflow: hidden;">
                        <iframe
                            src="{$embedUrl}"
                            loading="lazy"
                            style="border: none; width: 100%; aspect-ratio: 16/9; display: block;"
                            allow="accelerometer; gyroscope; encrypted-media; picture-in-picture"
                            allowfullscreen
                        ></iframe>
                    </div>
                    <style>
                        .preview-thumb-container { display: none !important; }
                    </style>
                HTML;

                $event->html = $player . $event->html;
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_DEFINE_THUMB_URL,
            function(DefineAssetThumbUrlEvent $event) {
                $asset = $event->asset;
                if (
                    $asset->kind !== Asset::KIND_VIDEO ||
                    !BunnyStreamHelper::getBunnyStreamVideoId($asset) ||
                    BunnyStreamHelper::getBunnyStreamStatus($asset) !== 'finished'
                ) {
                    return;
                }

                $url = BunnyStreamHelper::getThumbnailUrl($asset);

                if (empty($url)) {
                    return;
                }

                $event->url = $url;
            }
        );

        // Prevent more than one BunnyStream field from being added to a field layout
        // Also prevent BunnyStream fields from being added to non-asset field layouts
        Event::on(
            FieldLayout::class,
            Model::EVENT_DEFINE_RULES,
            static function(DefineRulesEvent $event) {
                /** @var FieldLayout $fieldLayout */
                $fieldLayout = $event->sender;
                $event->rules[] = [
                    'customFields', static function() use ($fieldLayout) {
                        $customFields = $fieldLayout->getCustomFields();
                        $hasBunnyStreamField = false;
                        foreach ($customFields as $customField) {
                            if ($customField instanceof BunnyStreamField) {
                                if ($hasBunnyStreamField) {
                                    $fieldLayout->addError('fields', Craft::t('bunny-stream', 'Only one BunnyStream field can be added to a single field layout.'));
                                    break;
                                }
                                $hasBunnyStreamField = true;
                            }
                        }
                        if ($hasBunnyStreamField && $fieldLayout->type !== Asset::class) {
                            $fieldLayout->addError('fields', Craft::t('bunny-stream', 'BunnyStream fields are only supported for assets.'));
                        }
                    },
                ];
            }
        );

        // Add a route to the webhooks controller
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['bunnystream/webhook'] = 'bunny-stream/webhook';
            }
        );
    }
}
