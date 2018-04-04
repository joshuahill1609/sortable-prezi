<?php

namespace App\Traits;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

/*
 * This trait will handle sorting for the model it is applied to. 
 *
 * The default DB column is position. You can override this by declaring a $sortableField on the model.
 *
 * If you need the position to be contained to a sub-set of the model. You can declare a $sortableGroupByField
 * on the model. 
 */
trait Sortable
{
    public static function bootSortable()
    {
        /*
         * Adds position to model or updates positions on creating event
         */
        static::creating(
            function ($model) {
                $sortableField = static::getSortableField();
                $query = static::applySortableGroup(static::on(), $model);
                if ($model->$sortableField === null) {
                    //position was not declared so lets give it the next available position
                    $model->setAttribute($sortableField, $query->max($sortableField) + 1);
                } else {
                    //position was declared so we need to update any displaced objects
                    static::shiftUp(
                        $query->where($sortableField, '>=', $model->$sortableField)->get(),
                        $sortableField,
                        $model->getTable()
                    );
                }
            }
        );

        /*
         * Updates positions on update event
         */
        static::updating(
            function ($model) {
                $sortableField = static::getSortableField();
                $query = static::applySortableGroup(static::on(), $model);

                /*
                 * TODO: This will handle a change in group and position. It will break
                 * if there is a change in two groups. 
                 */
                //check if model moved groups
                $did_not_switch_groups = true;
                if (static::getSortableGroupByField() !== null) {
                    foreach (static::getSortableGroupByField() as $group) {
                        if ($model->$group != $model->getOriginal($group)) {
                            $did_not_switch_groups = false;

                            //update the group being added to
                            static::shiftUp(
                                $query->where($sortableField, '>=', $model->$sortableField)->get(),
                                $sortableField,
                                $model->getTable()
                            );

                            //update the group being left
                            $og_model = clone $model;
                            $og_model->$group = $model->getOriginal($group);
                            $og_query = static::applySortableGroup($og_model::query(), $og_model);
                            static::shiftDown(
                                $og_query->where($sortableField, '>', $model->getOriginal($sortableField))->get(),
                                $sortableField,
                                $model->getTable()
                            );
                        }
                    }
                }

                if ($did_not_switch_groups) {
                    $new_position = $model->$sortableField;
                    $old_position = $model->getOriginal($sortableField);

                    if ($new_position < $old_position) {
                        static::shiftUp(
                            $query->where($sortableField, '>=', $new_position)
                                  ->where($sortableField, '<=', $old_position)
                                  ->get(),
                            $sortableField,
                            $model->getTable()
                        );
                    } elseif ($new_position > $old_position) {
                        static::shiftDown(
                            $query->where($sortableField, '<=', $new_position)
                                  ->where($sortableField, '>=', $old_position)
                                  ->get(),
                            $sortableField,
                            $model->getTable()
                        );
                    }
                }
            }
        );

        /*
         * Updates positions on deleting event
         */
        static::deleted(
            function ($model) {
                $sortableField = static::getSortableField();
                $query = static::applySortableGroup(static::on(), $model);

                static::shiftDown(
                    $query->where($sortableField, '>=', $model->$sortableField)->get(),
                    $sortableField,
                    $model->getTable()
                );
            }
        );

        /*
         * Global scope to order all model by the sortable field
         */
         static::addGlobalScope('sortable', function (Builder $builder) {
              $sortableField = static::getSortableField();
              $builder->orderBy($sortableField);
         });
    }

    /*
     * Get this models sorting field.
     * 
     * @return string
     */
    public static function getSortableField()
    {
        return isset(static::$sortableField) ? static::$sortableField : 'position';
    }

    /*
     * Apply a group by field when sorting this model.
     *
     * @param QueryBuilder $query
     * @param Model        $model
     *
     * @return QueryBuilder 
     */
    public static function applySortableGroup($query, $model)
    {
        $groupBy = static::getSortableGroupByField();
        if ($groupBy !== null) {
            foreach ($groupBy as $field) {
                $query->where($field, $model->$field);
            }
        }

        return $query;
    }

    /*
     * Get this models group by field.
     *
     * @return string|null
     */
    public static function getSortableGroupByField()
    {
        if (isset(static::$sortableGroupByField) ) {
            return is_array(static::$sortableGroupByField) ? static::$sortableGroupByField : [static::$sortableGroupByField];
        }

        return null;
    }

    /*
     * Increment the position of each displaced model by 1
     *
     * @param Collection $displaced
     * @param string     $sortableField
     * @param string     $tableName
     *
     * @return void
     */
    public static function shiftUp(Collection $displaced, $sortableField, $tableName)
    {
        return static::shift($displaced, $sortableField, $tableName, 'up');
    }

    /*
     * Decrement the position of each displaced model by 1
     *
     * @param Collection $displaced
     * @param string     $sortableField
     * @param string     $tableName
     *
     * @return void
     */
    public static function shiftDown(Collection $displaced, $sortableField, $tableName)
    {
        return static::shift($displaced, $sortableField, $tableName, 'down');
    }

    /*
     * Update the position of each displaced model
     *
     * @param Collection $displaced
     * @param string     $sortableField
     * @param string     $tableName
     * @param string     $direction 
     *
     * @return void
     */
    public static function shift(Collection $displaced, $sortableField, $tableName, $direction)
    {
        $displaced->each(function($item) use ($sortableField, $direction, $tableName) {

            //using query builder instead of eloquent to avoid infinite loop
            if ($direction === 'up') {
                DB::table($tableName)->where('id', $item->id)->increment($sortableField);
            } elseif ($direction === 'down') {
                DB::table($tableName)->where('id', $item->id)->decrement($sortableField);
            }

        });
    }
}
