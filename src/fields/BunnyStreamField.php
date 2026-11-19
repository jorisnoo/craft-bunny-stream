<?php

namespace Noo\CraftBunnyStream\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use craft\errors\InvalidFieldException;
use craft\fieldlayoutelements\Tip;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\View;
use Noo\CraftBunnyStream\enums\VideoStatus;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use yii\db\Schema;

class BunnyStreamField extends Field implements PreviewableFieldInterface
{
    public static function displayName(): string
    {
        return Craft::t('bunny-stream', 'Bunny Stream');
    }

    public static function supportedTranslationMethods(): array
    {
        // Every site represents the same physical file and must share its Bunny link.
        return [self::TRANSLATION_METHOD_NONE];
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof BunnyStreamFieldAttributes || !$value->videoId) {
            $label = Craft::t('bunny-stream', 'Video does not have a Bunny Stream asset');
            $content = '❌';
        } else {
            $status = VideoStatus::tryFrom((int)($value->metaData['status'] ?? -1));
            if ($status === VideoStatus::Finished) {
                $label = Craft::t('bunny-stream', 'Bunny Stream video is ready to play!');
                $content = '👍';
            } else {
                $label = Craft::t('bunny-stream', 'Bunny Stream video is being processed. Stay tuned!');
                $content = '⏳';
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
            'ownershipProof' => Schema::TYPE_STRING,
            'generation' => Schema::TYPE_STRING,
        ];
    }

    public function useFieldset(): bool
    {
        return true;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): BunnyStreamFieldAttributes
    {
        if ($value instanceof BunnyStreamFieldAttributes) {
            $value->videoId = BunnyStreamFieldAttributes::normalizeVideoId($value->videoId);
            return $value;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
            if (is_string($value)) {
                $value = ['videoId' => $value];
            }
        }

        if (!is_array($value)) {
            $value = [];
        }

        // Prefer current keys, even if explicitly cleared in a partially migrated value.
        $videoId = array_key_exists('videoId', $value) ? $value['videoId'] : ($value['bunnyStreamVideoId'] ?? null);
        $metaData = array_key_exists('metaData', $value) ? $value['metaData'] : ($value['bunnyStreamMetaData'] ?? null);
        if (is_string($metaData)) {
            $metaData = Json::decodeIfJson($metaData);
        }

        $attributes = new BunnyStreamFieldAttributes();
        $attributes->videoId = BunnyStreamFieldAttributes::normalizeVideoId($videoId);
        $attributes->metaData = is_array($metaData) ? $metaData : null;
        $attributes->ownershipProof = is_string($value['ownershipProof'] ?? null) ? $value['ownershipProof'] : null;
        $attributes->generation = is_string($value['generation'] ?? null) ? $value['generation'] : null;
        return $attributes;
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): BunnyStreamFieldAttributes
    {
        // Craft calls this before replacing the element's value. Only the plugin
        // may change the link; even a well-formed GUID can belong to another asset.
        if ($element !== null && $this->handle !== null) {
            try {
                return $this->normalizeValue($element->getFieldValue($this->handle), $element);
            } catch (InvalidFieldException) {
                // The field isn't attached to this element's layout.
            }
        }

        return new BunnyStreamFieldAttributes();
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): ?array
    {
        if (!$value instanceof BunnyStreamFieldAttributes) {
            return null;
        }

        return [
            'videoId' => BunnyStreamFieldAttributes::normalizeVideoId($value->videoId),
            'metaData' => $value->metaData !== null ? Json::encode($value->metaData) : null,
            'ownershipProof' => $value->ownershipProof,
            'generation' => $value->generation,
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
