<?php

namespace QueryMaker;

/**
 * Class QueryMaker.
 */
class QueryMaker
{
    private $tables;
    private $validComparisonOperators = ['LIKE', 'NOT LIKE', '=', '!=', '<>', '<', '<=', '>', '>=', '<=>', 'IS NOT',
        'IS', 'IS NOT NULL', 'IS NULL', 'IN', 'NOT IN', ];
    private $validLogicalOperators = ['AND', 'OR', 'XOR', 'NOT'];

    /**
     * QueryMaker constructor.
     *
     * @param null $tables
     */
    public function __construct($tables = null)
    {
        if ($tables !== null) {
            if (is_array($tables)) {
                $this->setTables($tables);
                return;
            }

            // $tables is string, treat it as a table
            $this->addTable(
                $tables,
                [
                    'name' => $tables,
                ]
            );
        }
    }

    /**
     * @param array $criteria
     * @param       $fromColumns
     * @param       $order
     * @param       $start
     * @param       $limit
     *
     * @throws \Exception
     *
     * @return string
     */
    public function selectQuery(array $criteria = [], $fromColumns = '*', $order = '', $start = 0, $limit = 0)
    {
        // append from columns
        if (empty($fromColumns) && $fromColumns !== null) {
            // from all
            $fromColumns = '*';
        } elseif (is_array($fromColumns)) {
            $fromColumns = implode(', ', $fromColumns);
        }

        $query = $this->getSelectFromTables($fromColumns);

        // append where clause - criteria
        $query .= $this->where($criteria);

        // append order by if it is specified
        $query .= $this->orderBy($order);

        // append start and limit if they are specified
        $query .= $this->startLimit($start, $limit);

        $query .= ';';

        return $query;
    }

    /**
     * @param array $criteria
     * @param       $fieldsValues
     * @param       $start
     * @param       $limit
     *
     * @throws \Exception
     *
     * @return string
     */
    public function updateQuery(array $criteria, $fieldsValues, $start, $limit)
    {
        if (empty($fieldsValues)) {
            throw new \Exception('fieldsValues cannot be empty in updateQuery function');
        }

        $query = "UPDATE {$this->getTable()['name']} SET ";

        // point to end of the array
        end($fieldsValues);

        // fetch key of the last element of the array.
        $lastFieldKey = key($fieldsValues);

        foreach ($fieldsValues as $fieldValueKey => $fieldValue) {
            // Do not append comma if it is the last element
            $comma = $lastFieldKey === $fieldValueKey ? '' : ', ';

            if (isset($fieldValue['bind']) && $fieldValue['bind'] === false) {
                $query .= "{$fieldValue['column']} = {$fieldValue['value']}{$comma}";
                continue;
            }

            $placeholder = $this->preparePlaceholder($fieldValue['column']);
            $query .= "{$fieldValue['column']} = :{$placeholder}{$comma}";
        }

        $query = rtrim($query);

        $query .= $this->where($criteria);
        $query .= $this->startLimit($start, $limit);
        $query .= ';';

        return $query;
    }

    /**
     * @param array $fieldsValues
     *
     * @throws \Exception
     *
     * @return string
     */
    public function insertQuery(array $fieldsValues)
    {
        if (empty($fieldsValues)) {
            throw new \Exception('fieldsValues cannot be empty in insertQuery function');
        }

        $query = "INSERT INTO {$this->getTable()['name']}";

        $columns = [];
        $parameters = [];

        foreach ($fieldsValues as $fieldValue) {
            $columns[] = "{$fieldValue['column']}";

            if (isset($fieldValue['bind']) && $fieldValue['bind'] === false) {
                $parameters[] = "{$fieldValue['value']}";
                continue;
            }

            $placeholder = $this->preparePlaceholder($fieldValue['column']);
            $parameters[] = ":{$placeholder}";
        }

        if (!empty($columns) && !empty($parameters)) {
            $query .= ' ('.implode(',', $columns).') VALUES ('.implode(',', $parameters).');';
        }

        return $query;
    }

    /**
     * @param array $fieldValueCollection
     *
     * @throws \Exception
     *
     * @return string
     */
    public function batchInsertQuery(array $fieldValueCollection)
    {
        if (empty($fieldValueCollection)) {
            throw new \Exception('fieldsValues cannot be empty in insertQuery function');
        }

        $query = "INSERT INTO {$this->getTable()['name']}";

        $parametersArray = [];
        foreach ($fieldValueCollection as $key => $fieldsValues) {
            // reset parameters
            $columns = [];
            $parameters = [];

            foreach ($fieldsValues as $fieldValue) {
                $columns[] = "{$fieldValue['column']}";

                if (isset($fieldValue['bind']) && $fieldValue['bind'] === false) {
                    $parameters[] = "{$fieldValue['value']}";
                    continue;
                }

                $placeholder = $this->preparePlaceholder($fieldValue['column']);
                $parameters[] = ":{$placeholder}{$key}";
            }

            $parameters = implode(',', $parameters);
            $parametersArray[] = "({$parameters})";
        }

        if (!empty($columns) && !empty($parameters)) {
            $query .= ' ('.implode(',', $columns).') VALUES '.implode(',', $parametersArray).';';
        }

        return $query;
    }

    /**
     * @param array $criteria
     * @param       $start
     * @param       $limit
     *
     * @throws \Exception
     *
     * @return string
     */
    public function deleteQuery(array $criteria, $start, $limit)
    {
        $query = "DELETE FROM {$this->getTable()['name']}";
        $query .= $this->where($criteria);
        $query .= $this->startLimit($start, $limit);
        $query .= ';';

        return $query;
    }

    /**
     * @param array $criteria
     *
     * @throws \Exception
     *
     * @return string
     */
    public function countQuery(array $criteria)
    {
        $query = $this->getSelectFromTables('COUNT(*)');
        $query .= $this->where($criteria);
        $query .= ';';

        return $query;
    }

    private function validateCriteria(array $criteria)
    {
        if (empty($criteria['column'])) {
            throw new \Exception('Column name cannot be empty');
        }

        if (!empty($criteria['operator']) && !in_array($criteria['operator'], $this->validComparisonOperators)) {
            throw new \Exception("'{$criteria['operator']}' is not a valid comparison operator");
        }

        if (!empty($criteria['logicalOperator']) && !in_array($criteria['logicalOperator'], $this->validLogicalOperators)) {
            throw new \Exception("'{$criteria['logicalOperator']}' is not a valid logical operator");
        }
    }
    
    /**
     * @param array $criteria
     *
     * @throws \Exception
     *
     * @return string
     */
    private function where(array $criteria)
    {
        if (empty($criteria)) {
            return '';
        }

        $where = ' WHERE ';
        $counter = 0;
        $nested = [];
        foreach ($criteria as $aCriteria) {
            $counter++;
            if (!empty($aCriteria['nested'])) {
                // check placeholder is not already added
                $before = isset($aCriteria['nested']['before']) && $counter != 1 ? "{$aCriteria['nested']['before']} " : '';
                $after = isset($aCriteria['nested']['after']) ? " {$aCriteria['nested']['after']}" : '';
                $placeholder = $before.'({'.$aCriteria['nested']['key'].'})'.$after.' ';
                $placeholderExists = strpos($where, $placeholder);

                // if placeholder does not exist append it to where clause
                if ($placeholderExists === false) {
                    // add placeholder
                    $where .= $placeholder;
                }
            }

            $this->validateCriteria($aCriteria);
            
            if (empty($aCriteria['operator'])) {
                // If operator is not specified, consider '=' as the operator
                $aCriteria['operator'] = $this->getDefaultComparisonOperator();
            }

            if ($counter === 1 || !empty($before) || !empty($after)) {
                $logicalOperator = '';
            } elseif (empty($aCriteria['logicalOperator'])) {
                // counter is greater than 1 and $aCriteria['logicalOperator'] is empty, consider 'AND' as default
                $logicalOperator = $this->getDefaultLogicalOperator().' ';
            } else {
                // counter is greater than 1 and $aCriteria['logicalOperator'] is NOT empty
                $logicalOperator = "{$aCriteria['logicalOperator']} ";
            }

            $toBeAppended = "{$logicalOperator}{$aCriteria['column']} {$aCriteria['operator']} ";
            // Form the query for IN or NOT IN
            if ($aCriteria['operator'] === 'IN' || $aCriteria['operator'] === 'NOT IN') {
                if (is_array($aCriteria['value'])) {
                    // value is array
                    $newParameters = [];
                    $aCriteriaKeys = array_keys($aCriteria['value']);
                    foreach ($aCriteriaKeys as $key) {
                        $placeholder = $this->preparePlaceholder($aCriteria['column']);
                        $newParameters[] = ":{$placeholder}{$counter}{$key}";
                    }

                    $toBeAppended .= '('.implode(',', $newParameters).') ';
                } else {
                    // value is not array
                    $placeholder = $this->preparePlaceholder($aCriteria['column']);
                    $toBeAppended .= "(:{$placeholder}{$counter}) ";
                }
            } else {
                // IS NULL and IS NOT NULL do NOT need a parameter
                if ($aCriteria['operator'] !== 'IS NULL' && $aCriteria['operator'] !== 'IS NOT NULL') {
                    $placeholder = $this->preparePlaceholder($aCriteria['column']);
                    $toBeAppended .= ":{$placeholder}{$counter} ";
                }
            }

            if (empty($aCriteria['nested'])) {
                $where .= $toBeAppended;
                continue;
            }

            // append it to the key in $nested
            if (!isset($nested[$aCriteria['nested']['key']])) {
                $nested[$aCriteria['nested']['key']] = $toBeAppended;
                continue;
            }
            
            $nested[$aCriteria['nested']['key']] .= $toBeAppended;
        }

        if (!empty($nested)) {
            $where = $this->populateNestedPlaceholders($nested, $where);
        }

        return rtrim($where);
    }

    private function populateNestedPlaceholders($nested, $where)
    {
        if (empty($nested)) {
            return $where;
        }

        foreach ($nested as $aNestedKey => $aNestedValue) {
            // find and replace $aNestedKey placeholder in where clause with the nested query
            $where = str_replace('{'.$aNestedKey.'}', rtrim($aNestedValue), $where);
        }

        return $where;
    }

    private function getDefaultLogicalOperator()
    {
        return 'AND';
    }
    
    private function getDefaultComparisonOperator()
    {
        return '=';
    }

    /**
     * @param $field
     *
     * @return string
     */
    private function orderBy($field)
    {
        if (empty($field)) {
            return '';
        }
        
        return " ORDER BY {$field}";
    }

    /**
     * @param int $start
     * @param int $limit
     *
     * @return string
     */
    private function startLimit($start = 0, $limit = 0)
    {
        if (!empty($start) && !empty($limit)) {
            return ' LIMIT :start, :limit';
        }

        if (!empty($limit)) {
            return ' LIMIT :limit';
        }

        return '';
    }

    /**
     * @param \PDOStatement $statement
     * @param array         $criteria
     * @param int           $start
     * @param int           $limit
     * @param array         $fieldsValues is used for update query
     *
     * @return \PDOStatement
     */
    public function bindValues(\PDOStatement $statement, array $criteria, $start = 0, $limit = 0, array $fieldsValues = [])
    {
        // bind criteria values
        $statement = $this->bindCriteria($statement, $criteria);

        // bind field values
        $statement = $this->bindFieldsValues($statement, $fieldsValues);

        // bind start and limit if they are specified
        if (!empty($start) && !empty($limit)) {
            $statement->bindValue(':start', (int) $start, \PDO::PARAM_INT);
            $statement->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
        } elseif (!empty($limit)) {
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }

        return $statement;
    }

    private function bindFieldsValues(\PDOStatement $statement, $fieldsValues)
    {
        if (empty($fieldsValues)) {
            return $statement;
        }

        foreach ($fieldsValues as $fieldValue) {
            if (isset($fieldValue['bind']) && $fieldValue['bind'] === false) {
                continue;
            }

            // set the type to string if it is empty
            if (empty($fieldValue['type'])) {
                $fieldValue['type'] = \PDO::PARAM_STR;
            }

            $placeholder = $this->preparePlaceholder($fieldValue['column']);
            $statement->bindValue(':'.$placeholder, $fieldValue['value'], $fieldValue['type']);
        }
        
        return $statement;
    }
    
    /**
     * @param \PDOStatement $statement
     * @param array         $criteria
     *
     * @return \PDOStatement
     */
    private function bindCriteria(\PDOStatement $statement, array $criteria)
    {
        if (empty($criteria)) {
            return $statement;
        }

        // bind criteria values
        $counter = 0;
        foreach ($criteria as $aCriteria) {
            $counter++;

            if (empty($aCriteria['operator'])) {
                // If operator is not specified, consider = as the operator
                $aCriteria['operator'] = $this->getDefaultComparisonOperator();
            }

            if ($aCriteria['operator'] === 'IS NULL' || $aCriteria['operator'] === 'IS NOT NULL') {
                continue;
            }

            $placeholder = $this->preparePlaceholder($aCriteria['column']);
            if ($aCriteria['operator'] === 'IN' || $aCriteria['operator'] === 'NOT IN') {
                if (is_array($aCriteria['value'])) {
                    // value is array
                    foreach ($aCriteria['value'] as $key => $value) {
                        // to override the automatic detection $aCriteria['type'] needs to be passed
                        $type = empty($aCriteria['type']) ? $this->detectParameterType($value) : $aCriteria['type'];
                        $statement->bindValue(':'.$placeholder.$counter.$key, $value, $type);
                    }

                    continue;
                }

                // value is not array
                // to override the automatic detection $aCriteria['type'] needs to be passed
                $type = empty($aCriteria['type']) ? $this->detectParameterType($aCriteria['value']) : $aCriteria['type'];
                $statement->bindValue(':'.$placeholder.$counter, $aCriteria['value'], $type);

                continue;
            }

            // set the type to string if it is empty
            if (empty($aCriteria['type'])) {
                $aCriteria['type'] = \PDO::PARAM_STR;
            }

            $statement->bindValue(':'.$placeholder.$counter, $aCriteria['value'], $aCriteria['type']);
        }

        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param array         $criteria
     * @param int           $start
     * @param int           $limit
     * @param array         $fieldValueCollection
     *
     * @return \PDOStatement
     */
    public function batchBindValues(
        \PDOStatement $statement,
        array $criteria,
        $start = 0,
        $limit = 0,
        array $fieldValueCollection = []
    ) {
        if (!empty($fieldValueCollection)) {
            // bind criteria values
            $statement = $this->bindCriteria($statement, $criteria);

            foreach ($fieldValueCollection as $fieldsValues) {
                // bind field values
                $statement = $this->bindFieldsValues($statement, $fieldsValues);
            }

            // bind start and limit if they are specified
            if (!empty($start) && !empty($limit)) {
                $statement->bindValue(':start', (int) $start, \PDO::PARAM_INT);
                $statement->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
            } elseif (!empty($limit)) {
                $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
            }

            return $statement;
        }
    }

    /**
     * @param null $tableAlias
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function getTable($tableAlias = null)
    {
        $tables = $this->getTables();
        if ($tableAlias === null) {
            return array_shift($tables);
        }

        if (!array_key_exists($tableAlias, $tables)) {
            throw new \Exception("Requested table does not exist for the alias: {$tableAlias}");
        }

        return $tables[$tableAlias];
    }

    /**
     * @param array $tables
     */
    public function setTables(array $tables)
    {
        $this->tables = $tables;
    }

    /**
     * @return array
     */
    public function getTables()
    {
        return $this->tables;
    }

    /**
     * @param null $fromColumns
     *
     * @throws \Exception
     *
     * @return string
     */
    public function getSelectFromTables($fromColumns = null)
    {
        $joinedSelect = [];
        $counter = 0;
        $from = '';

        if (empty($this->getTables())) {
            throw new \Exception('Tables cannot be empty');
        }

        foreach ($this->getTables() as $tableAlias => $table) {
            $counter++;

            if ($counter === 1) {
                $from .= "`{$table['name']}` AS `{$tableAlias}`";
                continue;
            }

            $from .= " JOIN `{$table['name']}` AS `{$tableAlias}`";

            if (empty($table['on']) || !is_array($table['on'])) {
                throw new \Exception("join array must have 'on'");
            }

            $from .= ' ON '.implode(' = ', $table['on']);
        }

        if ($fromColumns === null) {
            $fromColumns = implode(', ', $joinedSelect);
        } elseif (is_array($fromColumns)) {
            $fromColumns = implode(', ', $fromColumns);
        }

        return "SELECT {$fromColumns} FROM {$from}";
    }

    /**
     * @param $tableAlias
     * @param $table
     */
    public function addTable($tableAlias, $table)
    {
        $tables = $this->getTables();
        $tables[$tableAlias] = $table;
        $this->setTables($tables);
    }

    /**
     * @param $value
     *
     * @return int
     */
    private function detectParameterType($value)
    {
        return is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
    }

    /**
     * PDO placeholders can only be: [a-zA-Z0-9_]+.
     *
     * @param $placeholder
     *
     * @return mixed
     */
    private function preparePlaceholder($placeholder)
    {
        $placeholder = str_replace('`', '', $placeholder);

        return preg_replace('/[^a-zA-Z0-9_]/', '_', $placeholder);
    }
}
