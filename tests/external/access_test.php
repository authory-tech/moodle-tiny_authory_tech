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

use tiny_authory_tech\helper;

/**
 * Tests that users can only read and change their own writing data, unless they are a teacher in its course module.
 *
 * Students hold tiny/authory_tech:writingreport and :write, so each test signs in as a second student
 * of the same course and tries to reach the first student's data by passing its identifiers.
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tiny_authory_tech\helper
 * @covers \tiny_authory_tech\external\get_writing_differences
 * @covers \tiny_authory_tech\external\get_reply_json
 * @covers \tiny_authory_tech\external\get_replay_annotations
 * @covers \tiny_authory_tech\external\get_writing_statistics
 * @covers \tiny_authory_tech\external\get_autosave_content
 * @covers \tiny_authory_tech\external\get_student_stats
 * @covers \tiny_authory_tech\external\get_comment_link
 * @covers \tiny_authory_tech\external\get_forum_comment_link
 * @covers \tiny_authory_tech\external\resubmit_payload_data
 * @covers \tiny_authory_tech\external\update_pdf_annote_id
 * @covers \tiny_authory_tech\external\write_local_to_json
 * @covers \tiny_authory_tech\external\user_comments
 * @covers \tiny_authory_tech\external\get_user_list
 * @covers \tiny_authory_tech\external\get_module_list
 */
final class access_test extends \advanced_testcase {
    /** @var \stdClass Course with the first student's data. */
    private \stdClass $course;

    /** @var \stdClass Assignment course module in $course. */
    private \stdClass $cm;

    /** @var \stdClass Student who owns the data. */
    private \stdClass $owner;

    /** @var \stdClass Another student in the same course. */
    private \stdClass $otherstudent;

    /** @var \stdClass Editing teacher in the same course. */
    private \stdClass $teacher;

    /** @var \stdClass Keystroke file of $owner in $cm. */
    private \stdClass $file;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id, $this->course->id);

        $this->owner = $generator->create_and_enrol($this->course, 'student');
        $this->otherstudent = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');

        $this->file = $this->create_file($this->owner->id, $this->cm, 'assign', $this->cm->id);
        $DB->insert_record('tiny_authory_tech_user_writing', (object) [
            'file_id' => $this->file->id,
            'total_time_seconds' => 120,
            'key_count' => 300,
            'keys_per_minute' => 150,
            'character_count' => 280,
            'characters_per_minute' => 140,
            'word_count' => 50,
            'words_per_minute' => 25,
            'backspace_percent' => 5,
            'score' => 0.9,
            'copy_behavior' => 0,
        ]);
        $DB->insert_record('tiny_authory_tech_writing_diff', (object) [
            'file_id' => $this->file->id,
            'reconstructed_text' => 'Owner essay',
            'submitted_text' => base64_encode(json_encode(
                '<p>Owner <span class="tiny_authory_tech_added">essay</span><img src="x" onerror="alert(1)"></p>'
            )),
            'meta' => '0.5',
        ]);
        $DB->insert_record('tiny_authory_tech_comments', (object) [
            'userid' => $this->owner->id,
            'cmid' => $this->cm->id,
            'courseid' => $this->course->id,
            'modulename' => 'assign',
            'resourceid' => $this->cm->id,
            'questionid' => 0,
            'usercomment' => 'Owner paste source',
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a keystroke file record.
     *
     * @param int $userid Owner of the file.
     * @param \stdClass $cm Course module the file belongs to.
     * @param string $modulename Module name stored with the file.
     * @param int $resourceid Resource id stored with the file.
     * @return \stdClass The inserted record.
     */
    private function create_file(int $userid, \stdClass $cm, string $modulename, int $resourceid): \stdClass {
        global $DB;

        $record = (object) [
            'userid' => $userid,
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'modulename' => $modulename,
            'resourceid' => $resourceid,
            'filename' => "{$userid}_{$resourceid}_{$cm->id}_attempt.json",
            'content' => json_encode([['t' => 1, 'd' => [], 'meta' => 'keystroke']]),
            'original_content' => 'Owner essay',
            'questionid' => 0,
            'uploaded' => 1,
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('tiny_authory_tech_files', $record);
        return $record;
    }

    public function test_get_writing_differences_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_writing_differences::execute($this->file->id);
    }

    public function test_get_writing_differences_returns_cleaned_text_to_teacher(): void {
        $this->setUser($this->teacher);
        $result = get_writing_differences::execute($this->file->id);
        $data = json_decode($result['data']);

        $html = json_decode(base64_decode($data->submitted_text));
        $this->assertStringContainsString('tiny_authory_tech_added', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertCount(1, (array) $data->comments);
    }

    public function test_get_reply_json_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_reply_json::execute($this->file->filename);
    }

    public function test_get_reply_json_allows_owner_and_teacher(): void {
        foreach ([$this->owner, $this->teacher] as $user) {
            $this->setUser($user);
            $result = get_reply_json::execute($this->file->filename);
            $this->assertTrue($result->status);
            $this->assertSame($this->file->content, $result->data);
            $this->assertSame(json_encode(['Owner paste source']), $result->comments);
        }
    }

    public function test_get_reply_json_unknown_file_name(): void {
        $this->setUser($this->owner);
        $this->expectException(\moodle_exception::class);
        get_reply_json::execute("{$this->owner->id}_999_{$this->cm->id}_attempt.json");
    }

    public function test_get_replay_annotations_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_replay_annotations::execute($this->file->filename);
    }

    public function test_get_writing_statistics_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_writing_statistics::execute($this->cm->id, $this->file->id);
    }

    public function test_get_writing_statistics_takes_module_from_record(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $othercm = $this->getDataGenerator()->create_module('assign', ['course' => $othercourse->id]);
        $this->getDataGenerator()->enrol_user($this->otherstudent->id, $othercourse->id, 'editingteacher');

        // Teacher rights in another module do not open this file.
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_writing_statistics::execute($othercm->cmid, $this->file->id);
    }

    public function test_get_writing_statistics_allows_owner(): void {
        $this->setUser($this->owner);
        $result = get_writing_statistics::execute($this->cm->id, $this->file->id);
        $data = json_decode($result['data']);
        $this->assertEquals(50, $data->word_count);
        $this->assertEquals($this->cm->id, $data->cmid);
    }

    public function test_get_autosave_content_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_autosave_content::execute($this->cm->id, 'assign', $this->cm->id, '', $this->owner->id, $this->course->id);
    }

    public function test_get_autosave_content_allows_own(): void {
        $this->setUser($this->owner);
        $result = get_autosave_content::execute($this->cm->id, 'assign', $this->cm->id, '', 0, $this->course->id);
        $this->assertCount(1, json_decode($result));
    }

    public function test_get_student_stats_rejects_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_student_stats::execute($this->file->id, $this->owner->id);
    }

    public function test_get_student_stats_rejects_teacher_of_other_course(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $otherteacher = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');

        $this->setUser($otherteacher);
        $this->expectException(\moodle_exception::class);
        get_student_stats::execute($this->file->id, $this->owner->id);
    }

    public function test_get_comment_link_rejects_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_comment_link::execute($this->cm->id, 'quiz', $this->cm->id, 0, $this->owner->id);
    }

    public function test_get_comment_link_only_supports_quiz(): void {
        $this->setUser($this->teacher);
        $this->expectException(\invalid_parameter_exception::class);
        get_comment_link::execute($this->cm->id, 'assign', $this->cm->id, 0, $this->owner->id);
    }

    public function test_get_forum_comment_link_ignores_comments_of_other_modules(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $forum = $generator->create_module('forum', ['course' => $this->course->id]);
        $postid = 4242;
        $DB->insert_record('tiny_authory_tech_comments', (object) [
            'userid' => $this->owner->id,
            'cmid' => $forum->cmid,
            'courseid' => $this->course->id,
            'modulename' => 'forum',
            'resourceid' => $postid,
            'questionid' => 0,
            'usercomment' => 'Forum paste source',
            'timemodified' => time(),
        ]);

        // A teacher of another course passes the post id with a forum they teach in.
        $othercourse = $generator->create_course();
        $otherforum = $generator->create_module('forum', ['course' => $othercourse->id]);
        $otherteacher = $generator->create_and_enrol($othercourse, 'editingteacher');
        $this->setUser($otherteacher);
        $result = json_decode(get_forum_comment_link::execute($postid, 'forum', $otherforum->cmid));
        $this->assertSame('comments', $result->usercomment);

        // The teacher of the forum the comment belongs to still sees it.
        $this->setUser($this->teacher);
        $result = json_decode(get_forum_comment_link::execute($postid, 'forum', $forum->cmid));
        $this->assertCount(1, $result->usercomment);
        $this->assertSame('Forum paste source', $result->usercomment[0]->usercomment);
    }

    public function test_resubmit_payload_data_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        resubmit_payload_data::execute($this->file->id, $this->cm->id);
    }

    public function test_resubmit_payload_data_rejects_other_module(): void {
        $otherassign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);

        $this->setUser($this->owner);
        $this->expectException(\invalid_parameter_exception::class);
        resubmit_payload_data::execute($this->file->id, $otherassign->cmid);
    }

    public function test_resubmit_payload_data_allows_owner_and_teacher(): void {
        global $DB;

        foreach ([$this->owner, $this->teacher] as $user) {
            $DB->set_field('tiny_authory_tech_files', 'uploaded', 1, ['id' => $this->file->id]);
            $this->setUser($user);
            $this->assertTrue(resubmit_payload_data::execute($this->file->id, $this->cm->id));
            $this->assertEquals(0, $DB->get_field('tiny_authory_tech_files', 'uploaded', ['id' => $this->file->id]));
        }
    }

    public function test_update_pdf_annote_id_only_updates_own_records(): void {
        global $DB;

        $ownerfile = $this->create_file($this->owner->id, $this->cm, 'pdfannotator', 0);
        $otherfile = $this->create_file($this->otherstudent->id, $this->cm, 'pdfannotator', 0);

        // The other student names the owner and another course; only their own record changes.
        $this->setUser($this->otherstudent);
        update_pdf_annote_id::execute($this->cm->id, $this->owner->id, SITEID, 'pdfannotator', 77);

        $this->assertEquals(0, $DB->get_field('tiny_authory_tech_files', 'resourceid', ['id' => $ownerfile->id]));
        $this->assertEquals(77, $DB->get_field('tiny_authory_tech_files', 'resourceid', ['id' => $otherfile->id]));
    }

    public function test_write_local_to_json_requires_course_module(): void {
        $this->setUser($this->owner);
        $this->expectException(\moodle_exception::class);
        write_local_to_json::execute(0, 0, 'assign', '', '[]', 'Text');
    }

    public function test_user_comments_takes_course_from_module(): void {
        global $DB;

        $this->setUser($this->otherstudent);
        $this->assertTrue(user_comments::execute('assign', $this->cm->id, $this->cm->id, SITEID, 'Mine', time(), ''));
        $comment = $DB->get_record('tiny_authory_tech_comments', ['userid' => $this->otherstudent->id], '*', MUST_EXIST);
        $this->assertEquals($this->course->id, $comment->courseid);
    }

    public function test_course_lists_check_course_context(): void {
        // The course has two activities; the lists used to pick one of them and fail.
        $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);

        $this->setUser($this->teacher);
        $users = json_decode(get_user_list::execute(0, $this->course->id));
        $this->assertContainsEquals($this->owner->id, array_column($users->userlist, 'id'));
        $modules = json_decode(get_module_list::execute(0, $this->course->id));
        $this->assertContainsEquals($this->cm->id, array_column($modules->userlist, 'id'));

        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        get_user_list::execute(0, $this->course->id);
    }

    public function test_file_page_access_rejects_other_student(): void {
        $this->setUser($this->otherstudent);
        $this->expectException(\required_capability_exception::class);
        helper::require_file_page_access($this->file->id);
    }

    public function test_file_page_access_rejects_teacher_of_other_course(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $otherteacher = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');

        $this->setUser($otherteacher);
        $this->expectException(\moodle_exception::class);
        helper::require_file_page_access($this->file->id);
    }

    public function test_file_page_access_allows_owner_and_teacher(): void {
        foreach ([$this->owner, $this->teacher] as $user) {
            $this->setUser($user);
            $this->assertEquals($this->file->id, helper::require_file_page_access($this->file->id)->id);
        }
    }
}
