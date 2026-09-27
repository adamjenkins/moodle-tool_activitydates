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

/**
 * Behat steps for tool_activitydates.
 */
class behat_tool_activitydates extends behat_base {
    /** @var array page type => [script, capability that opens it] */
    private const PAGES = [
        'dates' => ['view.php', 'tool/activitydates:manage'],
        'locks' => ['locks.php', 'tool/activitydates:managelocks'],
    ];

    /**
     * Look up a page type.
     *
     * @param string $page The page type: 'dates' or 'locks'.
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
     * | locks | Course shortname | Grade locks page    |
     *
     * @param string $page The page type: 'dates' or 'locks'.
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
     * @param string $page The page type: 'dates' or 'locks'.
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
}
