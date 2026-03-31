<?php

namespace Noo\CraftBunnyStream\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use craft\fieldlayoutelements\Tip;
use craft\helpers\Html;
use craft\helpers\Json;
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
        if (!$value instanceof BunnyStreamFieldAttributes || !$value->videoId) {
            $label = Craft::t('bunny-stream', 'Video does not have a Bunny Stream asset');
            $content = '❌';
        } else {
            $metaData = $value->metaData ?? [];
            $status = $metaData['status'] ?? null;
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

    public static function dbType(): array|string|null
    {
        return [
            'videoId' => Schema::TYPE_STRING,
            'metaData' => Schema::TYPE_TEXT,
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

        if (is_string($value)) {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : ['videoId' => $value];
        }

        if (!is_array($value)) {
            $value = [];
        }

        // Handle old column names
        if (isset($value['bunnyStreamVideoId'])) {
            $value['videoId'] = $value['bunnyStreamVideoId'];
            unset($value['bunnyStreamVideoId']);
        }
        if (isset($value['bunnyStreamMetaData'])) {
            $value['metaData'] = $value['bunnyStreamMetaData'];
            unset($value['bunnyStreamMetaData']);
        }

        if (isset($value['metaData']) && is_string($value['metaData'])) {
            $value['metaData'] = Json::decodeIfJson($value['metaData']);
        }

        return Craft::createObject([
            'class' => BunnyStreamFieldAttributes::class,
            ...$value,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): ?array
    {
        if (!$value instanceof BunnyStreamFieldAttributes) {
            return null;
        }

        return [
            'videoId' => $value->videoId,
            'metaData' => $value->metaData ? Json::encode($value->metaData) : null,
        ];
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
            return $value->videoId ?: '';
        }
        return '';
    }
}
