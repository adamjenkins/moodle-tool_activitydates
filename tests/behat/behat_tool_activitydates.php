<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat steps for tool_activitydates.
 *
 * @package    tool_activitydates
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;
use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Behat steps for tool_activitydates.
 */
class behat_tool_activitydates extends behat_base {
    /** @var array page type => [script, capability named when access is refused] */
    private const PAGES = [
        'dates' => ['view.php', 'tool/activitydates:manage'],
    ];

    /**
     * Look up a page type.
     *
     * @param string $page The page type: 'dates'.
     * @return array [script, capability]
     * @throws Exception If the page type is unknown.
     */
    private function page_info(string $page): array {
        $key = strtolower($page);
        if (!isset(self::PAGES[$key])) {
            throw new Exception("Unrecognised tool_activitydates page type '{$page}'");
        }
        return self::PAGES[$key];
    }

    /**
     * Convert page names to URLs for 'I am on the "[identifier]" "tool_activitydates > [page]" page'.
     *
     * | page  | identifier       | description         |
     * | dates | Course shortname | Activity dates page |
     *
     * @param string $page The page type: 'dates'.
     * @param string $identifier The course shortname.
     * @return moodle_url
     * @throws Exception If the page type is unknown.
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        [$script] = $this->page_info($page);
        return new moodle_url('/admin/tool/activitydates/' . $script, [
            'courseid' => $this->get_course_id($identifier),
        ]);
    }

    /**
     * Open a tool_activitydates page directly and check that the current user is refused.
     *
     * Core has no step for this: every step is followed by look_for_exceptions(), which
     * fails on any page showing a Moodle exception. So this step opens the page, checks
     * that the exception is the missing-capability one for that page, and then leaves the
     * error page for the site home page.
     *
     * @Then /^I should be refused access to the "(?P<identifier>[^"]*)" "tool_activitydates > (?P<page>[^"]*)" page$/
     *
     * @param string $identifier The course shortname.
     * @param string $page The page type: 'dates'.
     * @throws ExpectationException If the page opened, or failed for another reason.
     */
    public function i_should_be_refused_access_to_page(string $identifier, string $page): void {
        [, $capability] = $this->page_info($page);
        $url = $this->resolve_page_instance_url($page, $identifier);

        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $error = $this->getSession()->getPage()->find('xpath', "//div[@data-rel='fatalerror']");
        if (!$error) {
            throw new ExpectationException(
                "The '{$page}' page opened; access should have been refused",
                $this->getSession()
            );
        }
        $expected = get_string('nopermissions', 'error', get_capability_string($capability));
        if (!str_contains($error->getText(), $expected)) {
            throw new ExpectationException(
                "Expected '{$expected}' but the page showed: " . $error->getText(),
                $this->getSession()
            );
        }

        // Leave the error page so the after-step exception check sees a normal page.
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Open the former Grade locks page of a course, which now redirects to the Activity dates page.
     *
     * @When /^I open the former Grade locks page of "(?P<identifier>[^"]*)"$/
     *
     * @param string $identifier The course shortname.
     */
    public function i_open_the_former_grade_locks_page(string $identifier): void {
        $url = new moodle_url('/admin/tool/activitydates/locks.php', ['courseid' => $this->get_course_id($identifier)]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Skip the scenario when the activity type's table has no duedate column.
     *
     * Quiz gained a duedate column in Moodle 5.3; on earlier versions the due-date
     * controls are not offered, so a due-date scenario cannot run.
     *
     * @Given /^the "(?P<modtype>[^"]*)" activity table has a due date column$/
     *
     * @param string $modtype The module type, e.g. 'quiz'.
     * @throws SkippedException If the table has no duedate column.
     */
    public function the_activity_table_has_a_due_date_column(string $modtype): void {
        global $DB;
        if (!isset($DB->get_columns($modtype)['duedate'])) {
            throw new SkippedException("The '{$modtype}' table has no duedate column on this Moodle version");
        }
    }

    /**
     * Skip the scenario when the activity type's table has a duedate column.
     *
     * The converse of the step above, for the scenarios that check that due dates
     * are not offered before Moodle 5.3.
     *
     * @Given /^the "(?P<modtype>[^"]*)" activity table has no due date column$/
     *
     * @param string $modtype The module type, e.g. 'quiz'.
     * @throws SkippedException If the table has a duedate column.
     */
    public function the_activity_table_has_no_due_date_column(string $modtype): void {
        global $DB;
        if (isset($DB->get_columns($modtype)['duedate'])) {
            throw new SkippedException("The '{$modtype}' table has a duedate column on this Moodle version");
        }
    }

    /**
     * Check one date of an activity instance, read from the database.
     *
     * The value is compared as YYYY-MM-DDTHH:MM in the logged-in user's timezone,
     * or "0" when the date is not set.
     *
     * @Then /^the "(?P<field>timeopen|duedate|timeclose)" of "(?P<idnumber>[^"]*)" should be "(?P<datetime>[^"]*)"$/
     *
     * @param string $field The instance field: timeopen, duedate or timeclose.
     * @param string $idnumber The activity idnumber.
     * @param string $datetime The expected value: YYYY-MM-DDTHH:MM, or 0 for none.
     * @throws ExpectationException If the value differs.
     */
    public function the_activity_date_should_be(string $field, string $idnumber, string $datetime): void {
        global $DB;
        $cm = $this->get_cm_by_idnumber($idnumber);
        if (!isset($DB->get_columns($cm->modname)[$field])) {
            throw new ExpectationException("The '{$cm->modname}' table has no '{$field}' column", $this->getSession());
        }
        $time = (int) $DB->get_field($cm->modname, $field, ['id' => $cm->instance], MUST_EXIST);
        $this->assert_timestamp($time, $datetime, "The {$field} of '{$idnumber}'");
    }

    /**
     * Check the gradebook lock date of an activity's grade item, read from the database.
     *
     * @Then /^the grade lock date of "(?P<idnumber>[^"]*)" should be "(?P<datetime>[^"]*)"$/
     *
     * @param string $idnumber The activity idnumber.
     * @param string $datetime The expected value: YYYY-MM-DDTHH:MM, or 0 for none.
     * @throws ExpectationException If the value differs.
     */
    public function the_grade_lock_date_should_be(string $idnumber, string $datetime): void {
        global $DB;
        $cm = $this->get_cm_by_idnumber($idnumber);
        $time = (int) $DB->get_field('grade_items', 'locktime', [
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
        ], MUST_EXIST);
        $this->assert_timestamp($time, $datetime, "The grade lock date of '{$idnumber}'");
    }

    /**
     * Set one editable date in the preview table.
     *
     * The value is assigned by JavaScript and input/change events are dispatched,
     * because typing into a datetime-local input depends on the browser's locale.
     *
     * @When /^I set the "(?P<field>[^"]*)" date of "(?P<activityname>[^"]*)" to "(?P<value>[^"]*)"$/
     *
     * @param string $field The date field: timeopen, duedate, timeclose or timelock.
     * @param string $activityname The activity name shown in the table.
     * @param string $value The value, YYYY-MM-DDTHH:MM, or empty.
     * @throws ExpectationException If the row has no such input.
     */
    public function i_set_the_date_of_activity_to(string $field, string $activityname, string $value): void {
        $input = $this->find_date_input($field, $activityname);
        $this->execute_js_on_node($input, '{{ELEMENT}}.value = ' . json_encode($value) . ';' .
            '{{ELEMENT}}.dispatchEvent(new Event("input", {bubbles: true}));' .
            '{{ELEMENT}}.dispatchEvent(new Event("change", {bubbles: true}));');
    }

    /**
     * Move the keyboard focus to one editable date in the preview table.
     *
     * @When /^I focus the "(?P<field>[^"]*)" date of "(?P<activityname>[^"]*)"$/
     *
     * @param string $field The date field: timeopen, duedate, timeclose or timelock.
     * @param string $activityname The activity name shown in the table.
     * @throws ExpectationException If the row has no such input.
     */
    public function i_focus_the_date_of_activity(string $field, string $activityname): void {
        $input = $this->find_date_input($field, $activityname);
        $this->execute_js_on_node($input, '{{ELEMENT}}.focus();');
    }

    /**
     * Check the value of one editable date in the preview table.
     *
     * @Then /^the "(?P<field>[^"]*)" date input of "(?P<activityname>[^"]*)" should be "(?P<value>[^"]*)"$/
     *
     * @param string $field The date field: timeopen, duedate, timeclose or timelock.
     * @param string $activityname The activity name shown in the table.
     * @param string $value The expected value, YYYY-MM-DDTHH:MM, or empty.
     * @throws ExpectationException If the value differs.
     */
    public function the_date_input_should_be(string $field, string $activityname, string $value): void {
        $actual = (string) $this->find_date_input($field, $activityname)->getValue();
        if ($actual !== $value) {
            throw new ExpectationException(
                "The {$field} input of '{$activityname}' is '{$actual}', expected '{$value}'",
                $this->getSession()
            );
        }
    }

    /**
     * Re-enable a button that the page's JavaScript disabled, to test the server-side check behind it.
     *
     * @When /^I remove the disabled attribute from the "(?P<button>[^"]*)" button$/
     *
     * @param string $button The button label.
     */
    public function i_remove_the_disabled_attribute_from_button(string $button): void {
        $node = $this->find('button', $button);
        $this->execute_js_on_node($node, '{{ELEMENT}}.disabled = false;');
    }

    /**
     * Delete an activity straight away, as a teacher would from the course page.
     *
     * The recycle bin is switched off first, so the module goes now and not after a backup.
     *
     * @Given /^the activity "(?P<idnumber>[^"]*)" is deleted$/
     *
     * @param string $idnumber The activity idnumber.
     */
    public function the_activity_is_deleted(string $idnumber): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $cm = $this->get_cm_by_idnumber($idnumber);
        // Moodle 5.2 moved the deletion into the course format actions.
        $cmactions = new \core_courseformat\local\cmactions(get_course($cm->course));
        if (method_exists($cmactions, 'delete')) {
            $cmactions->delete((int) $cm->id);
        } else {
            course_delete_module((int) $cm->id);
        }
    }

    /**
     * Find a course module by idnumber.
     *
     * @param string $idnumber The activity idnumber.
     * @return stdClass The course_modules record plus modname.
     */
    private function get_cm_by_idnumber(string $idnumber): stdClass {
        global $DB;
        return $DB->get_record_sql(
            'SELECT cm.*, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.idnumber = :idnumber',
            ['idnumber' => $idnumber],
            MUST_EXIST
        );
    }

    /**
     * Compare a timestamp with an expected YYYY-MM-DDTHH:MM value in the logged-in user's timezone.
     *
     * @param int $time The timestamp, 0 for none.
     * @param string $expected The expected value, or "0" for none.
     * @param string $what What is compared, for the failure message.
     * @throws ExpectationException If the values differ.
     */
    private function assert_timestamp(int $time, string $expected, string $what): void {
        $timezone = core_date::get_user_timezone($this->get_session_user());
        $actual = $time ? userdate($time, '%Y-%m-%dT%H:%M', $timezone, false, false) : '0';
        if ($actual !== $expected) {
            throw new ExpectationException("{$what} is '{$actual}', expected '{$expected}'", $this->getSession());
        }
    }

    /**
     * Find one editable date input in the preview table row of the named activity.
     *
     * @param string $field The date field: timeopen, duedate, timeclose or timelock.
     * @param string $activityname The activity name shown in the table.
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException If the row has no such input.
     */
    private function find_date_input(string $field, string $activityname): \Behat\Mink\Element\NodeElement {
        $name = behat_context_helper::escape($activityname);
        $fieldliteral = behat_context_helper::escape($field);
        $xpath = "//div[@data-region='tool_activitydates-previewtable']//tr[.//a[normalize-space(.)={$name}]]" .
            "//input[@type='datetime-local'][@data-field={$fieldliteral}]";
        $input = $this->getSession()->getPage()->find('xpath', $xpath);
        if (!$input) {
            throw new ExpectationException(
                "No editable {$field} date for '{$activityname}' in the preview table",
                $this->getSession()
            );
        }
        return $input;
    }
}
