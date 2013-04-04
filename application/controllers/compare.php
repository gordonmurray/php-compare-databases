<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Compare extends MY_Controller
{

    function __construct()
    {
        parent::__construct();
    }

    function index()
    {
        $sql_commands_to_run = array();

        /*
         * load both databases
         */
        $DB1 = $this->load->database('development', TRUE);
        $DB2 = $this->load->database('live', TRUE);

        /*
         * list the tables from both databases
         */
        $development_tables = $DB1->list_tables();
        $live_tables = $DB2->list_tables();

        /*
         * list any tables in the development database that are not in the live database
         */
        $tables_to_create = array_diff($development_tables, $live_tables);

        /**
         * Create any tables that are not in the Live database
         */
        if (is_array($tables_to_create) && !empty($tables_to_create))
        {
            /*
             * TODO: Update this next step to remove tables if no longer used
             * currently it only adds tables to the Live DB if they are not there
             * it should also delete old tables that are no longer in use on the live site
             */
            $sql_commands_to_run = array_merge($sql_commands_to_run, $this->create_new_tables($tables_to_create));
        }

        $tables_up_update = $this->compare_table_structures($development_tables, $live_tables);

        if (is_array($tables_up_update) && !empty($tables_up_update))
        {
            $sql_commands_to_run = array_merge($sql_commands_to_run, $this->update_existing_tables($tables_up_update));
        }

        if (is_array($sql_commands_to_run) && !empty($sql_commands_to_run))
        {
            echo "<h2>The database is out of Sync!</h2>\n";
            echo "<p>The following SQL commands need to be executed to bring the Live database tables up to date: </p>\n";
            echo "<ol>\n";
            foreach ($sql_commands_to_run as $sql_command)
            {
                echo "<li>$sql_command</li>\n";
            }
            echo "<ol>\n";
        }
        else
        {
            echo "<h2>The database appears to be up to date</h2>\n";
        }
    }

    /**
     * Create new tables in the destination database
     * @param type $database_group
     * @param type $tables_to_create
     */
    function create_new_tables($tables_to_create)
    {
        /*
         * load both databases
         */
        $DB1 = $this->load->database('development', TRUE);
        $DB2 = $this->load->database('live', TRUE);

        $sql_commands_to_run = array();

        foreach ($tables_to_create as $table)
        {
            $query = $DB1->query("SHOW CREATE TABLE $table -- create tables");
            $table_structure = $query->row_array();
            $sql_commands_to_run[] = $table_structure["Create Table"];
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
        /*
         * load both databases
         */
        $DB1 = $this->load->database('development', TRUE);
        $DB2 = $this->load->database('live', TRUE);

        $tables_need_updating = array();

        $live_table_structures = $development_table_structures = array();

        /*
         * generate the sql for each table in the development database
         */
        foreach ($development_tables as $table)
        {
            $query = $DB1->query("SHOW CREATE TABLE $table -- dev");
            $table_structure = $query->row_array();
            $development_table_structures[$table] = $table_structure["Create Table"];
        }

        /*
         * generate the sql for each table in the live database
         */
        foreach ($live_tables as $table)
        {
            $query = $DB2->query("SHOW CREATE TABLE $table -- live");
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
        $DB1 = $this->load->database('development', TRUE);
        $DB2 = $this->load->database('live', TRUE);

        $table_structure_development = array();
        $table_structure_live = array();

        if (is_array($tables) && !empty($tables))
        {
            foreach ($tables as $table)
            {
                $table_structure_development[$table] = $this->table_field_data((array) $DB1, $table);
                $table_structure_live[$table] = $this->table_field_data((array) $DB2, $table);
            }
        }

        $sql_commands_to_run = array(); // TODO: Start with a transaction lock?

        /*
         * first, remove any fields from $table_structure_live that are no longer in $table_structure_development
         */
        $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes($table_structure_live, $table_structure_development, 'drop'));

        /*
         * TODO: second, update any fields that are in $table_structure_live already
         */
        //$sql_commands_to_run = '';

        /*
         * third, add any fields that are not present in $table_structure_live
         */
        $sql_commands_to_run = array_merge($sql_commands_to_run, $this->determine_field_changes($table_structure_development, $table_structure_live, 'add'));

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
        $fields = array();

        $conn = mysql_connect($database["hostname"], $database["username"], $database["password"]);

        mysql_select_db($database["database"]);

        $result = mysql_query('select * from ' . $table);

        if (is_resource($result))
        {
            $i = 0;
            while ($i < mysql_num_fields($result))
            {

                $meta = mysql_fetch_field($result, $i);
                if ($meta)
                {
                    $fields[] = (array) $meta;
                    $i++;
                }
            }
            mysql_free_result($result);
        }
        return $fields;
    }

    /**
     * Give to arrays of table fields, remove any unused fields
     * @param array $table_structure_development
     * @param array $table_structure_live
     * @return array $sql_commands_to_run
     */
    function determine_field_changes($source_field_structures, $destination_field_structures, $action)
    {
        $sql_commands_to_run = array();

        foreach ($source_field_structures as $table => $live_fields)
        {
            foreach ($live_fields as $field)
            {
                /*
                 * check to see if this field in in the development database
                 * if it is no longer in the development database it is assumed 
                 * it is removed and should be removed from the Live database too
                 */
                if (!$this->in_array_recursive($field["name"], $destination_field_structures[$table]))
                {
                    //echo "<strong>The field name '" . $field["name"] . "' is missing inside '$table'</strong><br />";
                    if ($action == 'drop')
                    {
                        $sql_commands_to_run[] = "ALTER TABLE $table DROP COLUMN " . $field["name"];
                    }
                    else
                    {
                        $sql_commands_to_run[] = "ALTER TABLE $table ADD COLUMN " . $field["name"];
                    }
                }
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
            $item = $item["name"]; // look in the name field only
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