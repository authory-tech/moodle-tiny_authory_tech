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
 * External function authory_tech_get_forum_comment_link: get forum comments links.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_forum_comment_link extends external_api {
    /**
     * get_forum_comment_link_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, null),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
            ],
        );
    }


    /**
     * Get forum comment link data
     *
     * @param int $id The resource ID
     * @param string $modulename The name of the module
     * @param int|null $cmid The course module ID
     * @return string JSON encoded comment and data
     * @throws \coding_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($id, $modulename, $cmid = null) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/lib/accesslib.php');
        require_once($CFG->dirroot . '/question/lib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => (int) $id,
                'modulename' => $modulename,
                'cmid' => (int) $cmid,
            ],
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

        $conditions = ["resourceid" => $params['id'], 'modulename' => "forum"];
        $recs = $DB->get_records('tiny_authory_tech_comments', $conditions);

        $attempts = "SELECT uw.total_time_seconds, uw.word_count, uw.words_per_minute, uf.uploaded,
                            uw.backspace_percent, uw.score, uw.copy_behavior, uf.resourceid,
                            uf.modulename, uf.userid, uf.filename, uw.file_id,
                            diff.meta AS effort_ratio
                      FROM {tiny_authory_tech_user_writing} uw
                      JOIN {tiny_authory_tech_files} uf ON uw.file_id = uf.id
                 LEFT JOIN {tiny_authory_tech_writing_diff} diff ON uw.file_id = diff.file_id
                     WHERE uf.resourceid = :id
                           AND uf.cmid = :cmid
                           AND uf.modulename = :modulename";

        $data =
            $DB->get_record_sql(
                $attempts,
                ['id' => $params['id'], 'cmid' => $params['cmid'], 'modulename' => $params['modulename']],
            );
        if (isset($data->effort_ratio)) {
            $data->effort_ratio = intval(floatval($data->effort_ratio) * 100);
        }
        $data = (array) $data;
        $data['first_file'] = 0;

        if (!isset($data['filename'])) {
            $sql = 'SELECT id as file_id, filename,userid, content
                      FROM {tiny_authory_tech_files}
                     WHERE resourceid = :resourceid
                            AND cmid = :cmid
                            AND modulename = :modulename';
            $filename = $DB->get_record_sql(
                $sql,
                ['resourceid' => $params['id'], 'cmid' => $params['cmid'], 'modulename' => $params['modulename']],
            );

            $data['filename'] = $filename->filename;
            $data['file_id'] = $filename->file_id;
            $data['resubmit'] = constants::is_resubmitable($data, $filename->file_id);
            $data['cmid'] = $params['cmid'];

            $firstfileid = helper::get_user_first_file_id((int)$filename->userid);
            if ($firstfileid !== null && $firstfileid == $filename->file_id) {
                $data['first_file'] = 1;
            }
        }

        $firstfileid = helper::get_user_first_file_id((int)($data['userid'] ?? 0));
        if ($firstfileid !== null && isset($filename) && $firstfileid == $filename->file_id) {
            $data['first_file'] = 1;
        }

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
     * Returns description of get_forum_comment_link() result value
     *
     * @return external_value The return value definition for forum comment link response
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
