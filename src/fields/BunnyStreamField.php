<?php

namespace jorisnoo\bunnystream\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\Tip;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\web\View;


use jorisnoo\bunnystream\models\BunnyStreamFieldAttributes;
use yii\db\Schema;

class BunnyStreamField extends Field implements PreviewableFieldInterface
{
    public static function displayName(): string
    {
        return Craft::t('_bunny-stream', 'Bunny Stream');
    }

    public function getTableAttributeHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof BunnyStreamFieldAttributes || !$value->bunnyStreamVideoId) {
            $label = Craft::t('_bunny-stream', 'Video does not have a Bunny Stream asset');
            $content = '❌';
        } else {
            $bunnyStreamData = $value->bunnyStreamMetaData ?? [];
            $status = $bunnyStreamData['status'] ?? null;
            if ((int)$status !== 3) {
                $label = Craft::t('_bunny-stream', 'Bunny Stream video is being processed. Stay tuned!');
                $content = '⏳';
            } else {
                $label = Craft::t('_bunny-stream', 'Bunny Stream video is ready to play!');
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

    public function getContentColumnType(): array|string
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

    public function normalizeValue(mixed $value, ElementInterface $element = null): mixed
    {
        if ($value instanceof BunnyStreamFieldAttributes) {
            return $value;
        }
        return Craft::createObject([
            'class' => BunnyStreamFieldAttributes::class,
            ...($value ?? []),
        ]);
    }

    protected function inputHtml(mixed $value, ElementInterface $element = null): string
    {
        if (!$element instanceof Asset || $element->kind !== Asset::KIND_VIDEO) {
            $warningTip = new Tip([
                'style' => Tip::STYLE_WARNING,
                'tip' => Craft::t('_bunny-stream', 'The Bunny Stream field is only designed to work on video assets.'),
            ]);
            return $warningTip->formHtml();
        }

        return Craft::$app->getView()->renderTemplate(
            '_bunny-stream/_components/bunnystream-field-input.twig',
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

    public function modifyElementsQuery(ElementQueryInterface $query, mixed $value): void
    {
        if (!$value) {
            return;
        }
        /** @var ElementQuery $query */
        $column = ElementHelper::fieldColumnFromField($this);
        $metaDataColumn = StringHelper::replace($column, $this->handle, "{$this->handle}_bunnyStreamMetaData");
        if (is_array($value)) {
            $keys = array_keys($value);
            foreach ($keys as $key) {
                $query
                    ->subQuery
                    ->andWhere(Db::parseParam("JSON_EXTRACT(content.$metaDataColumn, '$.$key')", $value[$key]));
            }
        } else {
            $query
                ->subQuery
                ->andWhere(Db::parseParam("content.$column", $value));
        }
    }

}
