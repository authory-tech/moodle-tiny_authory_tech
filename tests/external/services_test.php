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

namespace tiny_authory_tech\external;

use core_external\external_api;

/**
 * Tests for the plugin's web service registrations and access checks.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\external\disable_all_course
 */
final class services_test extends \advanced_testcase {
    /**
     * Every function declared in db/services.php is installed, implemented by a class in
     * classes/external, and exposes valid parameter and return descriptions.
     */
    public function test_every_registered_function_resolves(): void {
        global $CFG, $DB;

        require($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/db/services.php');
        // The $functions array is defined by db/services.php.
        $this->assertNotEmpty($functions);

        $installed = $DB->count_records('external_functions', ['component' => 'tiny_authory_tech']);
        $this->assertSame(count($functions), $installed);

        foreach (array_keys($functions) as $name) {
            $info = external_api::external_function_info($name);
            $this->assertStringStartsWith('tiny_authory_tech\\external\\', $info->classname, $name);
            $this->assertSame('execute', $info->methodname, $name);
        }
    }

    /**
     * A student cannot switch the plugin off for every course.
     */
    public function test_disable_all_course_requires_editsettings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        disable_all_course::execute(true);
    }

    /**
     * An administrator can switch the plugin off, which is stored per course.
     */
    public function test_disable_all_course_as_admin_disables_every_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertTrue(disable_all_course::execute(true));
        $this->assertEmpty(get_config('tiny_authory_tech', "authory_tech-{$course->id}"));
    }
}
