<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Compare extends MY_Controller
{

    function __construct()
    {
        parent::__construct();
        $this->CHARACTER_SET = "utf8 COLLATE utf8_general_ci";
        $this->DB1 = $this->load->database('development', TRUE); // load the source/development database
        $this->DB2 = $this->load->database('live', TRUE); // load the destination/live database
    }

    function index()
    {
        /*
         * This will become a list of SQL Commands to run on the Live database to bring it up to date
         */
        $sql_commands_to_run = array();

        /*
         * list the tables from both databases
         */
        $development_tables = $this->DB1->list_tables();
        $live_tables = $this->DB2->list_tables();

        /*
         * list any tables that need to be created or dropped
         */
        $tables_to_create = array_diff($development_tables, $live_tables);
        $tables_to_drop = array_diff($live_tables, $development_tables);

        /**
         * Create/Drop any tables that are not in the Live database
         */
        $sql_commands_to_run = (is_array($tables_to_create) && !empty($tables_to_create)) ? array_merge($sql_commands_to_run, $this->manage_tables($tables_to_create, 'create')) : array();
        $sql_commands_to_run = (is_array($tables_to_drop) && !empty($tables_to_drop)) ? array_merge($sql_commands_to_run, $this->manage_tables($tables_to_drop, 'drop')) : array();

        $tables_to_update = $this->compare_table_structures($development_tables, $live_tables);

        /*
         * Before comparing tables, remove any tables from the list that will be created in the $tables_to_create array
         */
        $tables_to_update = array_diff($tables_to_update, $tables_to_create);

        /*
         * update tables, add/update/emove columns
         */
        $sql_commands_to_run = (is_array($tables_to_update) && !empty($tables_to_update)) ? array_merge($sql_commands_to_run, $this->update_existing_tables($tables_to_update)) : '';

        if (is_array($sql_commands_to_run) && !empty($sql_commands_to_run))
        {
            echo "<h2>The database is out of Sync!</h2>\n";
            echo "<p>The following SQL commands need to be executed to bring the Live database tables up to date: </p>\n";
            echo "<pre style='padding: 20px; background-color: #FFFAF0;'>\n";
            foreach ($sql_commands_to_run as $sql_command)
            {
                echo "$sql_command\n";
            }
            echo "<pre>\n";
        }
        else
        {
            echo "<h2>The database appears to be up to date</h2>\n";
        }
    }

    /**
     * Manage tables, create or drop them
     * @param array $tables
     * @param string $action
     * @return array $sql_commands_to_run
     */
    function manage_tables($tables, $action)
    {
        $sql_commands_to_run = array();

        if ($action == 'create')
        {
            foreach ($tables as $table)
            {
                $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- create tables");
                $table_structure = $query->row_array();
                $sql_commands_to_run[] = $table_structure["Create Table"] . ";";
            }
        }

        if ($action == 'drop')
        {
            foreach ($tables as $table)
            {
                $sql_commands_to_run[] = "DROP TABLE $table;";
            }
        }

        return $sql_commands_to_run;
    }

    /**
     * Go through each table, compare their sql structure
     * @param array $development_tables
     * @param array $live_tables
     */
    function compare_table_structures($development_tables, $live_tables)
    {
        $tables_need_updating = array();

        $live_table_structures = $development_table_structures = array();

        /*
         * generate the sql for each table in the development database
         */
        foreach ($development_tables as $table)
        {
            $query = $this->DB1->query("SHOW CREATE TABLE `$table` -- dev");
            $table_structure = $query->row_array();
            $development_table_structures[$table] = $table_structure["Create Table"];
        }

        /*
         * generate the sql for each table in the live database
         */
        foreach ($live_tables as $table)
        {
            $query = $this->DB2->query("SHOW CREATE TABLE `$table` -- live");
            $table_structure = $query->row_array();
            $live_table_structures[$table] = $table_structure["Create Table"];
        }

        /*
         * compare the development sql to the live sql
         */
        foreach ($development_tables as $table)
        {
            $development_table = $development_table_structures[$table];
            $live_table = (isset($live_table_structures[$table])) ? $live_table_structures[$table] : '';

            if ($this->count_differences($development_table, $live_table) > 0)
            {
                $tables_need_updating[] = $table;
            }
        }

        return $tables_need_updating;
    }

    /**
     * Count differences in 2 sql statements
     * @param string $old
     * @param string $new
     * @return int $differences
     */
    function count_differences($old, $new)
    {
        $differences = 0;
        $old = trim(preg_replace('/\s+/', '', $old));
        $new = trim(preg_replace('/\s+/', '', $new));

        if ($old == $new)
        {
            return $differences;
        }

        $old = explode(" ", $old);
        $new = explode(" ", $new);
        $length = max(count($old), count($new));

        for ($i = 0; $i < $length; $i++)
        {
            if ($old[$i] != $new[$i])
            {
                $differences++;
            }
        }

        return $differences;
    }

    /**
     * Given an array of tables that differ from DB1 to DB2, update DB2
     * @param array $tables
     */
    function update_existing_tables($tables)
    {
        $sql_commands_to_run = array();
        $table_structure_development = array();
        $table_structure_live = array();

        if (is_array($tables) && !empty($tables))
        {
            foreach ($tables as $table)
            {
                $table_structure_development[$table] = $this->table_field_data((array) $this->DB1, $table);
                $table_structure_live[$table] = $this->table_field_data((array) $this->DB2, $table);
            }
        }

        /*
         * add, remove or update any fields in $table_structure_live
         */
        $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes($table_structure_development, $table_structure_live));

        return $sql_commands_to_run;
    }

    /**
     * Given a database and a table, compile an array of field meta data
     * @param array $database
     * @param string $table
     * @return array $fields
     */
    function table_field_data($database, $table)
    {
        $conn = mysqli_connect($database["hostname"], $database["username"], $database["password"]);

        mysql_select_db($database["database"]);

        $result = mysql_query("SHOW COLUMNS FROM `$table`");

        while ($row = mysql_fetch_assoc($result))
        {
            $fields[] = $row;
        }

        return $fields;
    }

    /**
     * Given to arrays of table fields, add/edit/remove fields
     * @param type $source_field_structures
     * @param type $destination_field_structures
     */
    function determine_field_changes($source_field_structures, $destination_field_structures)
    {
        $sql_commands_to_run = array();

        /**
         * loop through the source (usually development) database
         */
        foreach ($source_field_structures as $table => $fields)
        {
            foreach ($fields as $field)
            {
                if ($this->in_array_recursive($field["Field"], $destination_field_structures[$table]))
                {
                    $modify_field = '';
                    /*
                     * Check for required modifications
                     */
                    for ($n = 0; $n < count($fields); $n++)
                    {
                        if (isset($fields[$n]) && isset($destination_field_structures[$table][$n]) && ($fields[$n]["Field"] == $destination_field_structures[$table][$n]["Field"]))
                        {
                            $differences = array_diff($fields[$n], $destination_field_structures[$table][$n]);

                            if (is_array($differences) && !empty($differences))
                            {
                                // ALTER TABLE `bugs` MODIFY COLUMN `site_name`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `type`;
                                // ALTER TABLE `bugs` MODIFY COLUMN `message`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `site_name`;
                                $modify_field = "ALTER TABLE $table MODIFY COLUMN `" . $fields[$n]["Field"] . "` " . $fields[$n]["Type"] . ' CHARACTER SET ' . $this->CHARACTER_SET;
                                $modify_field .= (isset($fields[$n]["Default"]) && $fields[$n]["Default"] != '') ? ' DEFAULT \'' . $fields[$n]["Default"] . '\'' : '';
                                $modify_field .= (isset($fields[$n]["Null"]) && $fields[$n]["Null"] == 'YES') ? ' NULL' : ' NOT NULL';
                                $modify_field .= (isset($fields[$n]["Extra"]) && $fields[$n]["Extra"] != '') ? ' ' . $fields[$n]["Extra"] : '';
                                $modify_field .= ';';
                            }
                        }

                        if ($modify_field != '' && !in_array($modify_field, $sql_commands_to_run))
                            $sql_commands_to_run[] = $modify_field;
                    }
                }
                else
                {
                    /*
                     * Add 
                     */
                    $add_field = "ALTER TABLE $table ADD COLUMN `" . $field["Field"] . "` " . $field["Type"] . " CHARACTER SET " . $this->CHARACTER_SET;
                    $add_field .= (isset($field["Null"]) && $field["Null"] == 'YES') ? ' Null' : '';
                    $add_field .= " DEFAULT " . $field["Default"];
                    $add_field .= (isset($field["Extra"]) && $field["Extra"] != '') ? ' ' . $field["Extra"] : '';
                    $add_field .= (isset($previous_field) && $previous_field != '') ? ' AFTER ' . $previous_field : '';
                    $add_field .= ';';
                    $sql_commands_to_run[] = $add_field;
                }
                
                $previous_field = $field["Field"];

            }
        }

        return $sql_commands_to_run;
    }

    /**
     * Recursive version of in_array
     * @param type $needle
     * @param type $haystack
     * @param type $strict
     * @return boolean
     */
    function in_array_recursive($needle, $haystack, $strict = false)
    {
        foreach ($haystack as $array => $item)
        {
            $item = $item["Field"]; // look in the name field only
            if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && in_array_recursive($needle, $item, $strict)))
            {
                return true;
            }
        }

        return false;
    }

}

/* End of file compare.php */
                /* Location: ./application/controllers/compare.php */
