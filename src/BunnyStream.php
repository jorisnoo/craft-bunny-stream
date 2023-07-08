<?php

namespace jorisnoo\bunnystream;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineRulesEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Fields;
use jorisnoo\bunnystream\behaviors\BunnyStreamAssetBehavior;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\helpers\BunnyStreamHelper;
use jorisnoo\bunnystream\models\Settings;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;

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
                if (
                    $asset->resaving ||
                    $asset->kind !== Asset::KIND_VIDEO ||
                    BunnyStreamHelper::getBunnyStreamVideoId($asset)
                ) {
                    return;
                }

                BunnyStreamHelper::updateOrCreateBunnyStreamVideo($asset);
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
