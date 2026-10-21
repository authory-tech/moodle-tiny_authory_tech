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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use invalid_parameter_exception;
use tiny_authory_tech\constants;
use tiny_authory_tech\helper;

/**
 * External function authory_tech_get_comment_link:  Comments Links.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_comment_link extends external_api {
    /**
     * Returns the parameters definition for get_comment_link function
     *
     * @return external_function_parameters Parameters definition for get_comment_link
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, null),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, null),
                'questionid' => new external_value(PARAM_INT, 'questionid', VALUE_DEFAULT, null),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, null),
            ],
        );
    }

    /**
     * Retrieves comment links and associated data for a quiz attempt
     *
     * @param int $id The quiz attempt ID
     * @param string $modulename The name of the module, which must be 'quiz'
     * @param int $cmid The course module ID
     * @param int $questionid The question ID
     * @param int $userid The user ID
     * @return string JSON encoded array containing comment data and user writing metrics
     * @throws \coding_exception If parameters are invalid
     * @throws \dml_exception If there is a database error
     * @throws \invalid_parameter_exception If parameters fail validation
     * @throws \moodle_exception If capability check fails
     */
    public static function execute($id, $modulename, $cmid, $questionid, $userid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/lib/accesslib.php');
        require_once($CFG->dirroot . '/question/lib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => $id,
                'modulename' => $modulename,
                'cmid' => $cmid,
                'questionid' => $questionid,
                'userid' => $userid,
            ],
        );

        // Only the quiz review page calls this function; the other modules have their own
        // functions (get_forum_comment_link, get_assign_grade_comment) that scope the lookup
        // to the checked course module.
        if ($params['modulename'] !== 'quiz') {
            throw new invalid_parameter_exception('Only the quiz module is supported');
        }

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:view", $context);

        $data['filename'] = '';
        $conditions = [
            "resourceid" => $params['id'],
            "cmid" => $params['cmid'],
            "questionid" => $params['questionid'],
            'userid' => $params['userid'],
        ];
        $table = 'tiny_authory_tech_comments';
        $recs = $DB->get_records($table, $conditions);
        $sql = 'SELECT filename, content, userid, id AS file_id, uploaded
                  FROM {tiny_authory_tech_files}
                 WHERE resourceid = :resourceid AND cmid = :cmid
                       AND modulename = :modulename AND questionid=:questionid AND userid = :userid ';
        $filename = $DB->get_record_sql(
            $sql,
            [
                'resourceid' => $params['id'],
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'],
                'questionid' => $params['questionid'],
                "userid" => $params['userid'],
            ],
        );
        if ($filename) {
            $data['filename'] = $filename->filename;
            $data['questionid'] = $params['questionid'];

            if ($data['filename']) {
                $firstfileid = helper::get_user_first_file_id((int)$filename->userid);
                $data['first_file'] = ($firstfileid !== null && $firstfileid == $filename->file_id) ? 1 : 0;
            }

            if ($filename->file_id) {
                $sql = 'SELECT uwr.*, diff.meta as effort_ratio
                        FROM {tiny_authory_tech_user_writing} uwr
                    LEFT JOIN {tiny_authory_tech_writing_diff} diff ON uwr.file_id = diff.file_id
                        WHERE uwr.file_id = :fileid';
                $report = $DB->get_record_sql($sql, ['fileid' => $filename->file_id]);
                if (isset($report->effort_ratio)) {
                    $report->effort_ratio = intval(floatval($report->effort_ratio) * 100);
                }
                if ($report) {
                    $data['score'] = $report->score;
                    $data['total_time_seconds'] = $report->total_time_seconds;
                    $data['word_count'] = $report->word_count;
                    $data['words_per_minute'] = $report->words_per_minute;
                    $data['backspace_percent'] = $report->backspace_percent;
                    $data['copy_behavior'] = $report->copy_behavior;
                    $data['key_count'] = $report->key_count;
                    $data['file_id'] = $filename->file_id;
                    $data['character_count'] = $report->character_count;
                    $data['characters_per_minute'] = $report->characters_per_minute;
                    $data['keys_per_minute'] = $report->keys_per_minute;
                    $data['effort_ratio'] = $report->effort_ratio ?? 0;
                    $data['uploaded'] = $filename->uploaded;
                }
            }
        }
        $usercomment = [];
        if ($recs) {
            foreach ($recs as $key => $rec) {
                array_push($usercomment, $rec);
            }
            $data['resubmit'] = constants::is_resubmitable($data, $filename->file_id ?? null);
            $data['file_id'] = $filename->file_id ?? null;
            $data['cmid'] = $params['cmid'];
            return json_encode(['usercomment' => $usercomment, 'data' => $data]);
        } else {
            return json_encode(['usercomment' => 'comments', 'data' => $data]);
        }
    }


    /**
     * get_comment_link_returns
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
