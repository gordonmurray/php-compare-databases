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
            echo "<h2>Databases tables are out of Sync!</h3>\n";
            $this->create_new_tables($tables_to_create);
        }
        else
        {

            $tables_up_update = $this->compare_table_structures($development_tables, $live_tables);

            if (is_array($tables_up_update) && !empty($tables_up_update))
            {
                echo "<h2>Databases table structures are out of Sync!</h3>\n";
                echo "The following tables need to be updated<br />\n";
                print_r($tables_up_update);

                $this->update_existing_tables($tables_up_update);
            }
            else
            {
                echo "<h2>Databases tables appear to be in Sync</h3>\n";
            }
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

        foreach ($tables_to_create as $table)
        {
            $query = $DB1->query("SHOW CREATE TABLE $table");
            $table_structure = $query->row_array();
            $table_sql = $table_structure["Create Table"];

            if ($DB2->simple_query($table_sql))
            {
                echo "Created table: $table<br />\n";
            }
            else
            {
                echo "Failed to create table: $table<br />\n";
            }
        }
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
            $query = $DB1->query("SHOW CREATE TABLE $table");
            $table_structure = $query->row_array();
            $development_table_structures[$table] = $table_structure["Create Table"];
        }

        /*
         * generate the sql for each table in the live database
         */
        foreach ($live_tables as $table)
        {
            $query = $DB2->query("SHOW CREATE TABLE $table");
            $table_structure = $query->row_array();
            $live_table_structures[$table] = $table_structure["Create Table"];
        }

        /*
         * compare the development sql to the live sql
         */
        foreach ($development_tables as $table)
        {
            $development_table = $development_table_structures[$table];
            $live_table = $live_table_structures[$table];

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
        /*
         * load both databases
         */
        $DB1 = $this->load->database('development', TRUE);
        $DB2 = $this->load->database('live', TRUE);

        if (is_array($tables) && !empty($tables))
        {
            foreach ($tables as $table)
            {
                $table_structure[$table] = $this->table_field_data((array) $DB1, $table);
            }

            print_r($table_structure);
        }
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

        return $fields;
    }

}

/* End of file compare.php */
    /* Location: ./application/controllers/compare.php */