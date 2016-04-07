<?php

namespace Aerynl\Refinement;

use Illuminate\Support\Pluralizer;
use Config;

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
    public static function getRefinedQuery($current_model, $session_name = "", $eager = array(), $additional_wheres = array(), $additional_joins = array(), $refinements_array = array())
    {
        if (empty($current_model)) return false;

        $current_table = strtolower(Pluralizer::plural($current_model));

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
                $query = self::joinTableToQuery($query, $additional_join, $current_table);
                $already_joined[] = $additional_join;
            }
        }

        $refinements = empty($refinements_array) ? \Session::get($session_name) : $refinements_array;
        if (empty($refinements)) return $query;

        foreach ($refinements as $refinement_table => $refinement) {
            $refinement_model = ucfirst(Pluralizer::singular($refinement_table));

            if ($current_model != $refinement_model && !in_array($refinement_table, $already_joined)) {
                /* in this case we need to join the table to be able to filter by it */
                $query = self::joinTableToQuery($query, $refinement_table, $current_table);
                $already_joined[] = $refinement_table;
            }

            foreach ($refinement as $refinement_column => $refinement_values) {
                $query = $query->whereIn($refinement_table . '.' . $refinement_column, $refinement_values);
            }
        }

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
        if (empty($options_scheme) || empty($current_model)) return array();

        $options_array = array();
        $current_table = strtolower(Pluralizer::plural($current_model));
        $full_refinements_array = \Session::has($session_name) ? \Session::get($session_name) : array();
        $current_model_id = \App::make($current_model)->getKeyName();
        $titles = Config::get('refinement::titles');

        /* remember tables, which will be added by refinements function */
        $already_joined = array();
        if (!empty($additional_joins)) $already_joined = $additional_joins;

        foreach ($options_scheme as $option_key => $option_scheme) {
            try {
                $titles_key = $option_scheme['parent_table'] . "|" . $option_scheme['filter_column'];

                $option_data = array(
                    'parent_table' => $option_scheme['parent_table'],
                    'column_name' => $option_scheme['filter_column'],
                    'title' => !empty($titles[$titles_key]) ? trans('refinements.'.$titles[$titles_key]) : ucfirst($option_scheme['filter_value']),
                    'options' => array()
                );

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

                /* generate query with updated refinements */
                $option_query = self::getRefinedQuery($current_model, "", $eager, $option_additional_wheres, $additional_joins, $option_refinements_array);

                /* add option parent table if we haven't joined before */
                $option_parent_model = ucfirst(Pluralizer::singular($option_scheme['parent_table']));
                if ($current_model != $option_parent_model && empty($option_refinements_array[$option_scheme['parent_table']])
                    && !in_array($option_scheme['parent_table'], $already_joined)) {

                    $option_query = self::joinTableToQuery($option_query, $option_scheme['parent_table'], $current_table);
                    $already_joined[] = $option_scheme['parent_table'];
                }

                /* add option child table if needed */
                if (!empty($option_scheme['join_table']) && $current_table != $option_scheme['join_table']) {
                    $join_statement = array(
                        'left' => "{$option_scheme['parent_table']}.{$option_scheme['filter_column']}",
                        'operand' => "=",
                        'right' => "{$option_scheme['join_table']}.id"
                    );

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

                /* define order by clause */
                $option_order_by = (empty($option_scheme['order_by'])) ? $option_name : $option_scheme['order_by'];

                /* TODO: a soon as this issue is fixed, rewrite to have options counted by sql https://github.com/sleeping-owl/with-join/issues/10 */
                $option_query = $option_query->select(
                    \DB::raw("COUNT(1) as option_count, {$option_name} as option_name, {$option_id} as option_id")
                )->groupBy($option_id, $current_table . "." . $current_model_id)->orderBy($option_order_by);

                /* finally getting records */
                $options_records = self::getArrayFromQuery($option_query);
                foreach ($options_records as $option_record) {
                    if (empty($option_data['options'][$option_record->option_id])) {
                        $option_data['options'][$option_record->option_id] = array(
                            'name' => $option_record->option_name,
                            'id' => $option_record->option_id,
                            'count' => 0,
                            'checked' => in_array($option_record->option_id, $selected_options_array)
                        );
                    }
                    $option_data['options'][$option_record->option_id]['count'] += (!empty($option_scheme['distinct']) ? 1 : $option_record->option_count);
                }

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
    private static function getArrayFromQuery($query)
    {
        $sql = $query->toSql();

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
     * @return Illuminate\Database\Query\Builder $query - Query Builder object
     */
    private static function joinTableToQuery($query, $join_table_name, $current_table) {
        $config_joins = Config::get('refinement::joins');

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

        /* if there is no record in config array for this table, skip it */
        if (empty($join_statement)) return $query;

        return $query->join($join_table_name, $join_statement['left'], $join_statement['operand'], $join_statement['right']);
    }
}