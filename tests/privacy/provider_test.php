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
 * Every path must cover the four tables that hold personal data: files, comments, writing
 * statistics and writing differences.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string[] The tables holding personal data. */
    private const TABLES = [
        'tiny_authory_tech_files',
        'tiny_authory_tech_comments',
        'tiny_authory_tech_user_writing',
        'tiny_authory_tech_writing_diff',
    ];

    /** @var \stdClass Course used across tests. */
    private \stdClass $course;

    /** @var \stdClass Course module used across tests. */
    private \stdClass $cm;

    /** @var \stdClass A second course module in the same course. */
    private \stdClass $othercm;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id, $this->course->id);
        $other = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->othercm = get_coursemodule_from_instance('assign', $other->id, $this->course->id);
    }

    /**
     * Store a writing session for a user: a file with its statistics and differences, and a comment.
     *
     * @param int $userid The user.
     * @param \stdClass $cm The course module.
     * @return int The file id.
     */
    private function create_session(int $userid, \stdClass $cm): int {
        global $DB;

        $fileid = $DB->insert_record('tiny_authory_tech_files', (object) [
            'userid' => $userid,
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'filename' => "{$userid}_{$cm->id}_{$cm->id}_attempt.json",
            'modulename' => 'assign',
            'resourceid' => $cm->id,
            'content' => json_encode([['t' => 1, 'd' => [], 'meta' => 'keystroke']]),
            'original_content' => "Essay of {$userid}",
            'uploaded' => 0,
            'timemodified' => time(),
        ]);
        $DB->insert_record('tiny_authory_tech_user_writing', (object) [
            'file_id' => $fileid,
            'total_time_seconds' => 60,
            'key_count' => 100,
            'keys_per_minute' => 100,
            'word_count' => 20,
        ]);
        $DB->insert_record('tiny_authory_tech_writing_diff', (object) [
            'file_id' => $fileid,
            'reconstructed_text' => "Essay of {$userid}",
            'submitted_text' => base64_encode(json_encode("<p>Essay of {$userid}</p>")),
            'meta' => '0.5',
        ]);
        $this->create_comment($userid, $cm);
        return $fileid;
    }

    /**
     * Store a comment for a user.
     *
     * @param int $userid The user.
     * @param \stdClass $cm The course module.
     */
    private function create_comment(int $userid, \stdClass $cm): void {
        global $DB;

        $DB->insert_record('tiny_authory_tech_comments', (object) [
            'userid' => $userid,
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'modulename' => 'assign',
            'resourceid' => $cm->id,
            'questionid' => 0,
            'usercomment' => "Comment of {$userid}",
            'timemodified' => time(),
        ]);
    }

    /**
     * Count the rows of a user in a course module, in each of the four tables.
     *
     * @param int $userid The user.
     * @param \stdClass $cm The course module.
     * @return int[] Row count per table.
     */
    private function count_rows(int $userid, \stdClass $cm): array {
        global $DB;

        $counts = [];
        $params = ['userid' => $userid, 'cmid' => $cm->id];
        foreach (['tiny_authory_tech_files', 'tiny_authory_tech_comments'] as $table) {
            $counts[$table] = $DB->count_records($table, $params);
        }
        foreach (['tiny_authory_tech_user_writing', 'tiny_authory_tech_writing_diff'] as $table) {
            $counts[$table] = $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {{$table}} t
                   JOIN {tiny_authory_tech_files} f ON f.id = t.file_id
                  WHERE f.userid = :userid AND f.cmid = :cmid",
                $params
            );
        }
        return $counts;
    }

    public function test_get_metadata_declares_every_table(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tiny_authory_tech'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        foreach (self::TABLES as $table) {
            $this->assertContains($table, $names);
        }
        $this->assertContains('api.authory.tech', $names);
    }

    public function test_get_contexts_for_userid(): void {
        $withfile = $this->getDataGenerator()->create_user();
        $withcomment = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $this->create_session($withfile->id, $this->cm);
        $this->create_comment($withcomment->id, $this->othercm);

        $context = \context_module::instance($this->cm->id);
        $this->assertEquals([$context->id], provider::get_contexts_for_userid($withfile->id)->get_contextids());

        $othercontext = \context_module::instance($this->othercm->id);
        $this->assertEquals([$othercontext->id], provider::get_contexts_for_userid($withcomment->id)->get_contextids());

        $this->assertEmpty(provider::get_contexts_for_userid($nodata->id)->get_contextids());
    }

    public function test_get_users_in_context(): void {
        $withfile = $this->getDataGenerator()->create_user();
        $withcomment = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();
        $this->create_session($withfile->id, $this->cm);
        $this->create_comment($withcomment->id, $this->cm);
        $this->create_session($elsewhere->id, $this->othercm);

        $userlist = new userlist(\context_module::instance($this->cm->id), 'tiny_authory_tech');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        sort($userids);
        $this->assertEquals([$withfile->id, $withcomment->id], $userids);

        $courselist = new userlist(\context_course::instance($this->course->id), 'tiny_authory_tech');
        provider::get_users_in_context($courselist);
        $this->assertEmpty($courselist->get_userids());
    }

    public function test_export_user_data_covers_every_table(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $fileid = $this->create_session($user->id, $this->cm);
        $this->create_session($other->id, $this->cm);
        $context = \context_module::instance($this->cm->id);

        $this->export_context_data_for_user($user->id, $context, 'tiny_authory_tech');

        $writer = writer::with_context($context);
        $pluginname = get_string('pluginname', 'tiny_authory_tech');
        $session = [$pluginname, get_string('privacy:path:session', 'tiny_authory_tech', $fileid)];

        $data = $writer->get_data($session);
        $this->assertSame("Essay of {$user->id}", $data->submission);
        $this->assertEquals(20, $data->statistics->word_count);
        $this->assertSame("<p>Essay of {$user->id}</p>", $data->differences->submitted_text);
        $this->assertNotEmpty($writer->get_custom_file($session, 'keystrokes.json'));

        $comments = $writer->get_data([$pluginname, get_string('privacy:path:comments', 'tiny_authory_tech')]);
        $this->assertCount(1, $comments->comments);
        $this->assertSame("Comment of {$user->id}", $comments->comments[0]->comment);
    }

    public function test_delete_data_for_all_users_in_context(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->create_session($user1->id, $this->cm);
        $this->create_session($user2->id, $this->cm);
        $this->create_session($user1->id, $this->othercm);

        // A course context is not one of ours and must leave everything in place.
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user1->id, $this->cm));

        provider::delete_data_for_all_users_in_context(\context_module::instance($this->cm->id));

        $this->assertEquals(array_fill_keys(self::TABLES, 0), $this->count_rows($user1->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 0), $this->count_rows($user2->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user1->id, $this->othercm));
    }

    public function test_delete_data_for_user(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->create_session($user1->id, $this->cm);
        $this->create_session($user2->id, $this->cm);
        $this->create_session($user1->id, $this->othercm);

        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_user(new approved_contextlist($user1, 'tiny_authory_tech', [$context->id]));

        $this->assertEquals(array_fill_keys(self::TABLES, 0), $this->count_rows($user1->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user2->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user1->id, $this->othercm));
    }

    public function test_delete_data_for_users(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->create_session($user1->id, $this->cm);
        $this->create_session($user2->id, $this->cm);
        $this->create_session($user1->id, $this->othercm);

        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_users(new approved_userlist($context, 'tiny_authory_tech', [$user1->id]));

        $this->assertEquals(array_fill_keys(self::TABLES, 0), $this->count_rows($user1->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user2->id, $this->cm));
        $this->assertEquals(array_fill_keys(self::TABLES, 1), $this->count_rows($user1->id, $this->othercm));
    }
}
