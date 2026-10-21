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

use tiny_authory_tech\observers;

/**
 * Tests for the tiny_authory_tech event observers.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\observers
 */
final class observers_test extends \advanced_testcase {
    /**
     * Store a file with its statistics, differences and a comment in a course module.
     *
     * @param int $userid The user.
     * @param \stdClass $cm The course module.
     */
    private function create_session(int $userid, \stdClass $cm): void {
        global $DB;

        $fileid = $DB->insert_record('tiny_authory_tech_files', (object) [
            'userid' => $userid,
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'filename' => "{$userid}_{$cm->id}_{$cm->id}_attempt.json",
            'modulename' => 'assign',
            'resourceid' => $cm->id,
            'timemodified' => time(),
        ]);
        $DB->insert_record('tiny_authory_tech_user_writing', (object) [
            'file_id' => $fileid,
            'total_time_seconds' => 60,
            'keys_per_minute' => 100,
        ]);
        $DB->insert_record('tiny_authory_tech_writing_diff', (object) [
            'file_id' => $fileid,
            'reconstructed_text' => 'Essay',
            'submitted_text' => 'Essay',
        ]);
        $DB->insert_record('tiny_authory_tech_comments', (object) [
            'userid' => $userid,
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'modulename' => 'assign',
            'resourceid' => $cm->id,
            'usercomment' => 'Comment',
            'timemodified' => time(),
        ]);
    }

    /**
     * Resetting a course removes its writing data from all four tables and leaves other courses alone.
     */
    public function test_reset_tracking_data(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $courses = [];
        foreach ([1, 2] as $i) {
            $courses[$i] = $generator->create_course();
            $assign = $generator->create_module('assign', ['course' => $courses[$i]->id]);
            $this->create_session($user->id, get_coursemodule_from_instance('assign', $assign->id));
        }

        $event = \core\event\course_reset_ended::create([
            'context' => \context_course::instance($courses[1]->id),
            'other' => ['reset_options' => []],
        ]);
        observers::reset_tracking_data($event);

        foreach ([1 => 0, 2 => 1] as $i => $expected) {
            $params = ['courseid' => $courses[$i]->id];
            $this->assertEquals($expected, $DB->count_records('tiny_authory_tech_files', $params));
            $this->assertEquals($expected, $DB->count_records('tiny_authory_tech_comments', $params));
            foreach (['tiny_authory_tech_user_writing', 'tiny_authory_tech_writing_diff'] as $table) {
                $this->assertEquals($expected, $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {{$table}} t
                       JOIN {tiny_authory_tech_files} f ON f.id = t.file_id
                      WHERE f.courseid = :courseid",
                    $params
                ));
            }
        }
        // No statistics or differences are left pointing at deleted files.
        $this->assertEquals(1, $DB->count_records('tiny_authory_tech_user_writing'));
        $this->assertEquals(1, $DB->count_records('tiny_authory_tech_writing_diff'));
    }
}
