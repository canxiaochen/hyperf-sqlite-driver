<?php

declare(strict_types=1);

/**
 * Fangx's Packages
 *
 * @link     https://github.com/nfangxu/hyperf-sqlite-driver
 * @document https://github.com/nfangxu/hyperf-sqlite-driver/blob/master/README.md
 * @contact  nfangxu@gmail.com
 * @author   nfangxu
 */

namespace Fangx\SQLite\Schema\Grammars;

use Doctrine\DBAL\Schema\Index;
use Hyperf\Database\Connection;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Grammars\Grammar;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Fluent;
use RuntimeException;

class SQLiteGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected $modifiers = ['Nullable', 'Default', 'Increment'];

    /**
     * The columns available as serials.
     *
     * @var array
     */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * Compile the query to determine if a table exists.
     *
     * @return string
     */
    public function compileTableExists()
    {
        return "select * from sqlite_master where type = 'table' and name = ?";
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * @param string $table
     * @return string
     */
    public function compileColumnListing($table)
    {
        return 'pragma table_info(' . $this->wrap(str_replace('.', '__', $table)) . ')';
    }

    /**
     * Compile a create table command.
     *
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            '%s table %s (%s%s%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            (string)$this->addForeignKeys($blueprint),
            (string)$this->addPrimaryKeys($blueprint)
        );
    }

    /**
     * Compile alter table commands for adding columns.
     *
     * @return array
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->prefixArray('add column', $this->getColumns($blueprint));

        return collect($columns)->map(function ($column) use ($blueprint) {
            return 'alter table ' . $this->wrapTable($blueprint) . ' ' . $column;
        })->all();
    }

    /**
     * Compile a unique key command.
     *
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'create unique index %s on %s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns)
        );
    }

    /**
     * Compile a plain index key command.
     *
     * @return string
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'create index %s on %s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns)
        );
    }

    /**
     * Compile a spatial index key command.
     *
     * @throws \RuntimeException
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command)
    {
        throw new RuntimeException('The database driver in use does not support spatial indexes.');
    }

    /**
     * Compile a foreign key command.
     *
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table creation...
    }

    /**
     * Compile a drop table command.
     *
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile the SQL needed to drop all tables.
     *
     * @return string
     */
    public function compileDropAllTables()
    {
        return "delete from sqlite_master where type in ('table', 'index', 'trigger')";
    }

    /**
     * Compile the SQL needed to drop all views.
     *
     * @return string
     */
    public function compileDropAllViews()
    {
        return "delete from sqlite_master where type in ('view')";
    }

    /**
     * Compile the SQL needed to rebuild the database.
     *
     * @return string
     */
    public function compileRebuild()
    {
        return 'vacuum';
    }

    /**
     * Compile a drop column command.
     *
     * @return array
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $tableDiff = $this->getDoctrineTableDiff(
            $blueprint,
            $schema = $connection->getDoctrineSchemaManager()
        );

        foreach ($command->columns as $name) {
            $tableDiff->removedColumns[$name] = $connection->getDoctrineColumn(
                $this->getTablePrefix() . $blueprint->getTable(),
                $name
            );
        }

        return (array)$schema->getDatabasePlatform()->getAlterTableSQL($tableDiff);
    }

    /**
     * Compile a drop unique key command.
     *
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($command->index);

        return "drop index {$index}";
    }

    /**
     * Compile a drop index command.
     *
     * @return string
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($command->index);

        return "drop index {$index}";
    }

    /**
     * Compile a drop spatial index command.
     *
     * @throws \RuntimeException
     */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command)
    {
        throw new RuntimeException('The database driver in use does not support spatial indexes.');
    }

    /**
     * Compile a rename table command.
     *
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        $from = $this->wrapTable($blueprint);

        return "alter table {$from} rename to " . $this->wrapTable($command->to);
    }

    /**
     * Compile a rename index command.
     *
     * @return array
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $schemaManager = $connection->getDoctrineSchemaManager();

        $indexes = $schemaManager->listTableIndexes($this->getTablePrefix() . $blueprint->getTable());

        $index = Arr::get($indexes, $command->from);

        if (! $index) {
            throw new RuntimeException("Index [{$command->from}] does not exist.");
        }

        $newIndex = new Index(
            $command->to,
            $index->getColumns(),
            $index->isUnique(),
            $index->isPrimary(),
            $index->getFlags(),
            $index->getOptions()
        );

        $platform = $schemaManager->getDatabasePlatform();

        return [
            $platform->getDropIndexSQL($command->from, $this->getTablePrefix() . $blueprint->getTable()),
            $platform->getCreateIndexSQL($newIndex, $this->getTablePrefix() . $blueprint->getTable()),
        ];
    }

    /**
     * Compile the command to enable foreign key constraints.
     *
     * @return string
     */
    public function compileEnableForeignKeyConstraints()
    {
        return 'PRAGMA foreign_keys = ON;';
    }

    /**
     * Compile the command to disable foreign key constraints.
     *
     * @return string
     */
    public function compileDisableForeignKeyConstraints()
    {
        return 'PRAGMA foreign_keys = OFF;';
    }

    /**
     * Compile the SQL needed to enable a writable schema.
     *
     * @return string
     */
    public function compileEnableWriteableSchema()
    {
        return 'PRAGMA writable_schema = 1;';
    }

    /**
     * Compile the SQL needed to disable a writable schema.
     *
     * @return string
     */
    public function compileDisableWriteableSchema()
    {
        return 'PRAGMA writable_schema = 0;';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     *
     * @return string
     */
    public function typeGeometry(Fluent $column)
    {
        return 'geometry';
    }

    /**
     * Create the column definition for a spatial Point type.
     *
     * @return string
     */
    public function typePoint(Fluent $column)
    {
        return 'point';
    }

    /**
     * Create the column definition for a spatial LineString type.
     *
     * @return string
     */
    public function typeLineString(Fluent $column)
    {
        return 'linestring';
    }

    /**
     * Create the column definition for a spatial Polygon type.
     *
     * @return string
     */
    public function typePolygon(Fluent $column)
    {
        return 'polygon';
    }

    /**
     * Create the column definition for a spatial GeometryCollection type.
     *
     * @return string
     */
    public function typeGeometryCollection(Fluent $column)
    {
        return 'geometrycollection';
    }

    /**
     * Create the column definition for a spatial MultiPoint type.
     *
     * @return string
     */
    public function typeMultiPoint(Fluent $column)
    {
        return 'multipoint';
    }

    /**
     * Create the column definition for a spatial MultiLineString type.
     *
     * @return string
     */
    public function typeMultiLineString(Fluent $column)
    {
        return 'multilinestring';
    }

    /**
     * Create the column definition for a spatial MultiPolygon type.
     *
     * @return string
     */
    public function typeMultiPolygon(Fluent $column)
    {
        return 'multipolygon';
    }

    /**
     * Get the foreign key syntax for a table creation statement.
     *
     * @return null|string
     */
    protected function addForeignKeys(Blueprint $blueprint)
    {
        $foreigns = $this->getCommandsByName($blueprint, 'foreign');

        return collect($foreigns)->reduce(function ($sql, $foreign) {
            // Once we have all the foreign key commands for the table creation statement
            // we'll loop through each of them and add them to the create table SQL we
            // are building, since SQLite needs foreign keys on the tables creation.
            $sql .= $this->getForeignKey($foreign);

            if (! is_null($foreign->onDelete)) {
                $sql .= " on delete {$foreign->onDelete}";
            }

            // If this foreign key specifies the action to be taken on update we will add
            // that to the statement here. We'll append it to this SQL and then return
            // the SQL so we can keep adding any other foreign constraints onto this.
            if (! is_null($foreign->onUpdate)) {
                $sql .= " on update {$foreign->onUpdate}";
            }

            return $sql;
        }, '');
    }

    /**
     * Get the SQL for the foreign key.
     *
     * @param \Hyperf\Utils\Fluent $foreign
     * @return string
     */
    protected function getForeignKey($foreign)
    {
        // We need to columnize the columns that the foreign key is being defined for
        // so that it is a properly formatted list. Once we have done this, we can
        // return the foreign key SQL declaration to the calling method for use.
        return sprintf(
            ', foreign key(%s) references %s(%s)',
            $this->columnize($foreign->columns),
            $this->wrapTable($foreign->on),
            $this->columnize((array)$foreign->references)
        );
    }

    /**
     * Get the primary key syntax for a table creation statement.
     *
     * @return null|string
     */
    protected function addPrimaryKeys(Blueprint $blueprint)
    {
        if (! is_null($primary = $this->getCommandByName($blueprint, 'primary'))) {
            return ", primary key ({$this->columnize($primary->columns)})";
        }
    }

    /**
     * Create the column definition for a char type.
     *
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a string type.
     *
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a text type.
     *
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return 'text';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return 'text';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return 'text';
    }

    /**
     * Create the column definition for a integer type.
     *
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @return string
     */
    protected function typeMediumInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @return string
     */
    protected function typeTinyInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @return string
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Create the column definition for a float type.
     *
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return 'float';
    }

    /**
     * Create the column definition for a double type.
     *
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'float';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return 'numeric';
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'tinyint(1)';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @return string
     */
    protected function typeEnum(Fluent $column)
    {
        return sprintf(
            'varchar check ("%s" in (%s))',
            $column->name,
            $this->quoteString($column->allowed)
        );
    }

    /**
     * Create the column definition for a json type.
     *
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return 'text';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @return string
     */
    protected function typeJsonb(Fluent $column)
    {
        return 'text';
    }

    /**
     * Create the column definition for a date type.
     *
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return 'datetime';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * Note: "SQLite does not have a storage class set aside for storing dates and/or times."
     * @see https://www.sqlite.org/datatype3.html
     *
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column)
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a time type.
     *
     * @return string
     */
    protected function typeTime(Fluent $column)
    {
        return 'time';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @return string
     */
    protected function typeTimeTz(Fluent $column)
    {
        return $this->typeTime($column);
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        return $column->useCurrent ? 'datetime default CURRENT_TIMESTAMP' : 'datetime';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @return string
     */
    protected function typeTimestampTz(Fluent $column)
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a year type.
     *
     * @return string
     */
    protected function typeYear(Fluent $column)
    {
        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a binary type.
     *
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return 'blob';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @return string
     */
    protected function typeUuid(Fluent $column)
    {
        return 'varchar';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @return string
     */
    protected function typeIpAddress(Fluent $column)
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @return string
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'varchar';
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @return null|string
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        return $column->nullable ? ' null' : ' not null';
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @return null|string
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->default)) {
            return ' default ' . $this->getDefaultValue($column->default);
        }
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     *
     * @return null|string
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' primary key autoincrement';
        }
    }
}
