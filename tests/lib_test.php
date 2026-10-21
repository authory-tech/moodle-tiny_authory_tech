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

namespace tiny_authory_tech\tests; // phpcs:ignore moodle.PHPUnit.TestCaseNames.UnexpectedLevel2NS

/**
 * Tests for the callbacks in lib.php.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::tiny_authory_tech_coursemodule_edit_post_actions
 */
final class lib_test extends \advanced_testcase {
    /**
     * Activities created without the course module form (web services, restore, generators) do not
     * carry the Authory.tech fields, and must keep their settings instead of raising a warning.
     */
    public function test_coursemodule_edit_post_actions_without_form_field(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        set_config("authory_tech-{$course->id}", 1, 'tiny_authory_tech');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->assertFalse($DB->record_exists('tiny_authory_tech_cm_settings', ['cmid' => $assign->cmid]));
    }
}
