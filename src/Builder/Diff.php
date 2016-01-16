<?php

namespace Pgdbsync\Builder;

class Diff
{
    // @todo extract this configuration out
    private $settings = [
        'alter_owner' => false
    ];

    private $schema;
    private $diff;
    private $summary;
    private $master;
    private $slave;

    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    public function getDiff($master, $slave)
    {
        $this->diff    = [];
        $this->summary = [];
        $this->master = $master;
        $this->slave = $slave;

        // FUNCTIONS
        $masterFunctions = isset($this->master['functions']) ? array_keys((array)$this->master['functions']) : [];
        $slaveFunctions  = isset($this->slave['functions']) ? array_keys((array)$this->slave['functions']) : [];

        // delete deleted functions
        $deletedFunctions = array_diff($slaveFunctions, $masterFunctions);
        if (count($deletedFunctions) > 0) {
            $this->deleteFunctions($deletedFunctions);
        }
        // create new functions
        $newFunctions = array_diff($masterFunctions, $slaveFunctions);

        // check differences
        foreach ($masterFunctions as $functionName) {
            if (!in_array($functionName, $newFunctions)) {
                $definitionMaster = $this->master['functions'][$functionName]['definition'];
                $definitionSlave  = $this->slave['functions'][$functionName]['definition'];

                if (md5($definitionMaster) != md5($definitionSlave)) {
                    $newFunctions[] = $functionName;
                }
            }
        }

        if (count($newFunctions) > 0) {
            $this->createFunctions($newFunctions);
        }

        // SEQUENCES
        $masterSequences = isset($this->master['sequences']) ? array_keys((array)$this->master['sequences']) : [];
        $slaveSequences  = isset($this->slave['sequences']) ? array_keys((array)$this->slave['sequences']) : [];

        // delete deleted sequences
        $deletedSequences = array_diff($slaveSequences, $masterSequences);
        if (count($deletedSequences) > 0) {
            $this->deleteSequences($deletedSequences, $master);
        }
        // create new sequences
        $newSequences = array_diff($masterSequences, $slaveSequences);
        if (count($newSequences) > 0) {
            $this->createSequences($newSequences);
        }

        // VIEWS
        $masterViews = isset($this->master['views']) ? array_keys((array)$this->master['views']) : [];
        $slaveViews  = isset($this->slave['views']) ? array_keys((array)$this->slave['views']) : [];

        // delete deleted views
        $deletedViews = array_diff($slaveViews, $masterViews);
        if (count($deletedViews) > 0) {
            $this->deleteViews($deletedViews);
        }

        // create new views
        $newViews = array_diff($masterViews, $slaveViews);
        if (count($newViews) > 0) {
            $this->createViews($newViews);
        }

        foreach ($masterViews as $view) {
            if (in_array($view, $newViews)) {
                continue;
            }

            if ($this->master['views'][$view]['definition'] !== $this->slave['views'][$view]['definition']) {
                $this->createView($view);
            }
        }
        // TABLES

        $masterTables = isset($this->master['tables']) ? array_keys((array)$this->master['tables']) : [];
        $slaveTables  = isset($this->slave['tables']) ? array_keys((array)$this->slave['tables']) : [];

        // delete deleted tables
        $deletedTables = array_diff($slaveTables, $masterTables);
        if (count($deletedTables) > 0) {
            $this->deleteTables($deletedTables);
        }

        // create new tables
        $newTables = array_diff($masterTables, $slaveTables);
        if (count($newTables) > 0) {
            $this->createTables($newTables);
        }

        foreach ($masterTables as $table) {
            if (in_array($table, $newTables)) {
                continue;
            }

            // check new columns in $master and not in slave
            // check deleted columns in $master (exits in slave and not in master)
            $masterColumns = array_keys((array)$this->master['tables'][$table]['columns']);
            $slaveColumns  = array_keys((array)$this->slave['tables'][$table]['columns']);

            $newColumns = array_diff($masterColumns, $slaveColumns);
            if (count($newColumns) > 0) {
                $this->addColumns($table, $newColumns);
            }

            $deletedColumns = array_diff($slaveColumns, $masterColumns);
            $this->deleteColumns($table, $deletedColumns);

            foreach ($masterColumns as $column) {
                // check modifications (different between $master and slave)
                // check differences in type
                if (isset($this->master['tables'][$table]['columns'][$column]) && isset($this->slave['tables'][$table]['columns'][$column])) {
                    $masterType = $this->master['tables'][$table]['columns'][$column]['type'];
                    $slaveType  = $this->slave['tables'][$table]['columns'][$column]['type'];
                    // check differences in precission
                    $masterPrecission = $this->master['tables'][$table]['columns'][$column]['precision'];
                    $slavePrecission  = $this->slave['tables'][$table]['columns'][$column]['precision'];

                    if ($masterType != $slaveType || $masterPrecission != $slavePrecission) {
                        $this->alterColumn($table, $column);
                    }
                }
            }

            // check new or removed constraints
            $masterConstraints = array_keys((array)$this->master['tables'][$table]['constraints']);
            $slaveConstraints  = array_keys((array)$this->slave['tables'][$table]['constraints']);
            // Delete missing constraints first
            $deletedConstraints = array_diff($slaveConstraints, $masterConstraints);
            if (count($deletedConstraints) > 0) {
                foreach ($deletedConstraints as $deletedConstraint) {
                    $this->dropConstraint($table, $deletedConstraint);
                }
            }
            // then add the new constraints
            $newConstraints = array_diff($masterConstraints, $slaveConstraints);
            if (count($newConstraints) > 0) {
                foreach ($newConstraints as $newConstraint) {
                    $this->addConstraint($table, $newConstraint);
                }
            }
        }

        return [
            'diff'    => $this->diff,
            'summary' => $this->summary
        ];
    }

    private function createTables($tables)
    {
        if (count((array)$tables) > 0) {
            foreach ($tables as $table) {
                $tablespace = $this->master['tables'][$table]['tablespace'];
                $_columns   = [];
                foreach ((array)$this->master['tables'][$table]['columns'] as $column => $columnConf) {
                    $type       = $columnConf['type'];
                    $precision  = $columnConf['precision'];
                    $nullable   = $columnConf['nullable'] ? null : ' NOT NULL';
                    $_columns[] = "{$column} {$type}" . ((!empty($precision)) ? "({$precision})" : null) . $nullable;
                }
                if (array_key_exists('constraints', $this->master['tables'][$table])) {
                    foreach ((array)$this->master['tables'][$table]['constraints'] as $constraint => $constraintInfo) {
                        $columns       = [];
                        $masterColumns = $this->master['tables'][$table]['columns'];
                        foreach ($constraintInfo['columns'] as $column) {
                            foreach ($masterColumns as $masterColumnName => $masterColumn) {
                                if ($masterColumn['order'] == $column) {
                                    $columns[] = $masterColumnName;
                                }
                            }
                        }
                        switch ($constraintInfo['type']) {
                            case 'CHECK':
                                $constraintSrc = $constraintInfo['src'];
                                $_columns[]    = "CONSTRAINT {$constraint} CHECK {$constraintSrc}";
                                break;
                            case 'PRIMARY KEY':
                                $constraintSrc = $constraintInfo['src'];
                                $columns       = implode(', ', $columns);
                                $_columns[]    = "CONSTRAINT {$constraint} PRIMARY KEY ({$columns})";
                                break;
                        }
                    }
                }
                $owner   = $this->master['tables'][$table]['owner'];
                $columns = implode(",\n ", $_columns);
                $buffer  = "\nCREATE TABLE {$this->schema}.{$table}(\n {$columns}\n)";
                if (!empty($tablespace)) {
                    $buffer .= "\nTABLESPACE {$tablespace}";
                }
                $buffer .= ";";

                if ($this->settings['alter_owner'] === true) {
                    $buffer .= "\nALTER TABLE {$this->schema}.{$table} OWNER TO {$owner};";
                }

                if (array_key_exists('grants', $this->master['tables'][$table])) {
                    foreach ((array)$this->master['tables'][$table]['grants'] as $grant) {
                        if (!empty($grant)) {
                            $buffer .= "\nGRANT ALL ON TABLE {$this->schema}.{$table} TO {$grant};";
                        }
                    }
                }
                $this->diff[]                        = $buffer;
                $this->summary['tables']['create'][] = "{$this->schema}.{$table}";
            }
        }
    }

    private function deleteTables($tables)
    {
        if (count((array)$tables) > 0) {
            foreach ($tables as $table) {
                $this->diff[]                      = "\nDROP TABLE {$this->schema}.{$table};";
                $this->summary['tables']['drop'][] = "{$this->schema}.{$table}";
            }
        }
    }

    private function deleteViews($views)
    {
        if (count((array)$views) > 0) {
            foreach ($views as $view) {
                $this->diff[]                     = "DROP VIEW {$this->schema}.{$view};";
                $this->summary['views']['drop'][] = "{$this->schema}.{$view}";
            }
        }
    }

    private function deleteSequences($sequences)
    {
        if (count((array)$sequences) > 0) {
            foreach ($sequences as $sequence) {
                $this->diff[]                        = "DROP SEQUENCE {$this->schema}.{$sequence};";
                $this->summary['sequence']['drop'][] = "{$this->schema}.{$sequence}";
            }
        }
    }

    private function deleteFunctions($functions)
    {
        if (count((array)$functions) > 0) {
            foreach ($functions as $function) {
                $this->diff[]                        = "DROP FUNCTION {$function};";
                $this->summary['function']['drop'][] = "{$function}";
            }
        }
    }

    private function createFunctions($functions)
    {
        if (count((array)$functions) > 0) {
            foreach ($functions as $function) {
                $buffer                          = $this->master['functions'][$function]['definition'];
                $this->summary['function']['create'][] = "{$this->schema}.{$function}";
                $this->diff[]                          = $buffer;
            }
        }
    }

    private function createSequences($sequences)
    {
        if (count((array)$sequences) > 0) {
            foreach ($sequences as $sequence) {
                $this->createSequence($sequence);
            }
        }
    }

    private function createSequence($sequence)
    {
        $increment = $this->master['sequences'][$sequence]['increment'];
        $minvalue  = $this->master['sequences'][$sequence]['minvalue'];
        $maxvalue  = $this->master['sequences'][$sequence]['maxvalue'];
        $start     = $this->master['sequences'][$sequence]['startvalue'];

        $owner  = $this->master['sequences'][$sequence]['owner'];
        $buffer = "\nCREATE SEQUENCE {$this->schema}.{$sequence}";
        $buffer .= "\n  INCREMENT {$increment}";
        $buffer .= "\n  MINVALUE {$minvalue}";
        $buffer .= "\n  MAXVALUE {$maxvalue}";
        $buffer .= "\n  START 1;";
        if ($this->settings['alter_owner'] === true) {
            $buffer .= "\nALTER TABLE {$this->schema}.{$sequence} OWNER TO {$owner};";
        }
        foreach ($this->master['sequences'][$sequence]['grants'] as $grant) {
            if (!empty($grant)) {
                $buffer .= "\nGRANT ALL ON TABLE {$this->schema}.{$sequence} TO {$grant};";
            }
        }
        $this->diff[]                          = $buffer;
        $this->summary['secuence']['create'][] = "{$this->schema}.{$sequence}";
    }

    private function createView($view)
    {
        $definition = $this->master['views'][$view]['definition'];
        $owner      = $this->master['views'][$view]['owner'];
        $buffer     = "\nCREATE OR REPLACE VIEW {$this->schema}.{$view} AS\n";
        $buffer .= "  " . $definition;
        if ($this->settings['alter_owner'] === true) {
            $buffer .= "\nALTER TABLE {$this->schema}.{$view} OWNER TO {$owner};";
        }
        foreach ($this->master['views'][$view]['grants'] as $grant) {
            if (!empty($grant)) {
                $buffer .= "\nGRANT ALL ON TABLE {$this->schema}.{$view} TO {$grant};";
            }
        }
        $this->diff[]                      = $buffer;
        $this->summary['view']['create'][] = "{$this->schema}.{$view}";
    }

    private function createViews($views)
    {
        if (count((array)$views) > 0) {
            foreach ($views as $view) {
                $this->createView($view);
            }
        }
    }

    private function addColumns($table, $columns)
    {
        if (count((array)$columns) > 0) {
            foreach ($columns as $column) {
                $this->diff[]                        = "ADD COLUMN {$column} TO TABLE {$table}";
                $this->summary['column']['create'][] = "{$this->schema}.{$table}.{$column}";
            }
        }
    }

    private function deleteColumns($table, $columns)
    {
        if (count((array)$columns) > 0) {
            foreach ($columns as $column) {
                $this->diff[]                      = "DELETE COLUMN {$column} TO TABLE {$table}";
                $this->summary['column']['drop'][] = "{$this->schema}.{$table} {$column}";
            }
        }
    }

    private function alterColumn($table, $column)
    {
        $masterType                   = $this->master['tables'][$table]['columns'][$column]['type'];
        $masterPrecision              = $this->master['tables'][$table]['columns'][$column]['precision'];
        $this->diff[]                       = "ALTER TABLE {$this->schema}.{$table} ALTER {$column} TYPE {$masterType}" . (empty($masterPrecision) ? "" : ("(" . $masterPrecision . ")")) . ";";
        $this->summary['column']['alter'][] = "{$this->schema}.{$table} {$column}";
    }

    private function addConstraint($table, $constraint)
    {
        $constraintData = $this->master['tables'][$table]['constraints'][$constraint];
        $type           = strtoupper($constraintData['type']);
        $columns        = [];
        $masterColumns  = $this->master['tables'][$table]['columns'];
        foreach ($constraintData['columns'] as $column) {
            foreach ($masterColumns as $masterColumnName => $masterColumn) {
                if ($masterColumn['order'] == $column) {
                    $columns[] = $masterColumnName;
                }
            }
        }

        switch ($type) {
            case 'UNIQUE':
                if (!empty($columns)) {
                    $this->diff[] = "ALTER TABLE {$this->schema}.{$table} ADD CONSTRAINT {$constraint} {$type} (" . implode(', ', $columns) . ");";
                } else {
                    $this->summary[] = 'CONSTRAINT ' . $constraint . ' FOR TABLE ' . $this->schema . '.' . $table . ' COULD NOT BE ADDED BECAUSE NO COLUMNS WERE DETECTED';
                }
                break;
            case 'CHECK':
                if (!empty($columns)) {
                    $this->diff[] = "ALTER TABLE {$this->schema}.{$table} ADD CONSTRAINT {$constraint} {$type} CHECK {$constraintSrc}";
                } else {
                    $this->summary[] = 'CONSTRAINT ' . $constraint . ' FOR TABLE ' . $this->schema . '.' . $table . ' COULD NOT BE ADDED BECAUSE NO COLUMNS WERE DETECTED';
                }
                break;
            case 'PRIMARY KEY':
                if (!empty($columns)) {
                    $this->diff[] = "ALTER TABLE {$this->schema}.{$table} ADD CONSTRAINT {$constraint} {$type} (" . implode(', ', $columns) . ");";
                } else {
                    $this->summary[] = 'CONSTRAINT ' . $constraint . ' FOR TABLE ' . $this->schema . '.' . $table . ' COULD NOT BE ADDED BECAUSE NO COLUMNS WERE DETECTED';
                }
                break;
            case 'FOREIGN KEY':
                $fkSchema  = $this->schema;
                $fkTable   = $constraintData['reftable'];
                $fkColumns = $constraintData['refcolumns'];
                if (!empty($columns) && !empty($fkTable) && !empty($fkColumns)) {
                    $deleteAction = strtoupper(Constraint::$ON_ACTION_MAP[$constraintData['delete_option']]);
                    $updateAction = strtoupper(Constraint::$ON_ACTION_MAP[$constraintData['update_option']]);
                    $match        = strtoupper(Constraint::$MATCH_MAP[$constraintData['match_option']]);
                    $this->diff[]       = "ALTER TABLE {$this->schema}.{$table}
                    ADD CONSTRAINT {$constraint} {$type} (" . implode(', ', $columns) . ")
                    REFERENCES {$fkSchema}.{$fkTable} (" . implode(', ', $fkColumns) . ")  MATCH {$match}
                    ON UPDATE {$updateAction} ON DELETE {$deleteAction};";
                } else {
                    $this->summary[] = 'CONSTRAINT ' . $constraint . ' FOR TABLE ' . $this->schema . '.' . $table . ' COULD NOT BE ADDED BECAUSE NO COLUMNS WERE DETECTED';
                }
                break;
        }
    }

    private function dropConstraint($table, $constraint)
    {
        $this->diff[] = "ALTER TABLE {$this->schema}.{$table} DROP CONSTRAINT {$constraint} CASCADE;";
    }

}