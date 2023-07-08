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
use yii\base\InvalidConfigException;
use yii\db\Schema;

class BunnyStreamField extends Field implements PreviewableFieldInterface
{
    public static function displayName(): string
    {
        return Craft::t('_bunny-stream', 'Bunny Stream');
    }

    public static function valueType(): string
    {
        return 'mixed';
    }

    /**
     * @param mixed $value
     * @param ElementInterface $element
     * @return string
     */
    public function getTableAttributeHtml(mixed $value, ElementInterface $element): string
    {
//        if (!$value instanceof MuxMateFieldAttributes || !$value->muxAssetId) {
//            $label = \Craft::t('_bunny-stream', 'Video does not have a Bunny Stream asset');
//            $content = '❌';
//        } else {
//            $muxData = $value->muxMetaData ?? [];
//            $muxStatus = $muxData['status'] ?? null;
//            if ($muxStatus !== 'ready') {
//                $label = \Craft::t('_bunny-stream', 'Bunny Stream video is being processed. Stay tuned!');
//                $content = '⏳';
//            } else {
//                $label = \Craft::t('_bunny-stream', 'Bunny Stream video is ready to play!');
//                $content = '👍';
//            }
//        }
//        return Html::tag('span', $content, [
//            'role' => 'img',
//            'title' => $label,
//            'aria' => [
//                'label' => $label,
//            ],
//        ]);
        return '';
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            // ...
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getContentColumnType(): array|string
    {
        return [
            'bunnyStreamVideoGuid' => Schema::TYPE_STRING,
            'bunnyStreamVideoStatus' => Schema::TYPE_INTEGER,
            'width' => Schema::TYPE_INTEGER,
            'height' => Schema::TYPE_INTEGER,
            'bunnyStreamMetaData' => Schema::TYPE_TEXT,
        ];
    }

    public function useFieldset(): bool
    {
        return true;
    }

    /**
     * @throws InvalidConfigException
     */
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


//        $id = Html::id($this->handle);
//        $namespacedId = Craft::$app->getView()->namespaceInputId($id);
//        $css = <<< CSS
//            #$namespacedId-field > .heading {
//                margin-bottom: 15px;
//            }
//            #$namespacedId-field legend {
//                font-size: 18px;
//            }
//            CSS;
//        Craft::$app->getView()->registerCss($css);

        return \Craft::$app->getView()->renderTemplate(
            '_bunny-stream/_components/bunnystream-field-input.twig',
            ['asset' => $element],
            View::TEMPLATE_MODE_CP
        );
    }

    public function getElementValidationRules(): array
    {
        return [];
    }

//    protected function searchKeywords(mixed $value, ElementInterface $element): string
//    {
//        if ($value instanceof MuxMateFieldAttributes && $value->muxPlaybackId) {
//            return $value->muxPlaybackId;
//        }
//        return '';
//    }
//
//    /**
//     * @inheritdoc
//     */
//    public function modifyElementsQuery(ElementQueryInterface $query, mixed $value): void
//    {
//        if (!$value) {
//            return;
//        }
//        /** @var ElementQuery $query */
//        $column = ElementHelper::fieldColumnFromField($this);
//        $playbackIdColumn = StringHelper::replace($column, $this->handle, "{$this->handle}_muxPlaybackId");
//        $metaDataColumn = StringHelper::replace($column, $this->handle, "{$this->handle}_muxMetaData");
//        if (is_array($value) && (isset($value['muxAssetId']) || isset($value['muxPlaybackId']))) {
//            if (isset($value['muxAssetId'])) {
//                $query->subQuery->andWhere(Db::parseParam("content.$column", $value['muxAssetId']));
//            }
//            if (isset($value['muxPlaybackId'])) {
//                $query->subQuery->andWhere(Db::parseParam("content.$playbackIdColumn", $value['muxPlaybackId']));
//            }
//            if (isset($value['muxMetaData'])) {
//                $query->subQuery->andWhere(Db::parseParam("content.$metaDataColumn", $value['muxMetaData']));
//            }
//        } else {
//            $query
//                ->subQuery
//                ->andWhere(Db::parseParam("content.$column", $value))
//                ->andWhere(Db::parseParam("content.$playbackIdColumn", $value));
//        }
//    }

}
