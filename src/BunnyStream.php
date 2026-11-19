<?php

namespace Noo\CraftBunnyStream;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\events\AssetPreviewEvent;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\DefineRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Fields;
use craft\web\UrlManager;
use Noo\CraftBunnyStream\behaviors\BunnyStreamAssetBehavior;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Noo\CraftBunnyStream\jobs\CreateBunnyStreamVideoJob;
use Noo\CraftBunnyStream\jobs\DeleteBunnyStreamVideoJob;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use Noo\CraftBunnyStream\models\Settings;
use Noo\CraftBunnyStream\previews\BunnyStreamAssetPreviewHandler;
use WeakMap;
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
    public string $schemaVersion = '0.0.4';

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
        $logTarget->logFile = Craft::getAlias('@storage/logs/bunny-stream.log');
        $logTarget->setLevels(['error', 'warning', 'info']);
        $logTarget->categories = [__NAMESPACE__ . '\\*'];
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

        $replaced = new WeakMap();
        $deleted = new WeakMap();

        // A new physical asset must not inherit the original asset's remote link.
        Event::on(Asset::class, Element::EVENT_BEFORE_SAVE, static function(ModelEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;
            if ($asset->duplicateOf && !$asset->getIsDerivative() && $asset->id !== $asset->duplicateOf->id) {
                $field = BunnyStreamHelper::getBunnyStreamFieldForAsset($asset);
                if ($field) {
                    $asset->setFieldValue($field->handle, null);
                }
            }
        });

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_PROPAGATE,
            static function(ModelEvent $event) use ($replaced) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (isset($replaced[$asset]) && $asset->getScenario() === Asset::SCENARIO_REPLACE) {
                    foreach ($replaced[$asset] as $job) {
                        $job->schedule();
                    }
                    unset($replaced[$asset]);
                }

                if (
                    $asset->resaving ||
                    $asset->getIsDerivative() ||
                    !BunnyStreamHelper::getBunnyStreamFieldForAsset($asset) ||
                    BunnyStreamHelper::getBunnyStreamVideoId($asset)
                ) {
                    return;
                }

                CreateBunnyStreamVideoJob::schedule($asset->id, $asset->siteId);
            }
        );

        // Make sure the Bunny Stream attributes are wiped when assets are replaced
        Event::on(
            Assets::class,
            Assets::EVENT_BEFORE_REPLACE_ASSET,
            static function(ReplaceAssetEvent $event) use ($replaced) {
                $asset = $event->asset;

                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                // The new video may have completely different framing, so a
                // focal point from the old video would point to the wrong spot.
                $asset->setFocalPoint(null);

                $field = BunnyStreamHelper::getBunnyStreamFieldForAsset($asset);
                if ($field) {
                    $replaced[$asset] = self::deletionJobs($asset);
                    // Let Craft's replacement save persist the cleared link.
                    // A worker uploading the previous file must not publish over it.
                    $asset->setFieldValue($field->handle, ['generation' => StringHelper::UUID()]);
                }
            }
        );

        // Capture server-written values while the row exists; never resave a deleted asset.
        Event::on(Asset::class, Element::EVENT_BEFORE_DELETE, static function(Event $event) use ($deleted) {
            /** @var Asset $asset */
            $asset = $event->sender;
            if (($asset->hardDelete || !$asset->keepFileOnDelete) && BunnyStreamHelper::getBunnyStreamFieldForAsset($asset)) {
                $deleted[$asset] = self::deletionJobs($asset);
            }
        });

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) use ($deleted) {
                $asset = $event->sender;
                foreach ($deleted[$asset] ?? [] as $job) {
                    $job->schedule();
                }
                unset($deleted[$asset]);
            }
        );

        Event::on(Asset::class, Element::EVENT_AFTER_RESTORE, static function(Event $event) {
            /** @var Asset $asset */
            $asset = $event->sender;
            if (!BunnyStreamHelper::getBunnyStreamFieldForAsset($asset) || $asset->getIsDerivative()) {
                return;
            }
            $videoId = BunnyStreamHelper::getBunnyStreamVideoId($asset);
            if (!$videoId) {
                CreateBunnyStreamVideoJob::schedule($asset->id, $asset->siteId);
            } elseif (!BunnyStreamHelper::getBunnyStreamStatus($asset)?->isTerminal()) {
                BunnyStreamHelper::afterCommit(static fn() => RefreshBunnyStreamMetadataJob::schedule($asset->id, videoId: $videoId, siteId: $asset->siteId));
            }
        });

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

                $iframe = BunnyStreamHelper::getIframeHtml($asset, ['preload' => 'metadata'], [
                    'style' => 'border: none; width: 100%; aspect-ratio: 16/9; display: block;',
                ]);
                if ($iframe === '') {
                    return;
                }

                $player = Html::tag('div', $iframe, [
                    'class' => 'meta',
                    'style' => 'padding: 0; overflow: hidden;',
                ]) . '<style>.preview-thumb-container { display: none !important; }</style>';

                $event->html = $player . $event->html;
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_DEFINE_THUMB_URL,
            static function(DefineAssetThumbUrlEvent $event) {
                $asset = $event->asset;

                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                $url = BunnyStreamHelper::getThumbnailUrl($asset);

                if ($url) {
                    $event->url = $url;
                }
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

    /** @return DeleteBunnyStreamVideoJob[] Signed receipts from persisted links, never from posted fields. */
    private static function deletionJobs(Asset $asset): array
    {
        /** @var AssetQuery $query */
        $query = Craft::$app->getElements()->createElementQuery(Asset::class);
        $jobs = [];
        $statuses = [Element::STATUS_ENABLED, Element::STATUS_DISABLED, Element::STATUS_ARCHIVED];
        foreach ($query->id($asset->id)->site('*')->status($statuses)->trashed(null)->all() as $persisted) {
            $job = DeleteBunnyStreamVideoJob::forPersistedLink($persisted);
            if ($job) {
                $jobs[$job->videoId] = $job;
            }
        }
        return $jobs;
    }
}
