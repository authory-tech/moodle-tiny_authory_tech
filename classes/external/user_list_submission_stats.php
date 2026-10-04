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
 * External function authory_tech_user_list_submission_stats: get user submissions status.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_list_submission_stats extends external_api {
    /**
     * Returns parameters for getting user list submission statistics
     *
     * @return external_function_parameters Parameters definition for submission stats
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, null),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, null),
                'filename' => new external_value(PARAM_TEXT, 'filename', VALUE_DEFAULT, ''),
            ],
        );
    }

    /**
     * Get user list submission statistics
     *
     * @param int $id The user ID
     * @param string $modulename The module name
     * @param int $cmid The course module ID
     * @return false|string JSON encoded submission statistics
     * @throws \coding_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($id, $modulename, $cmid) {
        global $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/locallib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => $id,
                'modulename' => $modulename,
                'cmid' => $cmid,
            ],
        );
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:view", $context);

        $rec = tiny_authory_tech_get_user_submissions_data($params['id'], $params['modulename'], $params['cmid']);

        return json_encode($rec);
    }

    /**
     * Returns description of get_user_list_submission_stats() result value
     *
     * @return external_value The return value definition for submission stats response
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
