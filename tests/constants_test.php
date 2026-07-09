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
 * Unit tests for tiny_authory_tech\constants.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\constants
 */
final class constants_test extends \advanced_testcase {
    // Tests for normalize_string.

    /**
     * Tests that normalize_string returns the expected normalized output.
     *
     * @param string $input The input string to normalize.
     * @param string $expected The expected normalized output.
     * @dataProvider normalize_string_provider
     */
    public function test_normalize_string(string $input, string $expected): void {
        $this->assertSame($expected, \tiny_authory_tech\constants::normalize_string($input));
    }

    /**
     * Data provider for test_normalize_string.
     *
     * @return array
     */
    public static function normalize_string_provider(): array {
        return [
            'html entities decoded' => ['Hello &amp; World', 'Hello & World'],
            'nbsp replaced by space' => ["Hello\xc2\xa0World", 'Hello World'],
            'multiple spaces collapsed' => ['Hello   World', 'Hello World'],
            'leading/trailing whitespace' => ['  Hello  ', 'Hello'],
            'mixed entities and whitespace' => ['  &lt;b&gt;   text  &lt;/b&gt;  ', '<b> text </b>'],
            'already clean string' => ['Hello World', 'Hello World'],
            'empty string' => ['', ''],
        ];
    }

    // Tests for get_paste_setting.

    public function test_get_paste_setting_defaults_to_allow(): void {
        $this->resetAfterTest();
        // No config stored — should return default.
        $this->assertSame('allow', \tiny_authory_tech\constants::get_paste_setting(9999, 8888));
    }

    public function test_get_paste_setting_returns_configured_value(): void {
        $this->resetAfterTest();
        set_config('PASTE1_2', 'block', 'tiny_authory_tech');
        $this->assertSame('block', \tiny_authory_tech\constants::get_paste_setting(1, 2));
    }

    // Tests for is_teacher_admin.

    public function test_is_teacher_admin_returns_true_for_editing_teacher(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $context = \context_course::instance($course->id);
        $this->assertTrue(\tiny_authory_tech\constants::is_teacher_admin($context));
    }

    public function test_is_teacher_admin_returns_false_for_student(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $context = \context_course::instance($course->id);
        $this->assertFalse(\tiny_authory_tech\constants::is_teacher_admin($context));
    }

    public function test_is_teacher_admin_returns_true_for_site_admin(): void {
        $this->resetAfterTest();

        $admin = get_admin();
        $this->setUser($admin);
        $context = \context_system::instance();
        $this->assertTrue(\tiny_authory_tech\constants::is_teacher_admin($context));
    }

    // Tests for the NAMES constant.

    public function test_names_contains_required_module_types(): void {
        $required = ['assign', 'forum', 'quiz', 'lesson'];
        foreach ($required as $mod) {
            $this->assertContains(
                $mod,
                \tiny_authory_tech\constants::NAMES,
                "NAMES constant should include module type '$mod'"
            );
        }
    }

    // Tests for the BODY_IDS constant.

    public function test_body_ids_each_entry_has_two_element_array(): void {
        foreach (\tiny_authory_tech\constants::BODY_IDS as $bodyid => $entry) {
            $this->assertIsArray($entry, "BODY_IDS[$bodyid] must be an array");
            $this->assertCount(
                2,
                $entry,
                "BODY_IDS[$bodyid] must have exactly 2 elements [module_name, activity_type]"
            );
            $this->assertNotEmpty($entry[0], "BODY_IDS[$bodyid][0] (AMD module name) must not be empty");
            $this->assertNotEmpty($entry[1], "BODY_IDS[$bodyid][1] (activity type) must not be empty");
        }
    }

    public function test_body_ids_assign_view_is_registered(): void {
        $this->assertArrayHasKey(
            'page-mod-assign-view',
            \tiny_authory_tech\constants::BODY_IDS,
            'The assignment view page must be registered to inject the dashboard button'
        );
    }

    // Tests for enable_replay.

    public function test_enable_replay_returns_true_when_unset(): void {
        $this->resetAfterTest();
        // When config is not set, default to true for backward compatibility.
        $this->assertTrue(\tiny_authory_tech\constants::enable_replay());
    }

    public function test_enable_replay_returns_true_when_explicitly_enabled(): void {
        $this->resetAfterTest();
        set_config('enable_replay', 1, 'tiny_authory_tech');
        $this->assertTrue(\tiny_authory_tech\constants::enable_replay());
    }

    public function test_enable_replay_returns_false_when_explicitly_disabled(): void {
        $this->resetAfterTest();
        set_config('enable_replay', 0, 'tiny_authory_tech');
        $this->assertFalse(\tiny_authory_tech\constants::enable_replay());
    }
}
