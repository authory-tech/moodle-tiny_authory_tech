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

namespace tiny_authory_tech;

/**
 * Class helper
 *
 * @package    tiny_authory_tech
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Check that the current user may read writing data that belongs to a user in a course module.
     *
     * The tiny/authory_tech:writingreport and :write capabilities are given to students, so on their
     * own they only prove that a user may see their own data. Reading another user's data needs the
     * teacher capability tiny/authory_tech:view in the module the data belongs to.
     *
     * @param int $ownerid The user the data belongs to, taken from the stored record.
     * @param \context $context The module context of the stored record, never one named in the request.
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_data_access(int $ownerid, \context $context): void {
        global $USER;

        if ($ownerid === (int) $USER->id) {
            require_capability('tiny/authory_tech:writingreport', $context);
        } else {
            require_capability('tiny/authory_tech:view', $context);
        }
    }

    /**
     * Load a keystroke file for a report page and check the current user may read it.
     *
     * Logs the user in to the course module of the file record (not one named in the request)
     * and then applies {@see self::require_data_access()}.
     *
     * @param int $fileid The tiny_authory_tech_files record id.
     * @return \stdClass The file record.
     * @throws \moodle_exception
     */
    public static function require_file_page_access(int $fileid): \stdClass {
        global $DB;

        $file = $DB->get_record('tiny_authory_tech_files', ['id' => $fileid], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_cmid($file->cmid);
        require_login($course, false, $cm);
        self::require_data_access((int) $file->userid, \context_module::instance($cm->id));

        return $file;
    }

    /**
     * Clean the submitted text that the type-server stores in tiny_authory_tech_writing_diff.
     *
     * The column holds base64-encoded JSON whose value is the student's submission as HTML, with
     * pasted or edited parts wrapped in spans. It is shown to teachers as HTML, so it goes through
     * Moodle's HTML cleaning like any other user-submitted text.
     *
     * @param string|null $encoded The submitted_text column value.
     * @param \context $context The module context the submission belongs to.
     * @return string The cleaned HTML, or an empty string if the value is not a JSON string.
     */
    public static function clean_submitted_text(?string $encoded, \context $context): string {
        $html = json_decode(base64_decode((string) $encoded));
        if (!is_string($html)) {
            return '';
        }
        return format_text($html, FORMAT_HTML, ['context' => $context, 'filter' => false]);
    }

    /**
     * Updates resource IDs for both comments and authory_tech files
     *
     * @param array $data Array containing userid, modulename, courseid, cmid and resourceid
     * @return void
     * @throws \dml_exception
     */
    public static function update_resource_id($data) {
        self::update_comment($data);
        self::update_authory_tech_files($data);
    }

    /**
     * Updates comments in the tiny_authory_tech_comments table.
     *
     * @param array $data Array containing userid, modulename, courseid, cmid and resourceid
     * @throws \dml_exception
     */
    public static function update_comment($data) {
        global $DB;

        $table      = 'tiny_authory_tech_comments';
        $conditions = [
            "userid"     => $data['userid'],
            "modulename" => $data['modulename'],
            'resourceid' => 0,
            'courseid'   => $data['courseid'],
            'cmid'       => $data['cmid'],
        ];

        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            self::update_records($recs, $table, $data['resourceid']);
        }
        // Update autosave content as well.
        $conditions['modulename'] = $data['modulename'] . "_autosave";
        self::update_autosaved_content($conditions, $table, $data['resourceid']);
    }

    /**
     * Updates authory_tech file in the tiny_authory_tech_files table.
     *
     * @param array $data Array containing userid, modulename, courseid, cmid and resourceid
     * @throws \dml_exception
     */
    public static function update_authory_tech_files($data) {
        global $DB;

        $table      = 'tiny_authory_tech_files';
        $conditions = [
            "userid"     => $data['userid'],
            "modulename" => $data['modulename'],
            'resourceid' => 0,
            'courseid'   => $data['courseid'],
            'cmid'       => $data['cmid'],
        ];
        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            $fname               = $data['userid'] . '_' . $data['resourceid'] . '_' . $data['cmid'] . '_attempt' . '.json';
            self::update_records($recs, $table, $data['resourceid'], $fname);
        }
    }

    /**
     * Update autosaved content records.
     *
     * @param array $conditions The conditions to find records to update
     * @param string $table The database table name
     * @param int $postid The post ID to update the records with
     * @return void
     * @throws \dml_exception
     */
    public static function update_autosaved_content($conditions, $table, $postid) {
        global $DB;
        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            self::update_records($recs, $table, $postid);
        }
    }

    /**
     * Updates records in the database with new resource ID and optionally a new filename
     *
     * @param array $recs Array of records to update
     * @param string $table Database table name
     * @param int $id New resource ID to set
     * @param string|null $name Optional new filename to set
     * @return void
     * @throws \dml_exception
     */
    private static function update_records($recs, $table, $id, $name = null) {
        global $DB;

        foreach ($recs as $rec) {
            $rec->resourceid = $id;
            if ($name) {
                $rec->filename = $name;
            }
            $DB->update_record($table, $rec, true);
        }
    }

    /**
     * Return the ID of the oldest file record for a user, with in-request caching.
     *
     * Avoids repeated identical queries across functions that all need to know
     * whether the current file is the user's first submission.
     *
     * @param int $userid
     * @return int|null File ID, or null if the user has no files.
     */
    public static function get_user_first_file_id(int $userid): ?int {
        global $DB;
        static $cache = [];

        if ($userid <= 0) {
            return null;
        }
        if (!array_key_exists($userid, $cache)) {
            $id = $DB->get_field_sql(
                'SELECT MIN(id) FROM {tiny_authory_tech_files} WHERE userid = :userid',
                ['userid' => $userid]
            );
            $cache[$userid] = $id ? (int) $id : null;
        }
        return $cache[$userid];
    }
}
