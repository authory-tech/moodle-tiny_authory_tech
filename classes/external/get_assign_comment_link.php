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

/**
 * External function authory_tech_get_assign_comment_link:  Comments Links.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_comment_link extends external_api {
    /**
     * Returns the parameters definition for get_assign_comment_link function
     *
     * @return external_function_parameters Parameters definition for assignment comment link
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
     * Get assignment comment link
     *
     * @param int $id The assignment submission ID
     * @param string $modulename The module name
     * @param int $cmid The course module ID
     * @return false|string JSON encoded comment data
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

        $recassignsubmission = $DB->get_record('assign_submission', ['id' => $params['id']], '*', false);
        $userid = $recassignsubmission->userid;
        $conditions = ["userid" => $userid, 'modulename' => $params['modulename'], 'cmid' => $params['cmid']];
        $recs = $DB->get_records('tiny_authory_tech_comments', $conditions);
        $usercomment = [];
        if ($recs) {
            foreach ($recs as $rec) {
                array_push($usercomment, $rec);
            }
            return json_encode($usercomment);
        } else {
            return json_encode([['usercomment' => 'comments']]);
        }
    }

    /**
     * Returns description of get_assign_comment_link() result value
     *
     * @return external_value The return value definition for assignment comment link response
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
