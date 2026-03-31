<?php

namespace Noo\CraftBunnyStream\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use craft\fieldlayoutelements\Tip;
use craft\helpers\Html;
use craft\web\View;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use yii\db\Schema;

class BunnyStreamField extends Field implements PreviewableFieldInterface
{
    public static function displayName(): string
    {
        return Craft::t('bunny-stream', 'Bunny Stream');
    }

    public function getTableAttributeHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof BunnyStreamFieldAttributes || !$value->bunnyStreamVideoId) {
            $label = Craft::t('bunny-stream', 'Video does not have a Bunny Stream asset');
            $content = '❌';
        } else {
            $bunnyStreamData = $value->bunnyStreamMetaData ?? [];
            $status = $bunnyStreamData['status'] ?? null;
            if ((int)$status !== 3) {
                $label = Craft::t('bunny-stream', 'Bunny Stream video is being processed. Stay tuned!');
                $content = '⏳';
            } else {
                $label = Craft::t('bunny-stream', 'Bunny Stream video is ready to play!');
                $content = '👍';
            }
        }
        return Html::tag('span', $content, [
            'role' => 'img',
            'title' => $label,
            'aria' => [
                'label' => $label,
            ],
        ]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), []);
    }

    public static function dbType(): array|string|null
    {
        return [
            'bunnyStreamVideoId' => Schema::TYPE_STRING,
            'bunnyStreamMetaData' => Schema::TYPE_TEXT,
        ];
    }

    public function useFieldset(): bool
    {
        return true;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof BunnyStreamFieldAttributes) {
            return $value;
        }
        return Craft::createObject([
            'class' => BunnyStreamFieldAttributes::class,
            ...($value ?? []),
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (!$element instanceof Asset || $element->kind !== Asset::KIND_VIDEO) {
            $warningTip = new Tip([
                'style' => Tip::STYLE_WARNING,
                'tip' => Craft::t('bunny-stream', 'The Bunny Stream field is only designed to work on video assets.'),
            ]);
            return $warningTip->formHtml();
        }

        return Craft::$app->getView()->renderTemplate(
            'bunny-stream/_components/bunnystream-field-input.twig',
            ['asset' => $element],
            View::TEMPLATE_MODE_CP
        );
    }

    public function getElementValidationRules(): array
    {
        return [];
    }


    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof BunnyStreamFieldAttributes) {
            return $value->bunnyStreamVideoId ?: '';
        }
        return '';
    }
}
