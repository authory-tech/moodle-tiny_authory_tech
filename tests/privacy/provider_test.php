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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tiny_authory_tech\privacy\provider;

/**
 * Privacy provider tests for tiny_authory_tech.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course used across tests. */
    private \stdClass $course;

    /** @var \stdClass Course module used across tests. */
    private \stdClass $cm;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id, $this->course->id);
    }

    // Metadata.

    public function test_get_metadata_returns_collection(): void {
        $collection = new \core_privacy\local\metadata\collection('tiny_authory_tech');
        $result = provider::get_metadata($collection);
        $this->assertInstanceOf(\core_privacy\local\metadata\collection::class, $result);
    }

    public function test_get_metadata_declares_files_table(): void {
        $collection = new \core_privacy\local\metadata\collection('tiny_authory_tech');
        provider::get_metadata($collection);

        $items = $collection->get_collection();
        $tablenames = array_map(fn($item) => $item->get_name(), $items);

        $this->assertContains(
            'tiny_authory_tech_files',
            $tablenames,
            'Provider must declare the tiny_authory_tech_files table in its metadata'
        );
    }

    public function test_get_metadata_declares_comments_table(): void {
        $collection = new \core_privacy\local\metadata\collection('tiny_authory_tech');
        provider::get_metadata($collection);

        $items = $collection->get_collection();
        $tablenames = array_map(fn($item) => $item->get_name(), $items);

        $this->assertContains(
            'tiny_authory_tech_comments',
            $tablenames,
            'Provider must declare the tiny_authory_tech_comments table in its metadata'
        );
    }

    // Context list.

    public function test_get_contexts_for_userid_returns_context_with_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        $record = (object)[
            'userid' => $user->id,
            'cmid' => $this->cm->id,
            'courseid' => $this->course->id,
            'filename' => 'test.json',
            'modulename' => 'assign',
            'resourceid' => 1,
            'uploaded' => 0,
            'timemodified' => time(),
        ];
        $DB->insert_record('tiny_authory_tech_files', $record);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $contextids = $contextlist->get_contextids();

        $this->assertContainsEquals(
            $context->id,
            $contextids,
            'The module context should be returned for a user with file data'
        );
    }

    public function test_get_contexts_for_userid_returns_empty_for_no_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertEmpty(
            $contextlist->get_contextids(),
            'No contexts should be returned for a user with no data'
        );
    }

    // Export.

    public function test_export_user_data_writes_file_records(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        $record = (object)[
            'userid' => $user->id,
            'cmid' => $this->cm->id,
            'courseid' => $this->course->id,
            'filename' => 'writing.json',
            'modulename' => 'assign',
            'resourceid' => 1,
            'uploaded' => 0,
            'timemodified' => time(),
        ];
        $DB->insert_record('tiny_authory_tech_files', $record);

        $contextlist = new approved_contextlist($user, 'tiny_authory_tech', [$context->id]);
        provider::export_user_data($contextlist);

        // The writer should have received data for this context.
        $writer = writer::with_context($context);
        $this->assertTrue(
            $writer->has_any_data(),
            'Export should write data for the user\'s context'
        );
    }

    // Deletion.

    public function test_delete_data_for_all_users_in_context_removes_records(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        foreach ([$user1->id, $user2->id] as $uid) {
            $record = (object)[
                'userid' => $uid,
                'cmid' => $this->cm->id,
                'courseid' => $this->course->id,
                'filename' => 'test.json',
                'modulename' => 'assign',
                'resourceid' => 1,
                'uploaded' => 0,
                'timemodified' => time(),
            ];
            $DB->insert_record('tiny_authory_tech_files', $record);
        }

        $this->assertSame(2, $DB->count_records('tiny_authory_tech_files', ['cmid' => $this->cm->id]));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(
            0,
            $DB->count_records('tiny_authory_tech_files', ['cmid' => $this->cm->id]),
            'All records for the context should be deleted'
        );
    }

    public function test_delete_data_for_user_removes_only_that_user(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        foreach ([$user1->id, $user2->id] as $uid) {
            $record = (object)[
                'userid' => $uid,
                'cmid' => $this->cm->id,
                'courseid' => $this->course->id,
                'filename' => 'test.json',
                'modulename' => 'assign',
                'resourceid' => 1,
                'uploaded' => 0,
                'timemodified' => time(),
            ];
            $DB->insert_record('tiny_authory_tech_files', $record);
        }

        $contextlist = new approved_contextlist($user1, 'tiny_authory_tech', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(
            0,
            $DB->count_records('tiny_authory_tech_files', ['userid' => $user1->id]),
            'User 1 records should be deleted'
        );
        $this->assertSame(
            1,
            $DB->count_records('tiny_authory_tech_files', ['userid' => $user2->id]),
            'User 2 records must not be deleted'
        );
    }

    // Userlist.

    public function test_get_users_in_context_returns_users_with_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        $record = (object)[
            'userid' => $user->id,
            'cmid' => $this->cm->id,
            'courseid' => $this->course->id,
            'filename' => 'test.json',
            'modulename' => 'assign',
            'resourceid' => 1,
            'uploaded' => 0,
            'timemodified' => time(),
        ];
        $DB->insert_record('tiny_authory_tech_files', $record);

        $userlist = new userlist($context, 'tiny_authory_tech');
        provider::get_users_in_context($userlist);

        $this->assertContainsEquals(
            $user->id,
            $userlist->get_userids(),
            'User with data in context should appear in the userlist'
        );
    }

    public function test_delete_data_for_users_removes_listed_users_only(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($this->cm->id);

        foreach ([$user1->id, $user2->id] as $uid) {
            $record = (object)[
                'userid' => $uid,
                'cmid' => $this->cm->id,
                'courseid' => $this->course->id,
                'filename' => 'test.json',
                'modulename' => 'assign',
                'resourceid' => 1,
                'uploaded' => 0,
                'timemodified' => time(),
            ];
            $DB->insert_record('tiny_authory_tech_files', $record);
        }

        $userlist = new approved_userlist($context, 'tiny_authory_tech', [$user1->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(
            0,
            $DB->count_records('tiny_authory_tech_files', ['userid' => $user1->id]),
            'User 1 data should be deleted'
        );
        $this->assertSame(
            1,
            $DB->count_records('tiny_authory_tech_files', ['userid' => $user2->id]),
            'User 2 data should remain'
        );
    }
}
