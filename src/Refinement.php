<?php

namespace Aerynl\Refinement;

use Illuminate\Support\Pluralizer;
use Config;
use Carbon\Carbon;

class Refinement
{

    /**
     * Updates refinements in session
     * @param string $refinements_name - name of the array in session, which keeps current refinements
     * @param array $new_refinements - array of new refinements
     */
    public static function updateRefinements($refinements_name, $new_refinements)
    {
        \Session::put($refinements_name, $new_refinements);
    }

    /**
     * Is used to generate query for getting filtered results.
     * @param string $current_model - main model name. Its elements are being filtered.
     * @param string $session_name - name of the session array, where refinements are kept
     * @param array $eager - eager loading of models
     * @param array $additional_wheres
     * @param array $additional_joins
     * @param array $refinements_array - in case you want to filter not by session, but some specific array
     * @return \Illuminate\Database\Query\Builder $query - Query Builder object
     */
    public static function getRefinedQuery($current_model, $session_name = "", $eager = array(), $additional_wheres = array(), $additional_joins = array(), $refinements_array = array(), $join_type='inner', $problem_herbs = false)
    {
        $current_instance = new $current_model;
        if (! $current_instance ) return false;

        $current_table = $current_instance->getTable();

        if (!is_array($eager)) $eager = array($eager);
        $query = $current_model::with($eager);
        if (!empty($additional_wheres)) {
            foreach ($additional_wheres as $additional_where) {
                if (empty($additional_where)) continue;
                $query->whereRaw($additional_where);
            }
        }

        $already_joined = array();
        if (!empty($additional_joins)) {

            foreach ($additional_joins as $additional_join) {
                $query = self::joinTableToQuery($query, $additional_join, $current_table, $join_type);
                $already_joined[] = $additional_join;
            }
        }

        $refinements = empty($refinements_array) ? \Session::get($session_name) : $refinements_array;

        if (empty($refinements)) return $query;

        foreach ($refinements as $refinement_table => $refinement) {
            //hardcoded changes for the boom project
            if($refinement_table == 'maintenances' && $current_model != 'App\Models\Maintenance'){
                $query->leftJoin('maintenances', 'elements.id', '=', 'maintenances.element_id')->groupBy('elements.id', 'maintenances.element_id', 'maintenances.id');
                foreach ($additional_joins as $table){
                    $query->groupBy($table.'.id');
                }
                $query->havingRaw('COUNT(maintenances.element_id) < 1');
                continue;
            }

            $refinement_model = self::getClassByTable($refinement_table);

            if ($current_model != $refinement_model && !in_array($refinement_table, $already_joined) && !$problem_herbs) {
                /* in this case we need to join the table to be able to filter by it */
                $query = self::joinTableToQuery($query, $refinement_table, $current_table);

                $already_joined[] = $refinement_table;
            }

            foreach ($refinement as $refinement_column => $refinement_values) {



                $query->where(function ($query) use ($refinement_values, $refinement_table, $refinement_column) {

                    $text_columns = Config::get('refinement.filter_types.text_columns');

                    foreach ($refinement_values as $key => $value) {
                        if($key !== 'date') {
                            if(is_array($text_columns) && in_array($refinement_table.'|'.$refinement_column, $text_columns)){
                                $value = base64_decode($value);
                            }

                            $query->orWhere($refinement_table . '.' . $refinement_column, '=', $value < 0 ? null : $value);
                        } else {
                            // $value is an array consisting of [$operator, $date]
                            $query->orWhere($refinement_table . '.' . $refinement_column, $value[0], Carbon::parse($value[1]));
                        }
                    }

                });
            }

        }

        // dd($query->toSql());

        return $query;
    }

    /**
     * Is used for generating array of refinements options using passed scheme
     *
     * @param string $current_model - main model name. Its elements are being filtered.
     * @param array $options_scheme - scheme of options
     * @param string $session_name - name of the session array, where refinements are kept
     * @param array $eager - eager loading of models
     * @param array $additional_wheres
     * @param array $additional_joins
     * @return array $options
     */
    public static function generateOptionsArray($current_model, $options_scheme = array(), $session_name = "", $eager = array(), $additional_wheres = array(), $additional_joins = array())
    {
        $current_instance = new $current_model;
        if (!$current_instance) return array();

        $options_array = array();
        $current_table = $current_instance->getTable();
        $full_refinements_array = \Session::has($session_name) ? \Session::get($session_name) : array();
        $current_model_id = $current_instance->getKeyName();
        $titles = Config::get('refinement.titles');

        /* remember tables, which will be added by refinements function */
        $already_joined = array();
        if (!empty($additional_joins)) $already_joined = $additional_joins;

        // dd($options_scheme);

        foreach ($options_scheme as $option_key => $option_scheme) {

            try {


                // The key by which we configure this refinement in the config and can refer to it
                $titles_key = $option_scheme['parent_table'] . "|" . $option_scheme['filter_column'];

                // Parse the option_scheme into an array that we use for showing our refinements on the page
                $option_data = array(
                    'parent_table' => $option_scheme['parent_table'],
                    'column_name' => $option_scheme['filter_column'],
                    'title' => !empty($titles[$titles_key]) ? trans('refinements.'.$titles[$titles_key]) : ucfirst($option_scheme['filter_value']),
                    'options' => array()
                );

                if(isset($option_scheme['type'])) {
                    $option_data['type'] = $option_scheme['type'];

                    // If we're dealing with a date, we don't need options
                    if($option_data['type'] === 'date') {
                        $option_data['operator'] = $option_scheme['operator'];

                        // Load a date that was set previously
                        if (!empty($full_refinements_array[$option_scheme['parent_table']][$option_scheme['filter_column']])) {
                            $option_data['options'] = $full_refinements_array[$option_scheme['parent_table']][$option_scheme['filter_column']];
                        }
                        $options_array[] = $option_data;
                        continue;
                    }
                }

                /* generating refinement array without our current option selected */
                $option_refinements_array = $full_refinements_array;
                $selected_options_array = array();
                if (!empty($option_refinements_array[$option_scheme['parent_table']][$option_scheme['filter_column']])) {
                    $selected_options_array = $option_refinements_array[$option_scheme['parent_table']][$option_scheme['filter_column']];
                    unset($option_refinements_array[$option_scheme['parent_table']][$option_scheme['filter_column']]);
                }

                if (empty($option_refinements_array[$option_scheme['parent_table']])) {
                    unset($option_refinements_array[$option_scheme['parent_table']]);
                }

                /* add additional wheres */
                $option_additional_wheres = $additional_wheres;
                if (!empty($option_scheme['additional_wheres'])) {
                    foreach ($option_scheme['additional_wheres'] as $option_where) {
                        if (in_array($option_where, $option_additional_wheres)) continue;
                        $option_additional_wheres[] = $option_where;
                    }
                }

                /* generate query with updated refinements, true in the end for no problem herbs */
                $problem_herbs = false;
                if(isset($option_scheme['filter_value_join_table'])) {
                    $problem_herbs = true;
                }

                // Fix the selected options for Problem Herbs
                if($problem_herbs && !empty($option_refinements_array[$option_scheme['join_table']])) {
                    $selected_options_array = $option_refinements_array[$option_scheme['join_table']][$option_scheme['filter_value']];
                }

                $option_query = self::getRefinedQuery($current_model, "", $eager, $option_additional_wheres, $additional_joins, $option_refinements_array, 'inner', $problem_herbs);

                /* add option parent table if we haven't joined before */
                $option_parent_model = self::getClassByTable($option_scheme['parent_table']);

                if ($current_model != $option_parent_model && empty($option_refinements_array[$option_scheme['parent_table']])
                    && !in_array($option_scheme['parent_table'], $already_joined)) {
                    $join_type = (isset($option_scheme['join_type']) && in_array($option_scheme['join_type'], ['inner', 'left', 'right'])) ? $option_scheme['join_type'] : 'inner';
                    $option_query = self::joinTableToQuery($option_query, $option_scheme['parent_table'], $current_table, $join_type);
                    $already_joined[] = $option_scheme['parent_table'];

                }

                /* add option child table if needed */
                if (!empty($option_scheme['join_table']) && $current_table != $option_scheme['join_table']) {

                    $join_statement = array(
                        'left' => "{$option_scheme['parent_table']}.{$option_scheme['filter_column']}",
                        'operand' => "=",
                        'right' => "{$option_scheme['join_table']}.id"
                    );

                    if($option_scheme['join_table'] == "problem_herbs") {
                        $join_statement = array(
                            'left' => "{$option_scheme['parent_table']}.{$option_scheme['filter_column']}",
                            'operand' => "=",
                            'right' => "{$option_scheme['join_table']}.grasland_id"
                        );
                    }

                    $option_query = $option_query->leftJoin($option_scheme['join_table'],
                        $join_statement['left'], $join_statement['operand'], $join_statement['right']);
                }

                /* add specific for this option selects */
                if (empty($option_scheme['join_table'])) {
                    $option_name = $option_scheme['parent_table'] . "." . $option_scheme['filter_value'];
                    $option_id = $option_scheme['parent_table'] . "." . $option_scheme['filter_value'];

                } else {
                    $option_name = $option_scheme['join_table'] . "." . $option_scheme['filter_value'];
                    $option_id = $option_scheme['join_table'] . ".id";
                }

                if(isset($option_scheme['filter_type']) && $option_scheme['filter_type'] == 'text_column'){
                    $option_name = $option_scheme['parent_table'].".".$option_scheme['filter_column'];
                    $option_id = $option_scheme['parent_table'].".".$option_scheme['filter_column'];
                }


                /* define order by clause */
                $option_order_by = (empty($option_scheme['order_by'])) ? $option_name : $option_scheme['order_by'];

                /* TODO: a soon as this issue is fixed, rewrite to have options counted by sql https://github.com/sleeping-owl/with-join/issues/10 */
                /* We want to count the id's from the parent table in case of a join, this will result in a proper count, even for the parent_table items that have a null value for this row.
                If the parent table doesn't exist, count on the $option_id column. */
                $count_column = $option_id;
                if(isset($option_scheme['parent_table'])) {
                    $count_column = $option_scheme['parent_table'].'.id';

                    // Fix specifically for element_maps which doesn't have an id column, should be generalized and added as an option
                    if($option_scheme['parent_table'] == 'elements_maps') {
                        $count_column = $option_scheme['parent_table'].'.map_id';
                    }
                }


                // PROBLEM_HERBS: filter_value_join is used for problem_herbs, we join the name table from herbs to get the name we want to show in the filters
                if(isset($option_scheme['filter_value_join_table'])) {
                    $option_name = $option_scheme['filter_value_join_table'].".".$option_scheme['filter_value_join_column'];
                    $option_id = $option_scheme['filter_value_join_table'] . ".id";
                }

                $option_query = $option_query->select(
                    \DB::raw("COUNT({$count_column}) as option_count, {$option_name} as option_name, {$option_id} as option_id")
                )->groupBy($option_id);

                // PROBLEM_HERBS: we also need to group on the new column, since the new table isn't in the groupby yet
                if(isset($option_scheme['filter_value_join_table'])) {
                    $option_query->groupBy($option_name);
                }

                $option_query->orderBy($option_order_by);

                if(isset($option_scheme['havingRaw'])){
                    $option_query->havingRaw($option_scheme['havingRaw']);
                }

                // PROBLEM_HERBS: finally, we need to join this additional table
                $ph = false;
                if(isset($option_scheme['filter_value_join_table'])) {
                    $option_query->join($option_scheme['filter_value_join_table'], $option_scheme['join_table'].".".$option_scheme['filter_value'], '=', $option_scheme['filter_value_join_table'].'.id');
                    // dd($option_query->toSql());
                    $ph = true;
                }

                /* finally getting records */
                // @TODO Here problem herbs crashes when selected
                $options_records = self::getArrayFromQuery($option_query, $ph);

                $option_scheme['filter_null'] = isset($option_scheme['filter_null']) ? $option_scheme['filter_null'] : false;

                // @TODO fix no maintenance set bug
                // if(isset($option_scheme['filter_null']) && $option_scheme['parent_table'] == "maintenances") {
                //     dd($option_scheme, $options_records);
                // }

                $optionSortNumber = 1;

                // dd($option_scheme, $option_scheme['havingRaw']);

                // When a refinedQuery with having (means havingRaw option query has been executed) in it is
                // active we get back the wrong results. To fix this we create a unique array of items from the
                // results and count the values of duplicates so we can use this to create our refinements.
                $havingCount = null;
                if(strpos($option_query->toSql(), 'having') !== false && !isset($option_scheme['havingRaw'])) {
                    $havingCount = array_count_values(array_map(function($foo){
                        return $foo->option_id;
                    }, $options_records));

                    $options_records = array_unique($options_records, SORT_REGULAR);
                    // dd($options_records);
                }

                foreach ($options_records as $option_record) {
                    if(is_null($option_record->option_name)){
                        $option_record->option_name = trans('refinements.not_set');
                    }

                    //set for null values for work with separated filters need to be removed
                    $option_record->option_id = ($option_scheme['filter_null'] && is_null($option_record->option_id) || isset($option_scheme['havingRaw']))
                        ? -1
                        : $option_record->option_id;

                    //encode value for text options
                    $option_record->option_id = (isset($option_scheme['filter_type']) && $option_scheme['filter_type'] == 'text_column')
                        ? base64_encode($option_record->option_id)
                        : $option_record->option_id;

                    if($option_record->option_name == '' && $option_scheme['filter_type'] == 'text_column'){
                        $option_record->option_name = trans('refinements.not_set');
                    }


                    if (empty($option_data['options'][$optionSortNumber]) || isset($option_scheme['havingRaw']) ){
                        // if($ph) {
                        //     dd($selected_options_array);
                        // }

                        $option_data['options'][$optionSortNumber] = array(
                            'name' => (is_null($option_record->option_name) && $option_scheme['filter_null'])
                                ? trans('refinements.not_set')
                                : $option_record->option_name,
                            'id' => $option_record->option_id,
                            'count' => $option_record->option_count,
                            'checked' => in_array($option_record->option_id, $selected_options_array),
                        );
                    }

                    // Add the havingcount to the filters, withdraw one because somehow we always count 1 too much
                    if($havingCount && !isset($option_scheme['havingRaw'])) {
                        $option_data['options'][$optionSortNumber]['count'] = $havingCount[$option_data['options'][$optionSortNumber]['id']] - 1;
                    }

                    // $option_data['options'][$optionSortNumber]['count'] += (!empty($option_scheme['distinct']) ? 1 : $option_record->option_count);

                    // Just count the # results when doing a havingRaw query and only show one item in the menu
                    if(isset($option_scheme['havingRaw'])){
                        $option_data['options'][$optionSortNumber]['name'] = trans('refinements.not_set');
                        $option_data['options'][$optionSortNumber]['count'] = count($options_records);

                        // Break the loop, we only need to do this for one iteration
                        break;
                    }

                    $optionSortNumber++;
                }

                // if(isset($option_scheme['havingRaw'])){
                //     $option_data['options'][-1]['count'] = count($options_records);
                // }
                $options_array[] = $option_data;
            } catch (\Exception $e) {
                \Log::error($e);
            }
        }


        return $options_array;
    }

    /**
     * Is used for quick selecting of big number of records from database without creating ORM objects
     * @param \Illuminate\Database\Query\Builder $query - Query Builder object
     * @return array $results - results of query
     */
    private static function getArrayFromQuery($query, $ph = false)
    {
        $sql = $query->toSql();

        // if($ph) {
        //     dd($sql);
        // }

        foreach ($query->getBindings() as $binding) {
            $value = is_numeric($binding) ? $binding : "'" . $binding . "'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        $results = \DB::select($sql);

        return $results;
    }

    /**
     * Is used to join tables to main query using config file
     * @param \Illuminate\Database\Query\Builder $query - Query Builder object - main query
     * @param $join_table_name - table we need to join
     * @param $current_table - table we join to
     * @param $type - inner, left, right
     * @return Illuminate\Database\Query\Builder $query - Query Builder object
     */
    private static function joinTableToQuery($query, $join_table_name, $current_table, $type = 'inner') {
        $config_joins = Config::get('refinement.joins');

        /* first we need to find join statement in configuration array */
        $join_statement = array();
        $join_statement_keys = array(
            "{$current_table}|{$join_table_name}",
            "{$join_table_name}|{$current_table}"
        );

        foreach ($join_statement_keys as $join_statement_key) {
            if (empty($config_joins[$join_statement_key])) continue;
            $join_statement = $config_joins[$join_statement_key];
        }

        /* if there is no record in config array for this table, skip it or dump debug information */
        if(empty($join_statement) && Config::get('app.debug')) {
            dd("Can't find {$current_table}|{$join_table_name} or {$join_table_name}|{$current_table} in config. Please add it");
        } else if (empty($join_statement)) return $query;

        if($current_table == 'maintenances' &&  $join_table_name == 'subsidies'){
            $type = 'left';
        }

        return $query->join($join_table_name, $join_statement['left'], $join_statement['operand'], $join_statement['right'], $type);
    }

    /**
     * @param $table_name
     * @return mixed
     */
    private static function getClassByTable($table_name) {

        $class = Config::get('refinement.table_map.'.$table_name);

        return $class ?: ucfirst(Pluralizer::singular($table_name));

    }
}
