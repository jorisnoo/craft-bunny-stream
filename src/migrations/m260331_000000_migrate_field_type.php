<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

class m260331_000000_migrate_field_type extends Migration
{
    public function safeUp(): bool
    {
        // Migrate field type from old namespace
        $this->update(Table::FIELDS, [
            'type' => 'Noo\CraftBunnyStream\fields\BunnyStreamField',
        ], [
            'type' => 'jorisnoo\bunnystream\fields\BunnyStreamField',
        ]);

        // Rename field columns from old names to new names
        $fields = (new Query())
            ->select(['id', 'handle'])
            ->from(Table::FIELDS)
            ->where(['type' => 'Noo\CraftBunnyStream\fields\BunnyStreamField'])
            ->all();

        foreach ($fields as $field) {
            $handle = $field['handle'];

            // Craft 5 stores multi-column field data in the elements_sites table
            // Column names follow the pattern: field_{handle}_{columnKey}
            $oldVideoIdCol = "field_{$handle}_bunnyStreamVideoId";
            $newVideoIdCol = "field_{$handle}_videoId";
            $oldMetaDataCol = "field_{$handle}_bunnyStreamMetaData";
            $newMetaDataCol = "field_{$handle}_metaData";

            $schema = $this->db->getTableSchema(Table::ELEMENTS_SITES);

            if ($schema && $schema->getColumn($oldVideoIdCol)) {
                $this->renameColumn(Table::ELEMENTS_SITES, $oldVideoIdCol, $newVideoIdCol);
            }

            if ($schema && $schema->getColumn($oldMetaDataCol)) {
                $this->renameColumn(Table::ELEMENTS_SITES, $oldMetaDataCol, $newMetaDataCol);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
