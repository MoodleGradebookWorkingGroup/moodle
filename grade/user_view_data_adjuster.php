<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/grade/lib.php');

/**
 * Used by user grade report to adjust grade_items (in grade_tree) and grade_grades
 * for user view of grade data.
 *
 * @author    Colin Campbell (OIT / University of Minnesota)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_user_view_data_adjuster {

    private $userid;
    private $courseid;
    private $showtotalsifcontainhidden;

    private $empty_grades;

    public function __construct($user, $course, $showtotalsifcontainhidden) {

        $this->userid = is_numeric($user) ? $user : $user->id;
        $this->courseid = is_numeric($course) ? $course : $course->id;

        $this->showtotalsifcontainhidden = $showtotalsifcontainhidden;

        $this->empty_grades = $this->get_gradeitems_with_empty_grades();
    }


    static public function get_adjusted_course_grade(
                                $user,
                                $course,
                                $showtotalsifcontainhidden=GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN)
    {
        $grades = static::get_adjusted_course_grades($user, $course, $showtotalsifcontainhidden);

        foreach ($grades as $grade) {
            if (! $gitem = $grade->grade_item) {
                continue;
            }
            if ('course' == $gitem->itemtype) {
                return $grade;
            }
        }
        return null;
    }

    /**
     * This returns adjusted course grades.
     */
    static public function get_adjusted_course_grades(
                                $user,
                                $course,
                                $showtotalsifcontainhidden=GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN)
    {
        $userid   = is_numeric($user)   ? $user   : $user->id;
        $courseid = is_numeric($course) ? $course : $course->id;

        $data_adjuster = new grade_user_view_data_adjuster(
                                $userid,
                                $courseid,
                                $showtotalsifcontainhidden);

        $gtree = new grade_tree($courseid, false, true, null, true);

        $adjusted_grades = $data_adjuster->get_adjusted_grades($gtree);

        return $adjusted_grades;
    }

    /**
     * Gets grade_grade objects for course and user in effect for this report.
     * Also, adjusts the grademax values in the grade_tree, which is why the
     * grade_tree is a by-reference parameter.  The
     * finalgrade values are adjusted to match what the user should see based on
     * hidden status and empty values.  If any grade_items are missing a grade
     * record, creates empty grade object.  The grade objects are returned as an
     * array indexed on grade item id.
     */
    public function get_adjusted_grades(grade_tree &$gtree) {
        $grades = $this->get_unadjusted_grades();

        $this->adjust_grades_recursive($grades, $gtree->top_element);
        return $grades;
    }

    /**
     * Gets grade_grade objects for course and user in effect for this report.  If
     * any grade_items are missing a grade record, creates empty grade object.
     * The grade objects are returned as an array indexed on grade item id.
     */
    private function get_unadjusted_grades() {
        global $DB;

        // This implementation is based on a section of blank_hidden_total in grade/report/lib.php.

        $grades = array();

        $sql = "SELECT g.*
                FROM {grade_grades} g
                JOIN {grade_items} gi ON gi.id = g.itemid
                WHERE g.userid = :userid AND gi.courseid = :courseid";

        if ($recs = $DB->get_records_sql($sql,
                                         array('userid' => $this->userid,
                                               'courseid' => $this->courseid)))
        {
            foreach ($recs as $graderec) {
                $grades[$graderec->itemid] = new grade_grade($graderec, false);
            }
        }

        // Create empty grade objects for items with no grade.
        foreach ($this->empty_grades as $itemid_of_emptygrade) {
            if (!isset($grades[$itemid_of_emptygrade])) {
                $grade_grade = new grade_grade();
                $grade_grade->userid = $this->userid;
                $grade_grade->itemid = $itemid_of_emptygrade;
                $grades[$itemid_of_emptygrade] = $grade_grade;
            }
        }
        return $grades;
    }

    /**
     * Adjusts grade->finalgrade and grade_item->grademax values.
     * For the grade max adjustment logic should be similar to that in
     * auto_update_max for GRADE_AGGREGATE_SUM.
     * According to that function, we do not add in anything with aggregationcoef > 0
     * because that indicates extra credit.  The key difference is that we do not add in
     * the max for any hidden grades.
     */
    private function adjust_grades_recursive(&$grades, &$element, &$current_category_element=null) {

        // We use the gtree structure to guide our recursion through the grades.

        // Some of this implementation is based on get_hiding_affected in
        // lib/grade/grade_grade.

        if ($element['type'] == 'category') {

            // Using both of these to mimic the way that auto_update_max calls
            // apply_limit_rules.
            $element['gradeitems'] = array();
            $element['newgrademaxes'] = array();

            $category = $element['object'];
            $gradeitem = $category->load_grade_item();

            $grade = $grades[$gradeitem->id];
            $grade->grade_item = $gradeitem;


            // We adjust from the bottom up because category grades depend on children.
            foreach($element['children'] as &$child) {
                $this->adjust_grades_recursive($grades, $child, $element);
            }

            // First, the adjust grademax in the grade_item.

            if ($category->aggregation == GRADE_AGGREGATE_SUM) {
                $newgrademaxes = $element['newgrademaxes'];
                if (empty($newgrademaxes)) {
                    $gradeitem->grademax = 0;
                } else {
                    $category->apply_limit_rules($newgrademaxes, $element['gradeitems']);
                    if (empty($newgrademaxes)) {
                        $gradeitem->grademax = 0;
                    } else {
                        $gradeitem->grademax = array_sum($newgrademaxes);
                    }
                }
            }

            // Then, adjust finalgrade in the grade_grade.

            if (!($grade->is_locked() or $grade->is_overridden() or $gradeitem->is_calculated())) {
                $this->adjust_aggregate_finalgrade($grades, $grade, $category);
            }

            if ($grade->finalgrade === null and $category->aggregateonlygraded) {
                $gradeitem->grademax = 0;
            }

            // This determines whether the grademax is included for higher level
            // aggregation.  Primarily relevant if parent category uses Sum of Grades.
            if (!empty($current_category_element) and
                $this->include_in_grademax_aggregation($grade,
                                                       $current_category_element['object']))
            {
                $current_category_element['gradeitems'][$gradeitem->id] = $gradeitem;
                $current_category_element['newgrademaxes'][$gradeitem->id] = $gradeitem->grademax;
            }

        } else if ($element['type'] == 'courseitem' or $element['type'] == 'categoryitem') {
            // Moved some logic to the category element because this might run prior to
            // child items' branch depending on "category_grade_last".

            $gradeitem = $element['object'];
            $grade = $grades[$gradeitem->id];
            $current_category = $current_category_element['object'];
            if ($gradeitem->id == $current_category->grade_item->id) {
                // Course and category items each appear twice in the tree.  They appear as
                // the grade_item on the category object and as one of the children in the
                // in the element array.  Use the same object for both.
                $element['object'] = $current_category->grade_item;
                // Also, ensure that the grade_item on $grade is the same.
                $grade->grade_item = $current_category->grade_item;
            }
        } else if ($element['type'] == 'item') {
            $gradeitem = $element['object'];
            $grade = $grades[$gradeitem->id];
            $grade->grade_item = $gradeitem;
            if ($this->include_in_grademax_aggregation($grade,
                                                       $current_category_element['object']))
            {
                $current_category_element['gradeitems'][$gradeitem->id] = $gradeitem;
                $current_category_element['newgrademaxes'][$gradeitem->id] = $gradeitem->grademax;
            }
        }
    }

    /**
     * Sets grade properties (finalgrade, containshidden) on the grade
     * object passed by reference (implicitly, since it is an object).
     */
    private function adjust_aggregate_finalgrade($grades, $grade, $gradecategory) {
        $gradeitem = $grade->grade_item;

        $values = array();
        $childitems = array();

        foreach ($gradeitem->depends_on() as $childitemid) {
            $childgrade = $grades[$childitemid];
            $childitem  = $childgrade->grade_item;
            $childitems[$childitemid] = $childitem;

            if ($childgrade->is_hidden() or isset($childgrade->containshidden)) {

                $grade->containshidden = true;

                if ($this->showtotalsifcontainhidden
                      == GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN) {
                    $grade->finalgrade = null;
                    // Returning from inside loop.  Be careful if refactoring.
                    return;
                }
            }

            if (! $this->include_in_gradevalue_aggregation($childgrade, $gradecategory)) {
                continue;
            }

            $value = $childgrade->finalgrade;
            if ($gradecategory->aggregation != GRADE_AGGREGATE_SUM) {
                $value = grade_grade::standardise_score(
                                          $childgrade->finalgrade,
                                          $childitem->grademin,
                                          $childitem->grademax,
                                          0,
                                          1);
            }
            $values[$childitemid] = is_null($value) ? 0 : $value;
        }

        // limit and sort. Sort assumed by aggregate_values.
        $gradecategory->apply_limit_rules($values, $childitems);
        asort($values, SORT_NUMERIC);

        if ($gradecategory->aggregation == GRADE_AGGREGATE_SUM) {
            $adjfinalgrade = array_sum($values);
        } else if (empty($values)) {
            $adjfinalgrade = null;
        } else {

            $agg_grade = $gradecategory->aggregate_values($values,
                                                          $childitems);

            // recalculate the rawgrade back to requested range
            $adjfinalgrade = grade_grade::standardise_score(
                                              $agg_grade,
                                              0,
                                              1,
                                              $gradeitem->grademin,
                                              $gradeitem->grademax);
        }

        if ($adjfinalgrade === null) {
            $grade->finalgrade = null;
        } else {
            $adjfinalgrade = $gradeitem->bounded_grade($adjfinalgrade);
            $grade->finalgrade = grade_floatval($adjfinalgrade);
        }
    }

    /**
     * Returns the gradeitem ids for grade items for which this user has no grade.
     */
    private function get_gradeitems_with_empty_grades() {
        global $DB;

        $sql = "SELECT gi.id
                FROM {grade_items} gi
                LEFT JOIN {grade_grades} g ON g.itemid = gi.id and g.userid = :userid
                WHERE gi.courseid = :courseid AND g.finalgrade IS NULL";

        return $DB->get_fieldset_sql($sql,
                                     array('userid' => $this->userid,
                                           'courseid' => $this->courseid));
    }

    private function include_in_aggregation($grade, $grade_category, $include_extracredit=false) {
        global $CFG;

        // We don't include in the range if
        //    - item is extra credit (related to aggregationcoef) and we don't want extra credit
        //    - the grade is excluded
        //    - the grade is hidden and we must display the total without it
        //    - the grade is empty and we must not display empty grades
        //    - the grade is neither a value type nor a scale type with grade_includescalesinaggregation set

        $gradeitem = $grade->grade_item;

        // For the three aggregation methods included in the below condition,
        // aggregationcoef > 0 indicates that the grade_item is an
        // extra-credit item.
        $aggregationmethod = $grade_category->aggregation;
        if (!$include_extracredit and $gradeitem->extracredit > 0 and
            ($aggregationmethod == GRADE_AGGREGATE_WEIGHTED_MEAN2 or
             $aggregationmethod == GRADE_AGGREGATE_EXTRACREDIT_MEAN or
             $aggregationmethod == GRADE_AGGREGATE_SUM))
        {
            return false;
        }

        if (! $grade->is_excluded()
            and
            ! ($this->showtotalsifcontainhidden != GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN
                     and
                   $grade->is_hidden())
            and ! ($grade_category->aggregateonlygraded
                     and
                   $grade->finalgrade === null)
            and ($gradeitem->gradetype == GRADE_TYPE_VALUE or ($gradeitem->gradetype == GRADE_TYPE_SCALE
                                                               and $CFG->grade_includescalesinaggregation)))
        {
            return true;
        }
        return false;
    }

    // The following two include_in_aggration-related methods are defined only
    // to improve readability of other code.
    private function include_in_gradevalue_aggregation($grade, $grade_category) {
        return $this->include_in_aggregation($grade, $grade_category, true);
    }

    private function include_in_grademax_aggregation($grade, $grade_category) {
        return $this->include_in_aggregation($grade, $grade_category, false);
    }

}


