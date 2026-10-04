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
use tiny_authory_tech\constants;
use tiny_authory_tech\helper;

/**
 * External function authory_tech_get_assign_grade_comment: get assign grade comments.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_grade_comment extends external_api {
    /**
     * Returns the parameters definition for get_assign_grade_comment function
     *
     * @return external_function_parameters Parameters definition for assignment grade comment
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_INT, 'id', VALUE_REQUIRED),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_REQUIRED),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_REQUIRED),
            ],
        );
    }

    /**
     * Get assignment grade comment data
     *
     * @param int $id The user ID
     * @param string $modulename The module name
     * @param int $cmid The course module ID
     * @return false|string JSON encoded comment and data
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($id, $modulename, $cmid) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => $id,
                'modulename' => $modulename,
                'cmid' => $cmid,
            ],
        );

        // Check if user has capability to view assignment comments.
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

        $conditions = ["userid" => $params['id'], 'modulename' => $params['modulename'], 'cmid' => $params['cmid']];
        $table = 'tiny_authory_tech_comments';
        $recs = $DB->get_records($table, $conditions);

        $attempts = "SELECT uw.total_time_seconds, uw.word_count, uw.words_per_minute,
                            uw.backspace_percent, uw.score, uw.copy_behavior, uf.resourceid,
                            uf.modulename, uf.userid, uw.file_id, uf.filename, uf.uploaded,
                            diff.meta AS effort_ratio
                       FROM {tiny_authory_tech_user_writing} uw
                       JOIN {tiny_authory_tech_files} uf ON uw.file_id = uf.id
                  LEFT JOIN {tiny_authory_tech_writing_diff} diff ON uw.file_id = diff.file_id
                      WHERE uf.userid = :id
                            AND uf.cmid = :cmid
                            AND uf.modulename = :modulename";

        $data =
            $DB->get_record_sql(
                $attempts,
                [
                    'id' => $params['id'],
                    'cmid' => $params['cmid'],
                    'modulename' => $params['modulename'],
                ],
            );
        if (isset($data->effort_ratio)) {
            $data->effort_ratio = intval(floatval($data->effort_ratio) * 100);
        }
        $data = (array) $data;
        if (!isset($data['filename'])) {
            $sql = 'SELECT filename, content, id, userid
                      FROM {tiny_authory_tech_files}
                     WHERE userid = :userid
                            AND cmid = :cmid
                            AND modulename = :modulename';
            $filename = $DB->get_record_sql(
                $sql,
                ['userid' => $params['id'], 'cmid' => $params['cmid'], 'modulename' => $params['modulename']],
            );

            $data['filename'] = $filename->filename;
            $data['file_id'] = $filename->id;
            $data['userid'] = $filename->userid;
        }
        if ($data['filename']) {
            $firstfileid = helper::get_user_first_file_id((int)$data['userid']);
            $data['first_file'] = ($firstfileid !== null && $firstfileid == $data['file_id']) ? 1 : 0;
        }
        $data['resubmit'] = constants::is_resubmitable($data, $data['file_id'] ?? null);
        $data['file_id'] = $data['file_id'] ?? null;
        $data['cmid'] = $params['cmid'];
        $usercomment = [];
        if ($recs) {
            foreach ($recs as $key => $rec) {
                array_push($usercomment, $rec);
            }
            return json_encode(['usercomment' => $usercomment, 'data' => $data]);
        } else {
            return json_encode(['usercomment' => 'comments', 'data' => $data]);
        }
    }

    /**
     * Returns description of get_assign_grade_comment() result value
     *
     * @return external_value The return value definition for assignment grade comment response
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
