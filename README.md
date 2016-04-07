# Refinement package
## Short description
Easy refinements for use with Laravel. Contains functions for saving filters in Session, generating filter options and fetching filter results.

## Quick start
```
composer require aerynl/refinement
php artisan config:publish aerynl/refinement
```
Second command will create `\app\config\packages\aerynl\refinement\config.php` file, where you can specify table connections and filter names.

## How to use
If you want to save filters in session, run:
```
$refinements = array(
	'products' => array(
		'color_id' => array(12, 13, 14)
	);
);
Refinement::updateRefinements("products_filters", $refinements);
```

If you want to get filtered results, run:
```
$products_query = Refinement::getRefinedQuery("Product", "products_filters");
$products = $products_query->paginate(10);
```

If you want to get refinement options, run:
```
$options_scheme = array(
	'parent_table' => 'products', 'join_table' => 'colors', 'filter_column' => 'color_id', 'filter_value' => 'color_name'
);
$products_options = Refinement::getRefinedQuery("Product", $options_scheme, "products_filters");
```

## What to write in config file
In config file you can specify table connections in `joins` array and filter names in `titles` array. 

#### Table connections
Sometimes we need to make joins to create the necessary queries.

Because it's hard to create proper queries from the inputted parameters the configuration below will be used to create the joins. 
**Be sure all the tables you are using for filtering have join configuration!** 
Note, this doesn't mean you should specify in join array tables, which are used to get options. For example, if you filter products by `product.color_id` and you have table `colors`, where all colors are kept, there is no need to add `colors` table to joins array. But if you always need to show only that products, which have `product_sale.active` = `false`, be sure that `product_sale` table is specified in config file.

Joins array has the following scheme:
```
'joins' => array(
        '$table1_name|$table2_name' => array(
            'left' => '$table1_name.$table1_column',
            'operand' => '=', // or whatever you want
            'right' => '$table2_name.$table2_column'
        ), 
		...
     );
```
#### Titles of filters
Configure the name of your refinements here.
If no name is entered the standard naming format will be used ([ucfirst](http://php.net/manual/ru/function.ucfirst.php))

Example:
```
'titles' => array(
    'products|color_id' => 'Product color',
	...
);
```

## Refinement title translations
Create a file called refinements.php in `/app/lang/en`, there you'll be able to add translations for the titles you've added to the configuration.

## Detailed information about functions

#### `Refinement::updateRefinements($session_name, $new_refinements)`
This method is used to save filters in session. 
`$session_name` is the name of session, so you can use several filters sessions in one application.
`$new_refinements` is a new filters array, that should be remembered. Keep the following scheme to have package work correctly:
```
$new_refinements = array(
	'$table_name1' => array(
		'$table_column1' => array(
			$value1, $value2, $value3
		),
		'$table_column2' => array(
			$value1, $value2, $value3
		),
		...
	),
	...
);
```

#### `Refinement::getRefinedQuery($main_model, [$session_name, $eager, $additional_wheres, $additional_joins, $refinements_array])`
This method is used to generate query for getting filtered results. Why doesn't it return filtered results? Sometimes you will need to do some operations with query before getting the results, for example groupBy, orderBy, paginate and others. So you can use this function in the following way:
```
$products_query = Refinement::getRefinedQuery("Product", "products_filters");
$products = $products_query->paginate(10);
```
Passed variables:
* `$main_model` - string main model name.
* `$session_name` - string session name.
* `$eager` - array of tables, which you want to eager load. http://laravel.com/docs/4.2/eloquent#eager-loading
* `$additional_wheres` - array of additional where conditions, which need to be ran. For example `array("products.active is true", "products.deleted is null")`. Note, if your conditions use other tables, except of main one, you need to specify these tables in `$additional_joins` (and in config file).
* `$additional_joins` - array of additional tables, which are used in `$additional_wheres`. Note, these tables need to be configured in config file.
* `$refinements_array` - is used in case you need to get query with filters not from session, but from custome array. Should have the same format as `$new_refinements` in `updateRefinements` function. In this case session refinements are not used. 

#### `Refinement::generateOptionsArray($main_model, [$options_scheme, $session_name, $eager, $additional_wheres, $additional_joins])`
This method is used for generating array of refinements options using passed scheme. It returns array of options with the following scheme:
```
$options_array = array(
	array(
		'column_name' => string $column_name, // e.g. 'color_id'.
		'parent_table' => string $parent_table // e.g. 'products'.
		'title' => string $category_title	// is taken from Config titles array
		'options' => array(
			$option_id => array(
				'name' => string $option_name,  // e.g. "White"
				'id' => mixed $option_id,		// not integer, but mixed in case you filter by enum field or something else.
				'count' => int $number_of_results,  
				'checked' => bool $is_checked
			),
			...
		)
	), 
	...
	);
```
`$column_name`, `$parent_table` and `$option_id` are used for creating $new_refinements array for updateRefinements function.
For example you can show options like checkboxes with `name="$parent_table[$column_name][]"` and `value="$option_id"`.

Passed variables:
Note, first 5 variables should be the same as for `getRefinedQuery` function if you want to have actual $number_of_results.
* `$main_model` - string main model name.
* `$session_name` - string session name.
* `$eager` - array of tables, which you want to eager load. http://laravel.com/docs/4.2/eloquent#eager-loading
* `$additional_wheres` - array of additional where conditions, which need to be ran. For example `array("products.active is true", "products.deleted is null")`. Note, if your conditions use other tables, except of main one, you need to specify these tables in `$additional_joins` (and in config file).
* `$additional_joins` - array of additional tables, which are used in `$additional_wheres`. Note, these tables need to be configured in config file.
* `$options_scheme` - scheme of options we want to select in the following format: 
```
$options_scheme = array(
	array(
		'parent_table' => string $parent_table_name, - filtering is based on this table
		'filter_column' => string $parent_table_column - column of $parent_table. filtering is based on this column
		'join_table' => string $join_table_name, - table, which is joined to parent table and contains option names
		'filter_value' => string $join_table_column - column with option names
		'additional_wheres' => array of strings - additional where statements
	), 
	...
);
```
e.g.
```
$options_scheme = array(
	'parent_table' => 'products', 'join_table' => 'colors', 'filter_column' => 'color_id', 'filter_value' => 'color_name', 'additional_wheres' => array("colors.deleted_at is null")
);
```
This means, that results are filtered by `products.color_id`. And color names you can see in `colors.color_name` column.

## What will be done soon
- Laravel 5 supporting
- While I was writing this documentation, I had a great idea! We can avoid passing so many parameters each time by changing static methods to non-static.
