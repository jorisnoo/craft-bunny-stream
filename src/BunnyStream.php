<?php

namespace jorisnoo\bunnystream;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\AssetPreviewEvent;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineElementInnerHtmlEvent;
use craft\events\DefineRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Fields;
use craft\web\Response;
use craft\web\UrlManager;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use jorisnoo\bunnystream\behaviors\BunnyStreamAssetBehavior;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\helpers\BunnyStreamHelper;
use jorisnoo\bunnystream\models\Settings;

use yii\base\Event;
use yii\base\ModelEvent;
use yii\web\Response as BaseResponse;

/**
 * Bunny Stream plugin
 *
 * @method static BunnyStream getInstance()
 * @method Settings getSettings()
 */
class BunnyStream extends Plugin
{
    public string $schemaVersion = '0.0.1';

    public function init(): void
    {
        parent::init();

        // Register a custom log target, keeping the format as simple as possible.
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => '_bunny-stream',
            'categories' => ['_bunny-stream', 'jorisnoo\\bunnystream\\*'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
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

                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                if (
                    $asset->resaving ||
                    BunnyStreamHelper::getBunnyStreamVideoId($asset)
                ) {
                    BunnyStreamHelper::updateBunnyStreamData($asset);

                    return;
                }

                BunnyStreamHelper::updateOrCreateBunnyStreamVideo($asset);
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

                BunnyStreamHelper::deleteBunnyStreamAttributesForAsset($asset);
            }
        );

        // Delete Bunny Stream video when videos are deleted
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) {
                /** Asset $asset */
                $asset = $event->sender;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }

                BunnyStreamHelper::deleteBunnyStreamAttributesForAsset($asset);
            }
        );

//        Event::on(
//            Cp::class,
//            Cp::EVENT_DEFINE_ELEMENT_INNER_HTML,
//            static function(DefineElementInnerHtmlEvent $event) {
//                $element = $event->element;
//                if (
//                    !$element instanceof Asset ||
//                    $element->kind !== Asset::KIND_VIDEO ||
//                    $event->size !== 'large' ||
//                    !MuxMateHelper::getMuxPlaybackId($element) ||
//                    MuxMateHelper::getMuxStatus($element) !== 'ready'
//                ) {
//                    return;
//                }
//                $event->innerHtml = str_replace('class="elementthumb', 'class="elementthumb muxvideo', $event->innerHtml);
//                $css = <<< CSS
//                    .elementthumb.muxvideo::before {
//                        content: "VIDEO";
//                        display: block;
//                        position: absolute;
//                        background-color: black;
//                        color: white;
//                        left: 50%;
//                        top: 50%;
//                        transform: translate(-50%, -50%);
//                        pointer-events: none;
//                        font-size: 11px;
//                        border-radius: 3px;
//                        padding: 0 4px;
//                    }
//                CSS;
//                \Craft::$app->getView()->registerCss($css);
//            }
//        );
//
//        // Add video preview handler
//        Event::on(
//            Assets::class,
//            Assets::EVENT_REGISTER_PREVIEW_HANDLER,
//            static function(AssetPreviewEvent $event) {
//                $asset = $event->asset;
//                if ($asset->kind !== Asset::KIND_VIDEO) {
//                    return;
//                }
//                $event->previewHandler = new MuxVideoPreview($asset);
//            }
//        );

        // Add a route to the webhooks controller
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['bunnystream/webhook'] = '_bunny-stream/webhook';
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
                                    $fieldLayout->addError('fields', Craft::t('_bunny-stream', 'Only one BunnyStream field can be added to a single field layout.'));
                                    break;
                                }
                                $hasBunnyStreamField = true;
                            }
                        }
                        if ($hasBunnyStreamField && $fieldLayout->type !== Asset::class) {
                            $fieldLayout->addError('fields', Craft::t('_bunny-stream', 'BunnyStream fields are only supported for assets.'));
                        }
                    }
                ];
            }
        );
    }
}
