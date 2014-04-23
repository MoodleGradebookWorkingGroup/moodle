<?php

// This started from lib/grade/tests/grade_category_test.php.  Removed all
// subtests except for the generate_grades subtest, which was broke and had
// been commented out.

/**
 * @author     Colin Campbell (OIT / University of Minnesota)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/grade/tests/fixtures/lib.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/user_view_data_adjuster.php');

class grade_category_testcase extends grade_base_testcase {

    public static function tearDownAfterClass() {
        parent::tearDownAfterClass();
        ####exit;
    }

    protected function tearDown() {
        // For inspecting the database after running.
        #####exit;
    }

    public function test_grade_category() {
        global $CFG;

        # These tests assume this setting.
        $CFG->unlimitedgrades = 1;

        #$this->resetAfterTest(false);

        $this->sub_test_get_adjusted_course_grades();
        $this->sub_test_grade_category_generate_grades();
    }

    protected function sub_test_get_adjusted_course_grades() {

        // First, try static method that returns only course-level final grade.
        $grade = grade_user_view_data_adjuster::get_adjusted_course_grade(
                                                     $this->user[1]->id,
                                                     $this->courseid);
        #print "\n finalgrade: $grade->finalgrade ";

        grade_regrade_final_grades($this->courseid);
        $grades = grade_user_view_data_adjuster::get_adjusted_course_grades(
                                                     $this->user[1]->id,
                                                     $this->courseid);

        // Use this category_tree just to iterate through the grade items.
        $coursetree = grade_category::fetch_course_tree($this->courseid, false);
        #print_r($coursetree);

        #print "\n";
        #print $grades[$coursetree['object']->grade_item->id]->finalgrade;

        #print "\n";
        #foreach ($grades as $grade) {
        #    print $grade->finalgrade;
        #    print " ; ";
        #    if (isset($grade->grade_item)) {
        #        print $grade->grade_item->itemname;
        #        print " ; ";
        #        print $grade->grade_item->itemtype;
        #    }
        #    print "\n";
        #}
    }

    protected function sub_test_grade_category_generate_grades() {
        global $DB;

        //inserting some special grade items to make testing the final grade calculation easier
        $params = new stdClass;
        $params->courseid = $this->courseid;
        $params->fullname = 'unittestgradecalccategory';
        $params->aggregation = GRADE_AGGREGATE_MEAN;
        $params->aggregateonlygraded = 0;
        $grade_category = new grade_category($params, false);
        $grade_category->insert();

        $this->assertTrue(method_exists($grade_category, 'generate_grades'));

        $grade_category->load_grade_item();
        $cgi = $grade_category->get_grade_item();
        $cgi->grademin = 0;
        $cgi->grademax = 20;//3 grade items out of 10 but category is out of 20 to force scaling to occur
        $cgi->update();

        //3 grade items each with a maximum grade of 10
        $grade_items = array();
        for ($i=0; $i<3; $i++) {
            $grade_items[$i] = new grade_item();
            $grade_items[$i]->courseid = $this->courseid;
            $grade_items[$i]->categoryid = $grade_category->id;
            $grade_items[$i]->itemname = 'manual grade_item '.$i;
            $grade_items[$i]->itemtype = 'manual';
            $grade_items[$i]->itemnumber = 0;
            $grade_items[$i]->needsupdate = false;
            $grade_items[$i]->gradetype = GRADE_TYPE_VALUE;
            $grade_items[$i]->grademin = 0;
            $grade_items[$i]->grademax = 10;
            $grade_items[$i]->iteminfo = 'Manual grade item used for unit testing';
            $grade_items[$i]->timecreated = time();
            $grade_items[$i]->timemodified = time();

            //used as the weight by weighted mean and as extra credit by mean with extra credit
            //Will be 0, 1 and 2
            $grade_items[$i]->aggregationcoef = $i;

            $grade_items[$i]->insert();
        }

        //a grade for each grade item
        $grade_grades = array();
        for ($i=0; $i<3; $i++) {
            $grade_grades[$i] = new grade_grade();
            $grade_grades[$i]->itemid = $grade_items[$i]->id;
            $grade_grades[$i]->userid = $this->userid;
            $grade_grades[$i]->rawgrade = ($i+1)*2;//produce grade grades of 2, 4 and 6
            $grade_grades[$i]->finalgrade = ($i+1)*2;
            $grade_grades[$i]->timecreated = time();
            $grade_grades[$i]->timemodified = time();
            $grade_grades[$i]->information = '1 of 2 grade_grades';
            $grade_grades[$i]->informationformat = FORMAT_PLAIN;
            $grade_grades[$i]->feedback = 'Good, but not good enough..';
            $grade_grades[$i]->feedbackformat = FORMAT_PLAIN;

            $grade_grades[$i]->insert();
        }

        //3 grade items with 1 grade_grade each.
        //grade grades have the values 2, 4 and 6

        //First correct answer is the aggregate with all 3 grades
        //Second correct answer is with the first grade (value 2) hidden

        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_MEDIAN, 'GRADE_AGGREGATE_MEDIAN', 8, 10);
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_MAX, 'GRADE_AGGREGATE_MAX', 12, 12);
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_MODE, 'GRADE_AGGREGATE_MODE', 12, 12);

        //weighted mean. note grade totals are rounded to an int to prevent rounding discrepancies. correct final grade isnt actually exactly 10
        //3 items with grades 2, 4 and 6 with weights 0, 1 and 2 and all out of 10. then doubled to be out of 20.
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_WEIGHTED_MEAN, 'GRADE_AGGREGATE_WEIGHTED_MEAN', 10, 10);

        //mean of grades with extra credit
        //3 items with grades 2, 4 and 6 with extra credit 0, 0, and 2 equally weighted and all out of 20.
        $grade_items[1]->aggregationcoef = 0;
        $grade_items[1]->update();
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_EXTRACREDIT_MEAN, 'GRADE_AGGREGATE_EXTRACREDIT_MEAN', 18, 32);

        //simple weighted mean
        //3 items with grades 2, 4 and 6 equally weighted and all out of 10. then doubled to be out of 20.
        // Colin.  Added aggregationcoef reset.
        for ($i=0; $i<3; $i++) {
            $grade_items[$i]->aggregationcoef = 0;
            $grade_items[$i]->update();
        }
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_WEIGHTED_MEAN2, 'GRADE_AGGREGATE_WEIGHTED_MEAN2', 8, 10);

        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_MEAN, 'GRADE_AGGREGATE_MEAN', 8, 10);

        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_MIN, 'GRADE_AGGREGATE_MIN', 4, 8);

        #print "\nGRADE_AGGREGATE_SUM ";
        $cgi->grademax = 30;
        $cgi->update();
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_SUM, 'GRADE_AGGREGATE_SUM', 12, 10);

        $grade_grades[1]->rawgrade = null;
        $grade_grades[1]->finalgrade = null;
        $grade_grades[1]->update();
        $grade_category->aggregateonlygraded = 1;
        $grade_category->update();
        $this->helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, GRADE_AGGREGATE_SUM, 'GRADE_AGGREGATE_SUM', 8, 6);
        #print " GRADE_AGGREGATE_SUM\n";
    }

    /**
     * Test grade category aggregation using the supplied grade objects and aggregation method
     * @param grade_category $grade_category the category to be tested
     * @param array $grade_items array of instance of grade_item
     * @param array $grade_grades array of instances of grade_grade
     * @param int $aggmethod the aggregation method to apply ie GRADE_AGGREGATE_MEAN
     * @param string $aggmethodname the name of the aggregation method to apply. Used to display any test failure messages
     * @param int $correct1 the correct final grade for the category with NO items hidden
     * @param int $correct2 the correct final grade for the category with the grade at $grade_grades[0] hidden
     * @return void
    */
    protected function helper_test_grade_agg_method($grade_category, $grade_items, $grade_grades, $aggmethod, $aggmethodname, $correct1, $adjusted) {
        global $DB;

        $grade_category->aggregation = $aggmethod;
        $grade_category->update();

        //check grade_item isnt hidden from a previous test
        $grade_items[0]->set_hidden(0, true);
        $this->helper_test_grade_aggregation_result($grade_category, $correct1, $correct1, 'Testing aggregation method('.$aggmethodname.') with no items hidden %s');

        //hide the grade item with grade of 2
        $grade_items[0]->set_hidden(1, true);
        $this->helper_test_grade_aggregation_result($grade_category, $correct1, $adjusted, 'Testing aggregation method('.$aggmethodname.') with 1 item hidden %s');
    }

    /**
     * Verify the value of the category grade item for $this->userid
     * @param grade_category $grade_category the category to be tested
     * @param int $correctgrade the expected grade
     * @param string msg The message that should be displayed if the correct grade is not found
     * @return void
     */
    protected function helper_test_grade_aggregation_result($grade_category, $correctgrade, $adjusted, $msg) {
        global $DB;

        $category_grade_item = $grade_category->get_grade_item();

        //this creates all the grade_grades we need
        grade_regrade_final_grades($this->courseid);
        $grade = $DB->get_record('grade_grades', array('itemid'=>$category_grade_item->id, 'userid'=>$this->userid));

        #print " grademax:$category_grade_item->grademax ";
        #### Colin. rawgrade appears to be null here for the category_grade_item
        ####$this->assertEquals($grade->rawgrade, $grade->rawgrademin, $msg, $grade->rawgrademax);
        $this->assertEquals(intval($correctgrade), intval($grade->finalgrade), $msg);

        #### TODO: Test with category first and last. Ensure implementation doesn't care.
        #### TODO: Check percentages, also.
        $category_grade_last = false;
        $gtree = new grade_tree($this->courseid, false, $category_grade_last, null, true); 

        $showtotalsifcontainhidden = GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN;
        $data_adjuster = new grade_user_view_data_adjuster(
                                $this->userid,
                                $this->course,
                                $showtotalsifcontainhidden);

        $adjusted_grades = $data_adjuster->get_adjusted_grades($gtree);
        #print " adj_grademax ($category_grade_item->id):".$adjusted_grades[$category_grade_item->id]->grade_item->grademax;
        $this->assertEquals(intval($adjusted),
                            intval($adjusted_grades[$category_grade_item->id]->finalgrade),
                            $msg.' (ADJUSTED) ');

        #### Colin. The below comment was in the original.
        /*
         * TODO this doesnt work as the grade_grades created by $grade_category->generate_grades(); dont
         * observe the category's max grade
        //delete the grade_grades for the category itself and check they get recreated correctly
        $DB->delete_records('grade_grades', array('itemid'=>$category_grade_item->id));
        $grade_category->generate_grades();

        $grade = $DB->get_record('grade_grades', array('itemid'=>$category_grade_item->id, 'userid'=>$this->userid));
        $this->assertWithinMargin($grade->rawgrade, $grade->rawgrademin, $grade->rawgrademax);
        $this->assertEquals(intval($correctgrade), intval($grade->finalgrade), $msg);
         *
         */
    }

}
