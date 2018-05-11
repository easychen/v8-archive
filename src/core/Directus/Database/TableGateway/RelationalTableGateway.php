<?php

namespace Directus\Database\TableGateway;

use Directus\Database\Exception;
use Directus\Database\Filters\Filter;
use Directus\Database\Filters\In;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\Object\Collection;
use Directus\Database\Query\Builder;
use Directus\Database\RowGateway\BaseRowGateway;
use Directus\Database\Schema\Object\FieldRelationship;
use Directus\Database\Schema\SchemaManager;
use Directus\Database\SchemaService;
use Directus\Exception\ErrorException;
use Directus\Util\ArrayUtils;
use Directus\Util\DateTimeUtils;
use Directus\Util\StringUtils;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Predicate\PredicateInterface;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;
use Zend\Db\TableGateway\TableGateway;

class RelationalTableGateway extends BaseTableGateway
{
    const ACTIVITY_ENTRY_MODE_DISABLED = 0;
    const ACTIVITY_ENTRY_MODE_PARENT = 1;
    const ACTIVITY_ENTRY_MODE_CHILD = 2;

    protected $toManyCallStack = [];

    /**
     * @var array
     */
    protected $defaultEntriesSelectParams = [
        'limit' => 20,
        'offset' => 0,
        'search' => null,
        'meta' => 0,
        'status' => null
    ];

    protected $operatorShorthand = [
        'eq' => ['operator' => 'equal_to', 'not' => false],
        '='  => ['operator' => 'equal_to', 'not' => false],
        'neq' => ['operator' => 'equal_to', 'not' => true],
        '!='  => ['operator' => 'equal_to', 'not' => true],
        '<>'  => ['operator' => 'equal_to', 'not' => true],
        'in' => ['operator' => 'in', 'not' => false],
        'nin' => ['operator' => 'in', 'not' => true],
        'lt' => ['operator' => 'less_than', 'not' => false],
        'lte' => ['operator' => 'less_than_or_equal', 'not' => false],
        'gt' => ['operator' => 'greater_than', 'not' => false],
        'gte' => ['operator' => 'greater_than_or_equal', 'not' => false],

        'nlike' => ['operator' => 'like', 'not' => true],
        'contains' => ['operator' => 'like'],
        'ncontains' => ['operator' => 'like', 'not' => true],

        '<' => ['operator' => 'less_than', 'not' => false],
        '<=' => ['operator' => 'less_than_or_equal', 'not' => false],
        '>' => ['operator' => 'greater_than', 'not' => false],
        '>=' => ['operator' => 'greater_than_or_equal', 'not' => false],

        'nnull' => ['operator' => 'null', 'not' => true],

        'nempty' => ['operator' => 'empty', 'not' => true],

        'nhas' => ['operator' => 'has', 'not' => true],

        'nbetween' => ['operator' => 'between', 'not' => true],
    ];

    public function deleteRecord($id, array $params = [])
    {
        // TODO: Add "item" hook, different from "table" hook
        $success = $this->delete([
            $this->primaryKeyFieldName => $id
        ]);

        if (!$success) {
            throw new ErrorException(
                sprintf('Error deleting a record in %s with id %s', $this->table, $id)
            );
        }

        if ($this->table !== SchemaManager::COLLECTION_ACTIVITY) {
            $parentLogEntry = BaseRowGateway::makeRowGatewayFromTableName('id', 'directus_activity', $this->adapter);
            $logData = [
                'type' => DirectusActivityTableGateway::makeLogTypeFromTableName($this->table),
                'action' => DirectusActivityTableGateway::ACTION_DELETE,
                'user' => $this->acl->getUserId(),
                'datetime' => DateTimeUtils::nowInUTC()->toString(),
                'ip' => get_request_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'collection' => $this->table,
                'item' => $id,
                'message' => ArrayUtils::get($params, 'activity_message')
            ];
            $parentLogEntry->populate($logData, false);
            $parentLogEntry->save();
        }
    }

    /**
     * @param array $data
     * @param array $params
     *
     * @return BaseRowGateway
     */
    public function updateRecord($data, array $params = [])
    {
        return $this->manageRecordUpdate($this->getTable(), $data, $params);
    }

    /**
     * @param $data
     * @param array $params
     *
     * @return BaseRowGateway
     */
    public function revertRecord($data, array $params = [])
    {
        return $this->updateRecord($data, array_merge($params, ['revert' => true]));
    }

    /**
     * @param string $tableName
     * @param array $recordData
     * @param array $params
     * @param null $childLogEntries
     * @param bool $parentCollectionRelationshipsChanged
     * @param array $parentData
     *
     * @return BaseRowGateway
     */
    public function manageRecordUpdate($tableName, $recordData, array $params = [], &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false, $parentData = [])
    {
        $TableGateway = $this;
        if ($tableName !== $this->getTable()) {
            $TableGateway = new RelationalTableGateway($tableName, $this->adapter, $this->acl);
        }

        $activityEntryMode = ArrayUtils::get($params, 'activity_mode', static::ACTIVITY_ENTRY_MODE_PARENT);
        $recordIsNew = !array_key_exists($TableGateway->primaryKeyFieldName, $recordData);

        $tableSchema = SchemaService::getCollection($tableName);

        $currentUserId = $this->acl ? $this->acl->getUserId() : null;
        $isAdmin = $this->acl ? $this->acl->isAdmin() : false;

        // Do not let non-admins make admins
        // TODO: Move to hooks
        if ($tableName == 'directus_users' && !$isAdmin) {
            if (isset($recordData['group']) && $recordData['group']['id'] == 1) {
                unset($recordData['group']);
            }
        }

        $thisIsNested = ($activityEntryMode == self::ACTIVITY_ENTRY_MODE_CHILD);

        // Recursive functions will change this value (by reference) as necessary
        // $nestedCollectionRelationshipsChanged = $thisIsNested ? $parentCollectionRelationshipsChanged : false;
        $nestedCollectionRelationshipsChanged = false;
        if ($thisIsNested) {
            $nestedCollectionRelationshipsChanged = &$parentCollectionRelationshipsChanged;
        }

        // Recursive functions will append to this array by reference
        // $nestedLogEntries = $thisIsNested ? $childLogEntries : [];
        $nestedLogEntries = [];
        if ($thisIsNested) {
            $nestedLogEntries = &$childLogEntries;
        }

        // Update and/or Add Many-to-One Associations
        $recordData = $TableGateway->addOrUpdateManyToOneRelationships($tableSchema, $recordData, $nestedLogEntries, $nestedCollectionRelationshipsChanged);

        $parentRecordWithoutAlias = [];
        foreach ($recordData as $key => $data) {
            $column = $tableSchema->getField($key);

            // TODO: To work with files
            // As `data` is not set as alias for files we are checking for actual aliases
            if ($column && $column->isAlias()) {
                continue;
            }

            $parentRecordWithoutAlias[$key] = $data;
        }

        // NOTE: set the primary key to null
        // to default the value to whatever increment value is next
        // avoiding the error of inserting nothing
        if (empty($parentRecordWithoutAlias)) {
            $parentRecordWithoutAlias[$tableSchema->getPrimaryKeyName()] = null;
        }

        // If more than the record ID is present.
        $newRecordObject = null;
        $parentRecordChanged = $this->recordDataContainsNonPrimaryKeyData($recordData);

        if ($parentRecordChanged) {
            // Update the parent row, w/ any new association fields replaced by their IDs
            $newRecordObject = $TableGateway
                ->addOrUpdateRecordByArray($parentRecordWithoutAlias);
            if (!$newRecordObject) {
                return [];
            }

            if ($newRecordObject) {
                $newRecordObject = $newRecordObject->toArray();
            }
        }

        // Do it this way, because & byref for outcome of ternary operator spells trouble
        $draftRecord = &$parentRecordWithoutAlias;
        if ($recordIsNew) {
            $draftRecord = &$newRecordObject;
        }

        // Restore X2M relationship / alias fields to the record representation & process these relationships.
        $collectionColumns = $tableSchema->getAliasFields();
        foreach ($collectionColumns as $collectionColumn) {
            $colName = $collectionColumn->getName();
            if (isset($recordData[$colName])) {
                $draftRecord[$colName] = $recordData[$colName];
            }
        }

        // parent
        if ($activityEntryMode === self::ACTIVITY_ENTRY_MODE_PARENT) {
            $parentData = [
                'item' => array_key_exists($this->primaryKeyFieldName, $recordData) ? $recordData[$this->primaryKeyFieldName] : null,
                'collection' => $tableName
            ];
        }

        $draftRecord = $TableGateway->addOrUpdateToManyRelationships($tableSchema, $draftRecord, $nestedLogEntries, $nestedCollectionRelationshipsChanged, $parentData);
        $rowId = $draftRecord[$this->primaryKeyFieldName];

        $columnNames = SchemaService::getAllNonAliasCollectionFieldNames($tableName);
        $TemporaryTableGateway = new TableGateway($tableName, $this->adapter);
        $fullRecordData = $TemporaryTableGateway->select(function ($select) use ($rowId, $columnNames) {
            $select->where->equalTo($this->primaryKeyFieldName, $rowId);
            $select->limit(1)->columns($columnNames);
        })->current();

        if (!$fullRecordData) {
            $recordType = $recordIsNew ? 'new' : 'pre-existing';
            throw new \RuntimeException('Attempted to load ' . $recordType . ' record post-insert with empty result. Lookup via row id: ' . print_r($rowId, true));
        }

        $fullRecordData = (array) $fullRecordData;
        $deltaRecordData = $recordIsNew ? $parentRecordWithoutAlias : array_intersect_key((array)$parentRecordWithoutAlias, $fullRecordData);

        $statusField = $tableSchema->getStatusField();
        if ($recordIsNew) {
            $logEntryAction = DirectusActivityTableGateway::ACTION_ADD;
        } else if (ArrayUtils::get($params, 'revert') === true) {
            $logEntryAction = DirectusActivityTableGateway::ACTION_REVERT;
        } else {
            $logEntryAction = DirectusActivityTableGateway::ACTION_UPDATE;

            try {
                if (
                    $statusField
                    && ArrayUtils::has($deltaRecordData, $statusField->getName())
                    && in_array(
                        ArrayUtils::get($deltaRecordData, $tableSchema->getStatusField()->getName()),
                        $this->getStatusMapping()->getSoftDeleteStatusesValue()
                    )
                ) {
                    $logEntryAction = DirectusActivityTableGateway::ACTION_SOFT_DELETE;
                }
            } catch (\Exception $e) {
                // the field doesn't have a status mapping
            }
        }

        switch ($activityEntryMode) {
            // Activity logging is enabled, and I am a nested action
            case self::ACTIVITY_ENTRY_MODE_CHILD:
                $childLogEntries[] = [
                    'type' => DirectusActivityTableGateway::makeLogTypeFromTableName($this->table),
                    'action' => $logEntryAction,
                    'user' => $currentUserId,
                    'datetime' => DateTimeUtils::nowInUTC()->toString(),
                    'ip' => get_request_ip(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                    'collection' => $tableName,
                    'parent_item' => isset($parentData['item']) ? $parentData['item'] : null,
                    'parent_collection' => isset($parentData['collection']) ? $parentData['collection'] : null,
                    'data' => json_encode($fullRecordData),
                    'delta' => !empty($deltaRecordData) ? json_encode($deltaRecordData) : null,
                    'parent_changed' => boolval($parentRecordChanged),
                    'item' => $rowId,
                    'message' => null
                ];
                if ($recordIsNew) {
                    /**
                     * This is a nested call, creating a new record w/in a foreign collection.
                     * Indicate by reference that the top-level record's relationships have changed.
                     */
                    $parentCollectionRelationshipsChanged = true;
                }
                break;

            case self::ACTIVITY_ENTRY_MODE_PARENT:
                // Does this act deserve a log?
                $parentRecordNeedsLog = $nestedCollectionRelationshipsChanged || $parentRecordChanged;
                /**
                 * NESTED QUESTIONS!
                 * @todo  what do we do if the foreign record OF a foreign record changes?
                 * is that activity entry also directed towards this parent activity entry?
                 * @todo  how should nested activity entries relate to the revision histories of foreign items?
                 * @todo  one day: treat children as parents if this top-level record was not modified.
                 */
                // Produce log if something changed.
                if ($parentRecordChanged || $nestedCollectionRelationshipsChanged) {
                    // Save parent log entry
                    $parentLogEntry = BaseRowGateway::makeRowGatewayFromTableName('id', 'directus_activity', $this->adapter);
                    $logData = [
                        'type' => DirectusActivityTableGateway::makeLogTypeFromTableName($this->table),
                        'action' => $logEntryAction,
                        'user' => $currentUserId,
                        'datetime' => DateTimeUtils::nowInUTC()->toString(),
                        'ip' => get_request_ip(),
                        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                        'collection' => $tableName,
                        'item' => $rowId,
                        'message' => ArrayUtils::get($params, 'activity_message')
                    ];
                    $parentLogEntry->populate($logData, false);
                    $parentLogEntry->save();

                    // Add Revisions
                    $revisionTableGateway = new RelationalTableGateway(SchemaManager::COLLECTION_REVISIONS, $this->adapter);
                    $revisionTableGateway->insert([
                        'activity' => $parentLogEntry->getId(),
                        'collection' => $tableName,
                        'item' => $rowId,
                        'data' => json_encode($fullRecordData),
                        'delta' => !empty($deltaRecordData) ? json_encode($deltaRecordData) : null,
                        'parent_item' => null,
                        'parent_collection' => null,
                        'parent_changed' => null,//boolval($parentRecordChanged)
                    ]);

                    // Update & insert nested activity entries
                    $ActivityGateway = new DirectusActivityTableGateway($this->adapter);
                    foreach ($nestedLogEntries as $entry) {
                        // TODO: ought to insert these in one batch
                        $ActivityGateway->insert(ArrayUtils::omit($entry, [
                            'parent_item',
                            'parent_collection',
                            'data',
                            'delta',
                            'parent_changed',
                        ]));
                        $revisionTableGateway->insert([
                            'activity' => $ActivityGateway->lastInsertValue,
                            'collection' => ArrayUtils::get($entry, 'collection'),
                            'item' => ArrayUtils::get($entry, 'item'),
                            'data' => ArrayUtils::get($entry, 'data'),
                            'delta' => ArrayUtils::get($entry, 'delta'),
                            'parent_item' => ArrayUtils::get($entry, 'parent_item'),
                            'parent_collection' => ArrayUtils::get($entry, 'parent_collection'),
                            'parent_changed' => ArrayUtils::get($entry, 'parent_changed')
                        ]);
                    }
                }
                break;
        }

        // Yield record object
        $recordGateway = new BaseRowGateway($TableGateway->primaryKeyFieldName, $tableName, $this->adapter, $this->acl);
        $recordGateway->populate($this->parseRecord($fullRecordData), true);

        return $recordGateway;
    }

    /**
     * @param Collection $schema The table schema array.
     * @param array $parentRow The parent record being updated.
     * @return  array
     */
    public function addOrUpdateManyToOneRelationships($schema, $parentRow, &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false)
    {
        // Create foreign row and update local column with the data id
        foreach ($schema->getFields() as $field) {
            $fieldName = $field->getName();

            if (!$field->isManyToOne()) {
                continue;
            }

            // Ignore absent values & non-arrays
            if (!isset($parentRow[$fieldName]) || !is_array($parentRow[$fieldName])) {
                continue;
            }

            // Ignore non-arrays and empty collections
            if (empty($parentRow[$fieldName])) {
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$fieldName]);
                continue;
            }

            $foreignDataSet = $parentRow[$fieldName];
            $foreignRow = $foreignDataSet;
            $foreignTableName = $field->getRelationship()->getCollectionB();
            $foreignTableSchema = $this->getTableSchema($foreignTableName);
            $primaryKey = $foreignTableSchema->getPrimaryKeyName();
            $ForeignTable = new RelationalTableGateway($foreignTableName, $this->adapter, $this->acl);

            if ($primaryKey && ArrayUtils::get($foreignRow, $this->deleteFlag) === true) {
                $Where = new Where();
                $Where->equalTo($primaryKey, $foreignRow[$primaryKey]);
                $ForeignTable->delete($Where);

                $parentRow[$fieldName] = $field->isNullable() ? null : 0;

                continue;
            }

            // Update/Add foreign record
            if ($this->recordDataContainsNonPrimaryKeyData($foreignRow, $foreignTableSchema->getPrimaryKeyName())) {
                // NOTE: using manageRecordUpdate instead of addOrUpdateRecordByArray to update related data
                $foreignRow = $this->manageRecordUpdate($foreignTableName, $foreignRow);
            }

            $parentRow[$fieldName] = $foreignRow[$primaryKey];
        }

        return $parentRow;
    }

    /**
     * @param Collection $schema The table schema array.
     * @param array $parentRow The parent record being updated.
     * @return  array
     */
    public function addOrUpdateToManyRelationships($schema, $parentRow, &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false, $parentData = [])
    {
        // Create foreign row and update local column with the data id
        foreach ($schema->getFields() as $field) {
            $fieldName = $field->getName();

            if (!$field->hasRelationship()) {
                continue;
            }

            // Ignore absent values & non-arrays
            if (!isset($parentRow[$fieldName]) || !is_array($parentRow[$fieldName])) {
                continue;
            }

            $relationship = $field->getRelationship();
            $fieldIsCollectionAssociation = $relationship->isToMany();

            // Ignore non-arrays and empty collections
            if (empty($parentRow[$fieldName])) {//} || ($fieldIsOneToMany && )) {
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$fieldName]);
                continue;
            }

            $foreignDataSet = $parentRow[$fieldName];

            /** One-to-Many, Many-to-Many */
            if ($fieldIsCollectionAssociation) {
                $this->enforceColumnHasNonNullValues($relationship->toArray(), ['collection_b', 'field_a'], $this->table);
                $foreignTableName = $relationship->getCollectionB();
                $foreignJoinColumn = $relationship->getFieldB();
                switch ($relationship->getType()) {
                    /** One-to-Many */
                    case FieldRelationship::ONE_TO_MANY:
                        $ForeignTable = new RelationalTableGateway($foreignTableName, $this->adapter, $this->acl);
                        foreach ($foreignDataSet as &$foreignRecord) {
                            if (empty($foreignRecord)) {
                                continue;
                            }

                            // TODO: Fix a bug when fetching a single column
                            // before fetching all columns from a table
                            // due to our basic "cache" implementation on schema layer
                            $hasPrimaryKey = isset($foreignRecord[$ForeignTable->primaryKeyFieldName]);

                            if ($hasPrimaryKey && ArrayUtils::get($foreignRecord, $this->deleteFlag) === true) {
                                $Where = new Where();
                                $Where->equalTo($ForeignTable->primaryKeyFieldName, $foreignRecord[$ForeignTable->primaryKeyFieldName]);
                                $ForeignTable->delete($Where);

                                continue;
                            }

                            // only add parent id's to items that are lacking the parent column
                            if (!array_key_exists($foreignJoinColumn, $foreignRecord)) {
                                $foreignRecord[$foreignJoinColumn] = $parentRow['id'];
                            }

                            $foreignRecord = $this->manageRecordUpdate(
                                $foreignTableName,
                                $foreignRecord,
                                ['activity_mode' => self::ACTIVITY_ENTRY_MODE_CHILD],
                                $childLogEntries,
                                $parentCollectionRelationshipsChanged,
                                $parentData
                            );
                        }
                        break;

                    /** Many-to-Many */
                    case FieldRelationship::MANY_TO_MANY:
                        $foreignJoinColumn = $relationship->getJunctionKeyB();
                        /**
                         * [+] Many-to-Many payloads declare collection items this way:
                         * $parentRecord['collectionName1'][0-9]['data']; // record key-value array
                         * [+] With optional association metadata:
                         * $parentRecord['collectionName1'][0-9]['id']; // for updating a pre-existing junction row
                         * $parentRecord['collectionName1'][0-9]['active']; // for disassociating a junction via the '0' value
                         */

                        $this->enforceColumnHasNonNullValues($relationship->toArray(), ['junction_collection', 'junction_key_a'], $this->table);
                        $junctionTableName = $relationship->getJunctionCollection();//$column['relationship']['junction_table'];
                        $junctionKeyLeft = $relationship->getJunctionKeyA();//$column['relationship']['junction_key_left'];
                        $junctionKeyRight = $relationship->getJunctionKeyB();//$column['relationship']['junction_key_right'];
                        $JunctionTable = new RelationalTableGateway($junctionTableName, $this->adapter, $this->acl);
                        $ForeignTable = new RelationalTableGateway($foreignTableName, $this->adapter, $this->acl);
                        foreach ($foreignDataSet as $junctionRow) {
                            /** This association is designated for removal */
                            $hasPrimaryKey = isset($junctionRow[$JunctionTable->primaryKeyFieldName]);

                            if ($hasPrimaryKey && ArrayUtils::get($junctionRow, $this->deleteFlag) === true) {
                                $Where = new Where;
                                $Where->equalTo($JunctionTable->primaryKeyFieldName, $junctionRow[$JunctionTable->primaryKeyFieldName]);
                                $JunctionTable->delete($Where);
                                // Flag the top-level record as having been altered.
                                // (disassociating w/ existing M2M collection entry)
                                $parentCollectionRelationshipsChanged = true;
                                continue;
                            }

                            /** Update foreign record */
                            $foreignRecord = ArrayUtils::get($junctionRow, $junctionKeyRight, []);
                            if (is_array($foreignRecord)) {
                                $foreignRecord = $ForeignTable->manageRecordUpdate(
                                    $foreignTableName,
                                    $foreignRecord,
                                    ['activity_mode' => self::ACTIVITY_ENTRY_MODE_CHILD],
                                    $childLogEntries,
                                    $parentCollectionRelationshipsChanged,
                                    $parentData
                                );
                                $foreignJoinColumnKey = $foreignRecord[$ForeignTable->primaryKeyFieldName];
                            } else {
                                $foreignJoinColumnKey = $foreignRecord;
                            }

                            // Junction/Association row
                            $junctionTableRecord = [
                                $junctionKeyLeft => $parentRow[$this->primaryKeyFieldName],
                                $foreignJoinColumn => $foreignJoinColumnKey
                            ];

                            // Update fields on the Junction Record
                            $junctionTableRecord = array_merge($junctionRow, $junctionTableRecord);

                            $foreignRecord = (array)$foreignRecord;

                            $relationshipChanged = $this->recordDataContainsNonPrimaryKeyData($foreignRecord, $ForeignTable->primaryKeyFieldName) ||
                                $this->recordDataContainsNonPrimaryKeyData($junctionTableRecord, $JunctionTable->primaryKeyFieldName);

                            // Update Foreign Record
                            if ($relationshipChanged) {
                                $JunctionTable->addOrUpdateRecordByArray($junctionTableRecord, $junctionTableName);
                            }
                        }
                        break;
                }
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$fieldName]);
            }
        }

        return $parentRow;
    }

    public function applyDefaultEntriesSelectParams(array $params)
    {
        // NOTE: Performance spot
        // TODO: Split this, into default and process params
        $defaultParams = $this->defaultEntriesSelectParams;
        $defaultLimit = $this->getSettings('global', 'default_limit');

        // Set default rows limit from db settings
        if ($defaultLimit) {
            $defaultParams['limit'] = (int)$defaultLimit;
        }

        $id = ArrayUtils::get($params, 'id');
        if ($id && count(StringUtils::csv((string) $id)) == 1) {
            $params['single'] = true;
        }

        // Fetch only one if single param is set
        if (ArrayUtils::get($params, 'single')) {
            $params['limit'] = 1;
        }

        // Remove the columns parameters
        // Until we  call it fields internally
        if (ArrayUtils::has($params, 'columns')) {
            ArrayUtils::remove($params, 'columns');
        }

        // NOTE: Let's use "columns" instead of "fields" internally for the moment
        if (ArrayUtils::has($params, 'fields')) {
            $params['fields'] = ArrayUtils::get($params, 'fields');
            // ArrayUtils::remove($params, 'fields');
        }

        $tableSchema = $this->getTableSchema();
        $sortingField = $tableSchema->getSortingField();
        $defaultParams['sort'] = $sortingField ? $sortingField->getName() : $this->primaryKeyFieldName;

        // Is not there a sort column?
        $tableColumns = array_flip(SchemaService::getCollectionFields($this->table, null, true));
        if (!$this->primaryKeyFieldName || !array_key_exists($this->primaryKeyFieldName, $tableColumns)) {
            unset($defaultParams['sort']);
        }

        if (!$this->getTableSchema()->hasStatusField() || ArrayUtils::get($params, 'status') === '*') {
            ArrayUtils::remove($params, 'status');
        } else if (!ArrayUtils::has($params, 'id') && !ArrayUtils::has($params, 'status')) {
            $defaultParams['status'] = $this->getPublishedStatuses();
        } else if (ArrayUtils::has($params, 'status') && is_string(ArrayUtils::get($params, 'status'))) {
            $params['status'] = StringUtils::csv($params['status']);
        }

        $params = array_merge($defaultParams, $params);

        if (ArrayUtils::get($params, 'sort')) {
            $params['sort'] = StringUtils::csv($params['sort']);
        }

        // convert csv columns into array
        $columns = convert_param_columns(ArrayUtils::get($params, 'fields', []));

        // Add columns to params if it's not empty.
        // otherwise remove from params
        if (!empty($columns)) {
            $params['fields'] = $columns;
        } else {
            ArrayUtils::remove($params, 'fields');
        }

        if ($params['limit'] === null) {
            ArrayUtils::remove($params, 'limit');
        }

        array_walk($params, [$this, 'castFloatIfNumeric']);

        return $params;
    }

    /**
     * @param array $params
     * @param Builder $builder
     *
     * @return Builder
     */
    public function applyParamsToTableEntriesSelect(array $params, Builder $builder)
    {
        // ----------------------------------------------------------------------------
        // STATUS VALUES
        // ----------------------------------------------------------------------------
        $statusField = $this->getTableSchema()->getStatusField();
        $permissionStatuses = $this->acl->getCollectionStatusesReadPermission($this->getTable());
        if ($statusField && is_array($permissionStatuses)) {
            $paramStatuses = ArrayUtils::get($params, 'status');
            if (is_array($paramStatuses)) {
                $permissionStatuses = ArrayUtils::intersection(
                    $permissionStatuses,
                    $paramStatuses
                );
            }

            $params['status'] = $permissionStatuses;
        }

        // @TODO: Query Builder Object
        foreach($params as $type => $argument) {
            $method = 'process' . ucfirst($type);
            if (method_exists($this, $method)) {
                call_user_func_array([$this, $method], [$builder, $argument]);
            }
        }

        $this->applyLegacyParams($builder, $params);

        return $builder;
    }

    /**
     * Relational Getter
     * NOTE: equivalent to old DB#get_entries
     *
     * @param  array $params
     *
     * @return array
     */
    public function getEntries($params = [])
    {
        if (!is_array($params)) {
            $params = [];
        }

        return $this->getItems($params);
    }

    /**
     * Get table items
     *
     * @param array $params
     *
     * @return array|mixed
     */
    public function getItems(array $params = [])
    {
        $entries = $this->loadItems($params);

        $single = ArrayUtils::has($params, 'id') || ArrayUtils::has($params, 'single');
        $meta = ArrayUtils::get($params, 'meta', 0);

        return $this->wrapData($entries, $single, $meta);
    }

    /**
     * wrap the query result into the api response format
     *
     * TODO: This will be soon out of TableGateway
     *
     * @param array $data
     * @param bool $single
     * @param bool $meta
     *
     * @return array
     */
    public function wrapData($data, $single = false, $meta = false)
    {
        $result = [];

        if ($meta) {
            if (!is_array($meta)) {
                $meta = StringUtils::csv($meta);
            }

            $result['meta'] = $this->createMetadata($data, $single, $meta);
        }

        $result['data'] = $data;

        return $result;
    }

    public function loadMetadata($data, $single = false)
    {
        return $this->wrapData($data, $single);
    }

    public function createMetadata($entriesData, $single, $list = [])
    {
        $singleEntry = $single || !ArrayUtils::isNumericKeys($entriesData);
        $metadata = $this->createGlobalMetadata($singleEntry, $list);

        if (!$singleEntry) {
            $metadata = array_merge($metadata, $this->createEntriesMetadata($entriesData, $list));
        }

        return $metadata;
    }

    /**
     * Creates the "global" metadata
     *
     * @param bool $single
     * @param array $list
     *
     * @return array
     */
    public function createGlobalMetadata($single, array $list = [])
    {
        $allKeys = ['collection', 'type'];
        $metadata = [];

        if (empty($list) || in_array('*', $list)) {
            $list = $allKeys;
        }

        if (in_array('collection', $list)) {
            $metadata['collection'] = $this->getTable();
        }

        if (in_array('type', $list)) {
            $metadata['type'] = $single ? 'item' : 'collection';
        }

        return $metadata;
    }

    /**
     * Create entries metadata
     *
     * @param array $entries
     * @param array $list
     *
     * @return array
     */
    public function createEntriesMetadata(array $entries, array $list = [])
    {
        $allKeys = ['result_count', 'total_count', 'status'];
        $tableSchema = $this->getTableSchema($this->table);

        $metadata = [];

        if (empty($list) || in_array('*', $list)) {
            $list = $allKeys;
        }

        if (in_array('result_count', $list)) {
            $metadata['result_count'] = count($entries);
        }

        if (in_array('total_count', $list)) {
            $metadata['total_count'] = $this->countTotal();
        }

        if ($tableSchema->hasStatusField() && in_array('status', $list)) {
            $statusCount = $this->countByStatus();
            $metadata['status'] = $statusCount;
        }

        return $metadata;
    }

    /**
     * Load Table entries
     *
     * @param array $params
     * @param \Closure|null $queryCallback
     *
     * @return array
     *
     * @throws Exception\InvalidFieldException
     * @throws Exception\ItemNotFoundException
     */
    public function loadItems(array $params = [], \Closure $queryCallback = null)
    {
        $collectionObject = $this->getTableSchema();

        $params = $this->applyDefaultEntriesSelectParams($params);
        $fields = ArrayUtils::get($params, 'fields');

        if (is_array($fields)) {
            $this->validateFields($fields);
        }

        // TODO: Check for all collections + fields permission/existence before querying
        // TODO: Create a new TableGateway Query Builder based on Query\Builder
        $builder = new Builder($this->getAdapter());
        $builder->from($this->getTable());

        $selectedFields = array_merge(
            [$collectionObject->getPrimaryKeyName()],
            $this->getSelectedNonAliasFields($fields ?: ['*'])
        );

        $statusField = $collectionObject->getStatusField();
        if ($statusField && $this->acl->getCollectionStatuses($this->table)) {
            $selectedFields = array_merge($selectedFields, [$statusField->getName()]);
        }

        $builder->columns($selectedFields);

        $builder = $this->applyParamsToTableEntriesSelect(
            $params,
            $builder
        );

        $this->enforceReadPermission($builder);

        if ($queryCallback !== null) {
            $builder = $queryCallback($builder);
        }

        // Run the builder Select with this tablegateway
        // to run all the hooks against the result
        $results = $this->selectWith($builder->buildSelect())->toArray();

        if (!$results && ArrayUtils::has($params, 'single')) {
            if (ArrayUtils::has($params, 'id')) {
                $message = sprintf('Item with id "%s" not found', $params['id']);
            } else {
                $message = 'Item not found';
            }

            throw new Exception\ItemNotFoundException($message);
        }

        // ==========================================================================
        // Perform data casting based on the column types in our schema array
        // and Convert dates into ISO 8601 Format
        // ==========================================================================
        $results = $this->parseRecord($results);

        $columnsDepth = ArrayUtils::deepLevel(get_unflat_columns($fields));
        if ($columnsDepth > 0) {
            $relatedFields = $this->getSelectedRelatedFields($fields);

            $relationalParams = [
                'meta' => ArrayUtils::get($params, 'meta'),
                'lang' => ArrayUtils::get($params, 'lang')
            ];

            $results = $this->loadRelationalData(
                $results,
                get_array_flat_columns($relatedFields),
                $relationalParams
            );
        }

        // When the params column list doesn't include the primary key
        // it should be included because each row gateway expects the primary key
        // after all the row gateway are created and initiated it only returns the chosen columns
        if ($fields && !array_key_exists('*', get_unflat_columns($fields))) {
            $visibleColumns = $this->getSelectedFields($fields);
            $results = array_map(function ($entry) use ($visibleColumns) {
                foreach ($entry as $key => $value) {
                    if (!in_array($key, $visibleColumns)) {
                        $entry = ArrayUtils::omit($entry, $key);
                    }
                }

                return $entry;
            }, $results);
        }

        if ($statusField && $this->acl->getCollectionStatuses($this->table)) {
            foreach ($results as $index => &$item) {
                $statusId = ArrayUtils::get($item, $statusField->getName());
                $blacklist = $this->acl->getReadFieldBlacklist($this->table, $statusId);
                $item = ArrayUtils::omit($item, $blacklist);
                if (empty($item)) {
                    unset($results[$index]);
                }
            }

            $results = array_values($results);
        }

        if (ArrayUtils::has($params, 'single')) {
            $results = reset($results);
        }

        return $results ? $results : [];
    }

    /**
     * Load Table entries
     *
     * Alias of loadItems
     *
     * @param array $params
     * @param \Closure|null $queryCallback
     *
     * @return mixed
     */
    public function loadEntries(array $params = [], \Closure $queryCallback = null)
    {
        return $this->loadItems($params, $queryCallback);
    }

    /**
     * Loads all relational data by depth level
     *
     * @param $result
     * @param array|null $columns
     * @param array $params
     *
     * @return array
     */
    protected function loadRelationalData($result, array $columns = [], array $params = [])
    {
        $result = $this->loadManyToOneRelationships($result, $columns, $params);
        $result = $this->loadOneToManyRelationships($result, $columns, $params);
        $result = $this->loadManyToManyRelationships($result, $columns, $params);

        return $result;
    }

    /**
     * Parse Filter "condition" (this is the filter key value)
     *
     * @param $condition
     *
     * @return array
     */
    protected function parseCondition($condition)
    {
        // TODO: Add a simplified option for logical
        // adding an "or_" prefix
        // filters[column][eq]=Value1&filters[column][or_eq]=Value2
        $logical = null;
        if (is_array($condition) && isset($condition['logical'])) {
            $logical = $condition['logical'];
            unset($condition['logical']);
        }

        $operator = is_array($condition) ? key($condition) : '=';
        $value = is_array($condition) ? current($condition) : $condition;
        $not = false;

        return [
            'operator' => $operator,
            'value' => $value,
            'not' => $not,
            'logical' => $logical
        ];
    }

    protected function parseDotFilters(Builder $mainQuery, array $filters)
    {
        foreach ($filters as $column => $condition) {
            if (!is_string($column) || strpos($column, '.') === false) {
                continue;
            }

            $columnList = $columns = explode('.', $column);
            $columnsTable = [
                $this->getTable()
            ];

            $nextColumn = array_shift($columnList);
            $nextTable = $this->getTable();
            $relational = SchemaService::hasRelationship($nextTable, $nextColumn);

            while ($relational) {
                $nextTable = SchemaService::getRelatedCollectionName($nextTable, $nextColumn);
                $nextColumn = array_shift($columnList);
                $relational = SchemaService::hasRelationship($nextTable, $nextColumn);
                $columnsTable[] = $nextTable;
            }

            // if one of the column in the list has not relationship
            // it will break the loop before going over all the columns
            // which we will call this as column not found
            // TODO: Better error message
            if (!empty($columnList)) {
                throw new Exception\FieldNotFoundException($nextColumn);
            }

            // Remove the original filter column with dot-notation
            unset($filters[$column]);

            // Reverse all the columns from comments.author.id to id.author.comments
            // To filter from the most deep relationship to their parents
            $columns = explode('.', column_identifier_reverse($column));
            $columnsTable = array_reverse($columnsTable, true);

            $mainColumn = array_pop($columns);
            $mainTable = array_pop($columnsTable);

            // the main query column
            // where the filter is going to be applied
            $column = array_shift($columns);
            $table = array_shift($columnsTable);

            $query = new Builder($this->getAdapter());
            $mainTableObject = $this->getTableSchema($table);
            $query->columns([$mainTableObject->getPrimaryField()->getName()]);
            $query->from($table);

            $this->doFilter($query, $column, $condition, $table);

            $index = 0;
            foreach ($columns as $key => $column) {
                ++$index;

                $oldQuery = $query;
                $query = new Builder($this->getAdapter());
                $collection = $this->getTableSchema($columnsTable[$key]);
                $field = $collection->getField($column);

                $selectColumn = $collection->getPrimaryField()->getName();
                $table = $columnsTable[$key];

                if ($field->isAlias()) {
                    $column = $collection->getPrimaryField()->getName();
                }

                if ($field->isManyToMany()) {
                    $selectColumn = $field->getRelationship()->getJunctionKeyA();
                    $column = $field->getRelationship()->getJunctionKeyB();
                    $table = $field->getRelationship()->getJunctionCollection();
                }

                $query->columns([$selectColumn]);
                $query->from($table);
                $query->whereIn($column, $oldQuery);
            }

            $collection = $this->getTableSchema($mainTable);
            $field = $collection->getField($mainColumn);
            $relationship = $field->getRelationship();

            // TODO: Make all this whereIn duplication into a function
            // TODO: Can we make the O2M simpler getting the parent id from itself
            //       right now is creating one unnecessary select
            if ($field->isManyToMany() || $field->isOneToMany()) {
                $mainColumn = $collection->getPrimaryField()->getName();
                $oldQuery = $query;
                $query = new Builder($this->getAdapter());

                if ($field->isManyToMany()) {
                    $selectColumn = $relationship->getJunctionKeyB();
                    $table = $relationship->getJunctionCollection();
                    $column = $relationship->getJunctionKeyA();
                } else {
                    $selectColumn = $column = $relationship->getJunctionKeyA();
                    $table = $relationship->getCollectionB();
                }

                $query->columns([$selectColumn]);
                $query->from($table);
                $query->whereIn(
                    $column,
                    $oldQuery
                );
            }

            $this->doFilter(
                $mainQuery,
                $mainColumn,
                [
                    'in' => $query
                ],
                $mainTable
            );
        }

        return $filters;
    }

    protected function doFilter(Builder $query, $column, $condition, $table)
    {
        $fieldName = $this->getColumnFromIdentifier($column);
        $field = $this->getField(
            $fieldName,
            // $table will be the default value to get
            // if the column has not identifier format
            $this->getTableFromIdentifier($column, $table)
        );

        if (!$field) {
            throw new Exception\InvalidFieldException($fieldName);
        }

        $condition = $this->parseCondition($condition);
        $operator = ArrayUtils::get($condition, 'operator');
        $value = ArrayUtils::get($condition, 'value');
        $not = ArrayUtils::get($condition, 'not');
        $logical = ArrayUtils::get($condition, 'logical');

        // TODO: if there's more, please add a better way to handle all this
        if ($field->isToMany()) {
            // translate some non-x2m relationship filter to x2m equivalent (if exists)
            switch ($operator) {
                case 'empty':
                    // convert x2m empty
                    // to not has at least one record
                    $operator = 'has';
                    $not = true;
                    $value = 1;
                    break;
            }
        }

        // Get information about the operator shorthand
        if (ArrayUtils::has($this->operatorShorthand, $operator)) {
            $operatorShorthand = $this->operatorShorthand[$operator];
            $operator = ArrayUtils::get($operatorShorthand, 'operator', $operator);
            $not = ArrayUtils::get($operatorShorthand, 'not', !$value);
        }

        $operatorName = StringUtils::underscoreToCamelCase(strtolower($operator), true);
        $method = 'where' . ($not === true ? 'Not' : '') . $operatorName;
        if (!method_exists($query, $method)) {
            return false;
        }

        $splitOperators = ['between', 'in'];
        // TODO: Add exception for API 2.0
        if (in_array($operator, $splitOperators) && is_scalar($value)) {
            $value = explode(',', $value);
        }

        $arguments = [$column, $value];

        if (isset($logical)) {
            $arguments[] = null;
            $arguments[] = $logical;
        }

        if (in_array($operator, ['all', 'has']) && $field->isToMany()) {
            if ($operator == 'all' && is_string($value)) {
                $value = array_map(function ($item) {
                    return trim($item);
                }, explode(',', $value));
            } else if ($operator == 'has') {
                $value = (int) $value;
            }

            $primaryKey = $this->getTableSchema($table)->getPrimaryField()->getName();
            $relationship = $field->getRelationship();
            if ($relationship->getType() == 'ONETOMANY') {
                $arguments = [
                    $primaryKey,
                    $relationship->getCollectionB(),
                    null,
                    $relationship->getJunctionKeyB(),
                    $value
                ];
            } else {
                $arguments = [
                    $primaryKey,
                    $relationship->getJunctionCollection(),
                    $relationship->getJunctionKeyA(),
                    $relationship->getJunctionKeyB(),
                    $value
                ];
            }
        }

        // TODO: Move this into QueryBuilder if possible
        if (in_array($operator, ['like']) && $field->isManyToOne()) {
            $relatedTable = $field->getRelationship()->getCollectionB();
            $tableSchema = SchemaService::getCollection($relatedTable);
            $relatedTableColumns = $tableSchema->getFields();
            $relatedPrimaryColumnName = $tableSchema->getPrimaryField()->getName();
            $query->orWhereRelational($this->getColumnFromIdentifier($column), $relatedTable, $relatedPrimaryColumnName, function (Builder $query) use ($column, $relatedTable, $relatedTableColumns, $value) {
                $query->nestOrWhere(function (Builder $query) use ($relatedTableColumns, $relatedTable, $value) {
                    foreach ($relatedTableColumns as $column) {
                        // NOTE: Only search numeric or string type columns
                        $isNumeric = $this->getSchemaManager()->isNumericType($column->getType());
                        $isString = $this->getSchemaManager()->isStringType($column->getType());
                        if (!$column->isAlias() && ($isNumeric || $isString)) {
                            $query->orWhereLike($column->getName(), $value);
                        }
                    }
                });
            });
        } else {
            call_user_func_array([$query, $method], $arguments);
        }
    }

    /**
     * Process Select Filters (Where conditions)
     *
     * @param Builder $query
     * @param array $filters
     */
    protected function processFilter(Builder $query, array $filters = [])
    {
        $filters = $this->parseDotFilters($query, $filters);

        foreach ($filters as $column => $condition) {
            if ($condition instanceof Filter) {
                $column =  $condition->getIdentifier();
                $condition = $condition->getValue();
            }

            $this->doFilter($query, $column, $condition, $this->getTable());
        }
    }

    /**
     * Process column joins
     *
     * @param Builder $query
     * @param array $joins
     */
    protected function processJoins(Builder $query, array $joins = [])
    {
        // @TODO allow passing columns
        $columns = []; // leave as this and won't get any ambiguous columns
        foreach ($joins as $table => $params) {
            // TODO: Reduce this into a simpler instructions
            // by simpler it means remove the duplicate join() line
            if (isset($params['on'])) {
                // simple joins style
                // 'table' => ['on' => ['col1', 'col2'] ]
                if (!isset($params['type'])) {
                    $params['type'] = 'INNER';
                }

                $params['on'] = implode('=', $params['on']);

                $query->join($table, $params['on'], $columns, $params['type']);
            } else {
                // many join style
                // 'table' => [ ['on' => ['col1', 'col2'] ] ]
                foreach ($params as $method => $options) {
                    if (! isset($options['type'])) {
                        $options['type'] = 'INNER';
                    }
                    $query->join($table, $options['on'], $columns, $options['type']);
                }
            }
        }
    }

    /**
     * Process group-by
     *
     * @param Builder $query
     * @param array|string $columns
     */
    protected function processGroups(Builder $query, $columns = [])
    {
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $query->groupBy($columns);
    }

    /**
     * Process Query search
     *
     * @param Builder $query
     * @param $search
     */
    protected function processQ(Builder $query, $search)
    {
        $columns = SchemaService::getAllCollectionFields($this->getTable());
        $table = $this->getTable();

        $query->nestWhere(function (Builder $query) use ($columns, $search, $table) {
            foreach ($columns as $column) {
                // NOTE: Only search numeric or string type columns
                $isNumeric = $this->getSchemaManager()->isNumericType($column->getType());
                $isString = $this->getSchemaManager()->isStringType($column->getType());
                if (!$isNumeric && !$isString) {
                    continue;
                }

                if ($column->isManyToOne()) {
                    $relationship = $column->getRelationship();
                    $relatedTable = $relationship->getCollectionB();
                    $tableSchema = SchemaService::getCollection($relatedTable);
                    $relatedTableColumns = $tableSchema->getFields();
                    $relatedPrimaryColumnName = $tableSchema->getPrimaryKeyName();
                    $query->orWhereRelational($column->getName(), $relatedTable, $relatedPrimaryColumnName, function (Builder $query) use ($column, $relatedTable, $relatedTableColumns, $search) {
                        $query->nestOrWhere(function (Builder $query) use ($relatedTableColumns, $relatedTable, $search) {
                            foreach ($relatedTableColumns as $column) {
                                // NOTE: Only search numeric or string type columns
                                $isNumeric = $this->getSchemaManager()->isNumericType($column->getType());
                                $isString = $this->getSchemaManager()->isStringType($column->getType());
                                if (!$column->isAlias() && ($isNumeric || $isString)) {
                                    $query->orWhereLike($column->getName(), $search);
                                }
                            }
                        });
                    });
                } else if ($column->isOneToMany()) {
                    $relationship = $column->getRelationship();
                    $relatedTable = $relationship->getCollectionB();
                    $relatedRightColumn = $relationship->getJunctionKeyB();
                    $relatedTableColumns = SchemaService::getAllCollectionFields($relatedTable);

                    $query->from($table);
                    // TODO: Test here it may be not setting the proper primary key name
                    $query->orWhereRelational($this->primaryKeyFieldName, $relatedTable, null, $relatedRightColumn, function(Builder $query) use ($column, $relatedTable, $relatedTableColumns, $search) {
                        foreach ($relatedTableColumns as $column) {
                            // NOTE: Only search numeric or string type columns
                            $isNumeric = $this->getSchemaManager()->isNumericType($column->getType());
                            $isString = $this->getSchemaManager()->isStringType($column->getType());
                            if (!$column->isAlias() && ($isNumeric || $isString)) {
                                $query->orWhereLike($column->getName(), $search, false);
                            }
                        }
                    });
                } else if ($column->isManyToMany()) {
                    // @TODO: Implement Many to Many search
                } else if (!$column->isAlias()) {
                    $query->orWhereLike($column->getName(), $search);
                }
            }
        });
    }

    /**
     * Process Select Order
     *
     * @param Builder $query
     * @param array $columns
     *
     * @throws Exception\InvalidFieldException
     */
    protected function processSort(Builder $query, array $columns)
    {
        foreach ($columns as $column) {
            $compact = compact_sort_to_array($column);
            $orderBy = key($compact);
            $orderDirection = current($compact);

            if (!SchemaService::hasCollectionField($this->table, $orderBy, $this->acl === null)) {
                throw new Exception\InvalidFieldException($column);
            }

            $query->orderBy($orderBy, $orderDirection);
        }
    }

    /**
     * Process Select Limit
     *
     * @param Builder $query
     * @param int $limit
     */
    protected function processLimit(Builder $query, $limit)
    {
        $query->limit((int) $limit);
    }

    /**
     * Process Select offset
     *
     * @param Builder $query
     * @param int $offset
     */
    protected function processOffset(Builder $query, $offset)
    {
        $query->offset((int) $offset);
    }

    /**
     * Apply legacy params to support old api requests
     *
     * @param Builder $query
     * @param array $params
     *
     * @throws Exception\FieldNotFoundException
     */
    protected function applyLegacyParams(Builder $query, array $params = [])
    {
        $skipAcl = $this->acl === null;
        if (ArrayUtils::get($params, 'status') && SchemaService::hasStatusField($this->getTable(), $skipAcl)) {
            $statuses = $params['status'];
            if (!is_array($statuses)) {
                $statuses = array_map(function($item) {
                    return trim($item);
                }, explode(',', $params['status']));
            }

            // $statuses = array_filter($statuses, function ($value) {
            //     return is_numeric($value);
            // });

            if ($statuses) {
                $query->whereIn(SchemaService::getStatusFieldName(
                    $this->getTable(),
                    $this->acl === null
                ), $statuses);
            }
        }

        if (ArrayUtils::has($params, 'id')) {
            $entriesIds = $params['id'];
            if (is_string($entriesIds)) {
                $entriesIds = StringUtils::csv($entriesIds, false);
            }

            if (!is_array($entriesIds)) {
                $entriesIds = [$entriesIds];
            }

            $idsCount = count($entriesIds);
            if ($idsCount > 0) {
                $query->whereIn($this->primaryKeyFieldName, $entriesIds);
                $query->limit($idsCount);
            }
        }

        if (!ArrayUtils::has($params, 'q')) {
            $search = ArrayUtils::get($params, 'search', '');

            if ($search) {
                $columns = SchemaService::getAllNonAliasCollectionFields($this->getTable());
                $query->nestWhere(function (Builder $query) use ($columns, $search) {
                    foreach ($columns as $column) {
                        if ($column->getType() === 'VARCHAR' || $column->getType()) {
                            $query->whereLike($column->getName(), $search);
                        }
                    }
                }, 'or');
            }
        }
    }

    /**
     * Throws error if column or relation is missing values
     * @param  array $column One schema column representation.
     * @param  array $requiredKeys Values requiring definition.
     * @param  string $tableName
     * @return void
     * @throws  \Directus\Database\Exception\RelationshipMetadataException If the required values are undefined.
     */
    private function enforceColumnHasNonNullValues($column, $requiredKeys, $tableName)
    {
        $erroneouslyNullKeys = [];
        foreach ($requiredKeys as $key) {
            if (!isset($column[$key]) || (strlen(trim($column[$key])) === 0)) {
                $erroneouslyNullKeys[] = $key;
            }
        }
        if (!empty($erroneouslyNullKeys)) {
            $msg = 'Required column/ui metadata columns on table ' . $tableName . ' lack values: ';
            $msg .= implode(' ', $requiredKeys);
            throw new Exception\RelationshipMetadataException($msg);
        }
    }

    /**
     * Load one to many relational data
     *
     * @param array $entries
     * @param Field[] $columns
     * @param array $params
     *
     * @return bool|array
     */
    public function loadOneToManyRelationships($entries, $columns, array $params = [])
    {
        $columnsTree = get_unflat_columns($columns);
        $visibleColumns = $this->getTableSchema()->getFields(array_keys($columnsTree));
        foreach ($visibleColumns as $alias) {
            if (!$alias->isAlias() || !$alias->isOneToMany()) {
                continue;
            }

            $relatedTableName = $alias->getRelationship()->getCollectionB();
            if ($this->acl && !SchemaService::canGroupReadCollection($relatedTableName)) {
                continue;
            }

            $primaryKey = $this->primaryKeyFieldName;
            $callback = function($row) use ($primaryKey) {
                return ArrayUtils::get($row, $primaryKey, null);
            };

            $ids = array_unique(array_filter(array_map($callback, $entries)));
            if (empty($ids)) {
                continue;
            }

            // Only select the fields not on the currently authenticated user group's read field blacklist
            $relationalColumnName = $alias->getRelationship()->getFieldB();
            $tableGateway = new RelationalTableGateway($relatedTableName, $this->adapter, $this->acl);
            $filterFields = get_array_flat_columns($columnsTree[$alias->getName()]);
            $filters = [];
            if (ArrayUtils::get($params, 'lang')) {
                $langIds = StringUtils::csv(ArrayUtils::get($params, 'lang'));
                $filters[$alias->getOptions('left_column_name')] = ['in' => $langIds];
            }

            $results = $tableGateway->loadEntries(array_merge([
                'fields' => array_merge([$relationalColumnName], $filterFields),
                // Fetch all related data
                'limit' => -1,
                'filter' => array_merge($filters, [
                    $relationalColumnName => ['in' => $ids]
                ]),
            ], $params));

            $relatedEntries = [];
            $selectedFields = $tableGateway->getSelectedFields($filterFields);

            foreach ($results as $row) {
                // Quick fix
                // @NOTE: When fetching a column that also has another relational field
                // the value is not a scalar value but an array with all the data associated to it.
                // @TODO: Make this result a object so it can be easy to interact.
                // $row->getId(); RowGateway perhaps?
                $relationalColumnId = $row[$relationalColumnName];
                if (is_array($relationalColumnId)) {
                    $relationalColumnId = $relationalColumnId[$tableGateway->primaryKeyFieldName];
                }

                if (!in_array('*', $filterFields)) {
                    $row = ArrayUtils::pick(
                        $row,
                        $selectedFields
                    );
                }

                $relatedEntries[$relationalColumnId][] = $row;
            }

            // Replace foreign keys with foreign rows
            $relationalColumnName = $alias->getName();
            foreach ($entries as &$parentRow) {
                // TODO: Remove all columns not from the original selection
                // meaning remove the related column and primary key that were selected
                // but weren't requested at first but were forced to be selected
                // within directus as directus needs the related and the primary keys to work properly
                $rows = ArrayUtils::get($relatedEntries, $parentRow[$primaryKey], []);
                $rows = $this->applyHook('load.relational.onetomany', $rows, ['column' => $alias]);
                $parentRow[$relationalColumnName] = $rows;
            }
        }

        return $entries;
    }

    /**
     * Load many to many relational data
     *
     * @param array $entries
     * @param Field[] $columns
     * @param array $params
     *
     * @return bool|array
     */
    public function loadManyToManyRelationships($entries, $columns, array $params = [])
    {
        $columnsTree = get_unflat_columns($columns);
        $visibleFields = $this->getTableSchema()->getFields(array_keys($columnsTree));

        foreach ($visibleFields as $alias) {
            if (!$alias->isAlias() || !$alias->isManyToMany()) {
                continue;
            }

            $relatedTableName = $alias->getRelationship()->getCollectionB();
            if ($this->acl && !SchemaService::canGroupReadCollection($relatedTableName)) {
                continue;
            }

            $primaryKey = $this->primaryKeyFieldName;
            $callback = function($row) use ($primaryKey) {
                return ArrayUtils::get($row, $primaryKey, null);
            };

            $ids = array_unique(array_filter(array_map($callback, $entries)));
            if (empty($ids)) {
                continue;
            }

            $junctionKeyLeftColumn = $alias->getRelationship()->getJunctionKeyA();
            $junctionTableName = $alias->getRelationship()->getJunctionCollection();
            $junctionTableGateway = new RelationalTableGateway($junctionTableName, $this->getAdapter(), $this->acl);
            $junctionPrimaryKey = SchemaService::getCollectionPrimaryKey($junctionTableName);

            $selectedFields = null;
            $fields = $columnsTree[$alias->getName()];
            if ($fields) {
                $selectedFields = get_array_flat_columns($fields);
                array_unshift($selectedFields, $junctionPrimaryKey);
            }

            $results = $junctionTableGateway->loadEntries(array_merge([
                // Fetch all related data
                'limit' => -1,
                // Add the aliases of the join columns to prevent being removed from array
                // because there aren't part of the "visible" columns list
                'fields' => $selectedFields,
                'filter' => [
                    new In(
                        $junctionKeyLeftColumn,
                        $ids
                    )
                ],
            ], $params));

            $relationalColumnName = $alias->getName();
            $relatedEntries = [];
            foreach ($results as $row) {
                $relatedEntries[$row[$junctionKeyLeftColumn]][] = $row;
            }

            // Replace foreign keys with foreign rows
            foreach ($entries as &$parentRow) {
                $parentRow[$relationalColumnName] = ArrayUtils::get(
                    $relatedEntries,
                    $parentRow[$primaryKey],
                    []
                );
            }
        }

        return $entries;
    }

    /**
     * Fetch related, foreign rows for a whole rowset's ManyToOne relationships.
     * (Given a table's schema and rows, iterate and replace all of its foreign
     * keys with the contents of these foreign rows.)
     *
     * @param array $entries Table rows
     * @param Field[] $columns
     * @param array $params
     *
     * @return array Revised table rows, now including foreign rows
     *
     * @throws Exception\RelationshipMetadataException
     */
    public function loadManyToOneRelationships($entries, $columns, array $params = [])
    {
        $columnsTree = get_unflat_columns($columns);
        $visibleColumns = $this->getTableSchema()->getFields(array_keys($columnsTree));
        foreach ($visibleColumns as $column) {
            if (!$column->isManyToOne()) {
                continue;
            }

            $relatedTable = $column->getRelationship()->getCollectionB();

            // if user doesn't have permission to view the related table
            // fill the data with only the id, which the user has permission to
            if ($this->acl && !SchemaService::canGroupReadCollection($relatedTable)) {
                $tableGateway = new RelationalTableGateway($relatedTable, $this->adapter, null);
                $primaryKeyName = $tableGateway->primaryKeyFieldName;

                foreach ($entries as $i => $entry) {
                    $entries[$i][$column->getName()] = [
                        $primaryKeyName => $entry[$column->getName()]
                    ];
                }

                continue;
            }

            $tableGateway = new RelationalTableGateway($relatedTable, $this->adapter, $this->acl);
            $primaryKeyName = $tableGateway->primaryKeyFieldName;

            if (!$relatedTable) {
                $message = 'Non single_file Many-to-One relationship lacks `related_table` value.';

                if ($column->getName()) {
                    $message .= ' Column: ' . $column->getName();
                }

                if ($column->getCollectionName()) {
                    $message .= ' Table: ' . $column->getCollectionName();
                }

                throw new Exception\RelationshipMetadataException($message);
            }

            // Aggregate all foreign keys for this relationship (for each row, yield the specified foreign id)
            $relationalColumnName = $column->getName();
            $yield = function ($row) use ($relationalColumnName, $entries, $primaryKeyName) {
                if (array_key_exists($relationalColumnName, $row)) {
                    $value = $row[$relationalColumnName];
                    if (is_array($value)) {
                        $value = isset($value[$primaryKeyName]) ? $value[$primaryKeyName] : 0;
                    }

                    return $value;
                }
            };

            $ids = array_unique(array_filter(array_map($yield, $entries)));
            if (empty($ids)) {
                continue;
            }

            $filterColumns = get_array_flat_columns($columnsTree[$column->getName()]);
            // Fetch the foreign data
            $results = $tableGateway->loadEntries(array_merge([
                // Fetch all related data
                'limit' => -1,
                // Make sure to include the primary key
                'fields' => array_merge([$primaryKeyName], $filterColumns),
                'filter' => [
                    $primaryKeyName => ['in' => $ids]
                ],
            ], $params));

            $relatedEntries = [];
            foreach ($results as $row) {
                $rowId = $row[$primaryKeyName];
                if (!in_array('*', $filterColumns)) {
                    $row = ArrayUtils::pick(
                        $row,
                        $tableGateway->getSelectedFields($filterColumns)
                    );
                }

                $relatedEntries[$rowId] = $row;

                $tableGateway->wrapData(
                    $relatedEntries[$rowId],
                    true,
                    ArrayUtils::get($params, 'meta', 0)
                );
            }

            // Replace foreign keys with foreign rows
            foreach ($entries as &$parentRow) {
                if (array_key_exists($relationalColumnName, $parentRow)) {
                    // @NOTE: Not always will be a integer
                    $foreign_id = (int)$parentRow[$relationalColumnName];
                    $parentRow[$relationalColumnName] = null;
                    // "Did we retrieve the foreign row with this foreign ID in our recent query of the foreign table"?
                    if (array_key_exists($foreign_id, $relatedEntries)) {
                        $parentRow[$relationalColumnName] = $relatedEntries[$foreign_id];
                    }
                }
            }
        }

        return $entries;
    }

    /**
     *
     * HELPER FUNCTIONS
     *
     **/

    /**
     * @param $fields
     *
     * @return array
     */
    public function getSelectedFields(array $fields)
    {
        return $this->replaceWildcardFieldWith(
            $fields,
            SchemaService::getAllCollectionFieldsName($this->getTable())
        );
    }

    /**
     * Gets the non alias fields from the selected fields
     *
     * @param array $fields
     *
     * @return array
     */
    public function getSelectedNonAliasFields(array $fields)
    {
        $nonAliasFields = SchemaService::getAllNonAliasCollectionFieldsName($this->getTableSchema()->getName());
        $allFields = $this->replaceWildcardFieldWith(
            $fields,
            $nonAliasFields
        );

        // Remove alias fields
        return ArrayUtils::intersection(
            $allFields,
            $nonAliasFields
        );
    }

    /**
     * Returns the related fields from the selected fields array
     *
     * @param array $fields
     *
     * @return array
     */
    public function getSelectedRelatedFields(array $fields)
    {
        $fieldsLevel = get_unflat_columns($fields);

        foreach ($fieldsLevel as $parent => $children) {
            if ($parent === '*') {
                $parentFields = $fieldsLevel[$parent];
                unset($fieldsLevel[$parent]);
                $allFields = SchemaService::getAllCollectionFieldsName($this->getTable());
                foreach ($allFields as $field) {
                    if (isset($fieldsLevel[$field])) {
                        continue;
                    }

                    $fieldsLevel[$field] = $parentFields;
                }

                break;
            }
        }

        $relatedFields = ArrayUtils::intersection(
            array_keys($fieldsLevel),
            $this->getTableSchema()->getRelationalFieldsName()
        );

        return array_filter($fieldsLevel, function ($key) use ($relatedFields) {
            return in_array($key, $relatedFields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Remove the wildcards fields and append the replacement fields
     *
     * @param array $fields
     * @param array $replacementFields
     *
     * @return array
     */
    protected function replaceWildcardFieldWith(array $fields, array $replacementFields)
    {
        $selectedNames = get_columns_flat_at($fields, 0);
        // remove duplicate field name
        $selectedNames = array_unique($selectedNames);

        $wildCardIndex = array_search('*', $selectedNames);
        if ($wildCardIndex !== false) {
            unset($selectedNames[$wildCardIndex]);
            $selectedNames = array_merge($selectedNames, $replacementFields);
        }

        $pickedNames = array_filter($selectedNames, function ($value) {
            return strpos($value, '-') !== 0;
        });
        $omittedNames = array_values(array_map(function ($value) {
            return substr($value, 1);
        }, array_filter($selectedNames, function ($value) {
            return strpos($value, '-') === 0;
        })));

        return array_values(array_flip(ArrayUtils::omit(array_flip($pickedNames), $omittedNames)));
    }

    /**
     * Throws an exception if any of the given field doesn't exists
     *
     * @param array $fields
     *
     * @throws Exception\InvalidFieldException
     */
    public function validateFields(array $fields)
    {
        $collection = $this->getTableSchema();
        $selectedFields = $this->getSelectedFields($fields);

        foreach ($selectedFields as $field) {
            if (!$collection->hasField($field)) {
                throw new Exception\InvalidFieldException($field);
            }
        }
    }

    /**
     * Does this record representation contain non-primary-key information?
     * Used to determine whether or not to update a foreign record, above and
     * beyond simply assigning it to a parent.
     * @param  array|RowGateway $record
     * @param  string $pkFieldName
     * @return boolean
     */
    public function recordDataContainsNonPrimaryKeyData($record, $pkFieldName = 'id')
    {
        if (is_subclass_of($record, 'Zend\Db\RowGateway\AbstractRowGateway')) {
            $record = $record->toArray();
        } elseif (!is_array($record)) {
            throw new \InvalidArgumentException('$record must an array or a subclass of AbstractRowGateway');
        }

        $keyCount = count($record);

        return array_key_exists($pkFieldName, $record) ? $keyCount > 1 : $keyCount > 0;
    }

    /**
     * Update a collection of records within this table.
     * @param  array $entries Array of records.
     * @return void
     */
    public function updateCollection($entries)
    {
        $entries = ArrayUtils::isNumericKeys($entries) ? $entries : [$entries];
        foreach ($entries as $entry) {
            $entry = $this->updateRecord($entry);
            $entry->save();
        }
    }

    /**
     * Get the total entries count
     *
     * @param PredicateInterface|null $predicate
     *
     * @return int
     */
    public function countTotal(PredicateInterface $predicate = null)
    {
        $select = new Select($this->table);
        $select->columns(['total' => new Expression('COUNT(*)')]);
        if (!is_null($predicate)) {
            $select->where($predicate);
        }

        $sql = new Sql($this->adapter, $this->table);
        $statement = $sql->prepareStatementForSqlObject($select);
        $results = $statement->execute();
        $row = $results->current();

        return (int) $row['total'];
    }

    /**
     * Only run on tables which have an status column.
     * @return array
     */
    public function countActive()
    {
        return $this->countByStatus();
    }

    public function countByStatus()
    {
        $collection = $this->schemaManager->getCollection($this->getTable());
        if (!$collection->hasStatusField()) {
            return [];
        }

        $statusFieldName = $collection->getStatusField()->getName();

        $select = new Select($this->getTable());
        $select
            ->columns([$statusFieldName, 'quantity' => new Expression('COUNT(*)')])
            ->group($statusFieldName);

        $sql = new Sql($this->adapter, $this->table);
        $statement = $sql->prepareStatementForSqlObject($select);
        $results = $statement->execute();

        $statusMap = $this->getStatusMapping();
        $stats = [];
        foreach ($results as $row) {
            if (isset($row[$statusFieldName])) {
                foreach ($statusMap as $status) {
                    if ($status->getValue() == $row[$statusFieldName]) {
                        $stats[$status->getName()] = (int) $row['quantity'];
                    }
                }
            }
        }

        $vals = [];
        foreach ($statusMap as $value) {
            array_push($vals, $value->getName());
        }

        $possibleValues = array_values($vals);
        $makeMeZero = array_diff($possibleValues, array_keys($stats));
        foreach ($makeMeZero as $unsetActiveColumn) {
            $stats[$unsetActiveColumn] = 0;
        }

        $stats['total_entries'] = array_sum($stats);

        return $stats;
    }
}
