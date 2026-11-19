<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Json;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use yii\db\Expression;

class m261119_000000_migrate_field_content extends Migration
{
    public function safeUp(): bool
    {
        $fieldUids = (new Query())
            ->select('uid')
            ->from(Table::FIELDS)
            ->where(['type' => BunnyStreamField::class])
            ->column($this->db);
        if (!$fieldUids) {
            return true;
        }

        $fieldUids = array_fill_keys($fieldUids, true);
        $configs = (new Query())
            ->select('config')
            ->from(Table::FIELDLAYOUTS)
            ->where(['type' => Asset::class])
            ->column($this->db);

        // Craft 5 keys content by layout-element UID, not by field UID or handle.
        foreach ($configs as $config) {
            foreach (Json::decode($config)['tabs'] ?? [] as $tab) {
                foreach ($tab['elements'] ?? [] as $element) {
                    if (isset($element['uid'], $element['fieldUid'], $fieldUids[$element['fieldUid']])) {
                        $this->renameKey($element['uid'], 'bunnyStreamVideoId', 'videoId');
                        $this->renameKey($element['uid'], 'bunnyStreamMetaData', 'metaData');
                    }
                }
            }
        }

        return true;
    }

    private function renameKey(string $uid, string $oldKey, string $newKey): void
    {
        // Rename only this key in place. In particular, don't re-encode unrelated
        // fields, overwrite a newer value (even JSON null), or resave the asset.
        if ($this->db->getIsMysql()) {
            $prefix = '$.' . Json::encode($uid) . '.';
            $old = $this->db->quoteValue($prefix . $oldKey);
            $new = $this->db->quoteValue($prefix . $newKey);
            $condition = "JSON_CONTAINS_PATH([[content]], 'one', $old)";
            $sql = "JSON_REMOVE(JSON_INSERT([[content]], $new, JSON_EXTRACT([[content]], $old)), $old)";
        } else {
            $uid = $this->db->quoteValue($uid);
            $old = sprintf('ARRAY[%s, %s]', $uid, $this->db->quoteValue($oldKey));
            $new = sprintf('ARRAY[%s, %s]', $uid, $this->db->quoteValue($newKey));
            $condition = "([[content]] #> $old) IS NOT NULL";
            $sql = "jsonb_set([[content]], $new, COALESCE([[content]] #> $new, [[content]] #> $old)) #- $old";
        }

        $this->update(Table::ELEMENTS_SITES, ['content' => new Expression($sql)], $condition, updateTimestamp: false);
    }
}
