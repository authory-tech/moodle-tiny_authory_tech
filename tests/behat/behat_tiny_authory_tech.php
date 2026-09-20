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
 * Custom Behat step definitions for tiny_authory_tech.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Behat\Context\Context;

// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod

/**
 * Step definitions for tiny_authory_tech Behat tests.
 */
class behat_tiny_authory_tech extends behat_base {
    /**
     * Navigate directly to the Authory writing dashboard for a given activity (by idnumber).
     *
     * Example: Given I am on the writing dashboard for "assign1"
     *
     * @Given I am on the writing dashboard for :activityidnumber
     * @param string $activityidnumber The idnumber of the activity (as set in the Behat data generator).
     */
    public function i_am_on_the_writing_dashboard_for(string $activityidnumber): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $activityidnumber], '*', MUST_EXIST);

        $url = new moodle_url(
            '/lib/editor/tiny/plugins/authory_tech/assign_dashboard.php',
            ['id' => $cm->id]
        );

        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Assert the writing dashboard is showing the correct assignment name in the header.
     *
     * Example: Then the writing dashboard should show "Test Assignment"
     *
     * @Then the writing dashboard should show :assignmentname
     * @param string $assignmentname The assignment name expected in the dashboard header.
     */
    public function the_writing_dashboard_should_show(string $assignmentname): void {
        $this->execute('behat_general::assert_page_contains_text', [$assignmentname]);
        $this->execute('behat_general::assert_page_contains_text', ['Writing Analytics Dashboard']);
    }

    /**
     * Enable Authory.tech for the course identified by shortname.
     *
     * Example: Given authory_tech is enabled for course "C1"
     *
     * @Given authory_tech is enabled for course :shortname
     * @param string $shortname The shortname of the course.
     */
    public function authory_tech_is_enabled_for_course(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        set_config("authory_tech-{$course->id}", '1', 'tiny_authory_tech');
    }

    /**
     * Configures the plugin so it will actually attempt to reach type-server (passing
     * the early "not configured" check in get_student_stats()) but the address is
     * unreachable — the same failure mode as type-server's DB being down in
     * production. Used to test that the dashboard shows "unavailable" rather than a
     * misleading "0 WPM" when real stats can't be fetched.
     *
     * Example: Given the type-server for this site is unreachable
     *
     * @Given the type-server for this site is unreachable
     */
    public function the_typeserver_for_this_site_is_unreachable(): void {
        set_config('secretkey', 'behat-test-secret', 'tiny_authory_tech');
        set_config('python_server', 'http://127.0.0.1:1', 'tiny_authory_tech');
    }

    /**
     * Seeds a tiny_authory_tech_files row with delta-event content for a student's
     * assignment submission, so the dashboard has a student panel to open.
     *
     * Example: Given there is a writing submission from "student1" for "assign1"
     *
     * @Given there is a writing submission from :username for :activityidnumber
     * @param string $username
     * @param string $activityidnumber
     */
    public function there_is_a_writing_submission_from_for(string $username, string $activityidnumber): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $cm   = $DB->get_record('course_modules', ['idnumber' => $activityidnumber], '*', MUST_EXIST);

        $base = 1_700_000_000_000;
        $events = [
            ['t' => $base, 'd' => [['insert' => 'H']], 'meta' => 'keystroke'],
            ['t' => $base + 2000, 'd' => [['retain' => 1], ['insert' => 'i']], 'meta' => 'keystroke'],
        ];

        $DB->insert_record('tiny_authory_tech_files', (object) [
            'userid'          => $user->id,
            'cmid'            => $cm->id,
            'courseid'        => $cm->course,
            'modulename'      => 'assign',
            'resourceid'      => 1,
            'filename'        => "{$user->id}_1_{$cm->id}_attempt.json",
            'content'         => json_encode($events),
            'original_content' => 'Hi',
            'timemodified'    => time(),
            'uploaded'        => 0,
        ]);
    }
}
