<?php

namespace Oro\Bundle\IntegrationBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;

use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class OroIntegrationBundleInstaller implements Installation
{
    /**
     * {@inheritdoc}
     */
    public function getMigrationVersion()
    {
        return 'v1_7';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema, QueryBag $queries)
    {
        /** Tables generation **/
        $this->createOroIntegrationChannelTable($schema);
        $this->createOroIntegrationChannelStatusTable($schema);
        $this->createOroIntegrationTransportTable($schema);

        /** Foreign keys generation **/
        $this->addOroIntegrationChannelForeignKeys($schema);
        $this->addOroIntegrationChannelStatusForeignKeys($schema);
    }

    /**
     * Create oro_integration_channel table
     *
     * @param Schema $schema
     */
    protected function createOroIntegrationChannelTable(Schema $schema)
    {
        $table = $schema->createTable('oro_integration_channel');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('organization_id', 'integer', ['notnull' => false]);
        $table->addColumn('transport_id', 'integer', ['notnull' => false]);
        $table->addColumn('default_user_owner_id', 'integer', ['notnull' => false]);
        $table->addColumn('name', 'string', ['length' => 255]);
        $table->addColumn('type', 'string', ['length' => 255]);
        $table->addColumn('connectors', 'array', ['comment' => '(DC2Type:array)']);
        $table->addColumn('synchronization_settings', 'object', ['comment' => '(DC2Type:object)']);
        $table->addColumn('mapping_settings', 'object', ['comment' => '(DC2Type:object)']);
        $table->addColumn('enabled', 'boolean', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['transport_id'], 'UNIQ_55B9B9C59909C13F');
        $table->addIndex(['default_user_owner_id'], 'IDX_55B9B9C5A89019EA', []);
        $table->addIndex(['organization_id'], 'IDX_55B9B9C532C8A3DE', []);
        $table->addIndex(['name'], 'oro_integration_channel_name_idx', []);
    }

    /**
     * Create oro_integration_channel_status table
     *
     * @param Schema $schema
     */
    protected function createOroIntegrationChannelStatusTable(Schema $schema)
    {
        $table = $schema->createTable('oro_integration_channel_status');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('channel_id', 'integer', []);
        $table->addColumn('code', 'string', ['length' => 255]);
        $table->addColumn('connector', 'string', ['length' => 255]);
        $table->addColumn('message', 'text', []);
        $table->addColumn('date', 'datetime', []);
        $table->addColumn('data', Type::JSON_ARRAY, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'IDX_C0D7E5FB72F5A1AA', []);
    }

    /**
     * Create oro_integration_transport table
     *
     * @param Schema $schema
     */
    protected function createOroIntegrationTransportTable(Schema $schema)
    {
        $table = $schema->createTable('oro_integration_transport');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('type', 'string', ['length' => 30]);
        $table->setPrimaryKey(['id']);
    }

    /**
     * Add oro_integration_channel foreign keys.
     *
     * @param Schema $schema
     */
    protected function addOroIntegrationChannelForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_integration_channel');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_organization'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'SET NULL', 'onUpdate' => null]
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_transport'),
            ['transport_id'],
            ['id'],
            ['onDelete' => null, 'onUpdate' => null]
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['default_user_owner_id'],
            ['id'],
            ['onDelete' => 'SET NULL', 'onUpdate' => null]
        );
    }

    /**
     * Add oro_integration_channel_status foreign keys.
     *
     * @param Schema $schema
     */
    protected function addOroIntegrationChannelStatusForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_integration_channel_status');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_channel'),
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE', 'onUpdate' => null]
        );
    }
}
