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

/**
 * External function authory_tech_get_autosave_content: resubmit payload data.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_autosave_content extends external_api {
    /**
     * Returns the parameters definition for get_autosave_content external function.
     *
     * @return external_function_parameters Parameters definition for getting autosave content
     */
    public static function execute_parameters() {
        return new external_function_parameters([
                'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, null),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, null),
                'editorid' => new external_value(PARAM_TEXT, 'editor id', VALUE_DEFAULT, null),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, null),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, null),
            ]);
    }

    /**
     * Gets autosaved content for a specific user and resource
     *
     * @param int $id The resource ID
     * @param string $modulename The name of the module (e.g. 'quiz', 'assign')
     * @param int $cmid The course module ID
     * @param string $editorid The editor ID
     * @param int $userid The user ID (defaults to current user)
     * @param int $courseid The course ID
     * @return string JSON encoded array containing usercomment and timemodified fields from tiny_authory_tech_comments records
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     * @throws \required_capability_exception
     */
    public static function execute($id, $modulename, $cmid, $editorid = "", $userid = 0, $courseid = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => $id,
                'modulename' => $modulename,
                'cmid' => $cmid,
                'editorid' => $editorid,
                'userid' => $userid,
                'courseid' => $courseid,
            ],
        );

        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        if (empty($params['questionid'])) {
            $params['questionid'] = 0;
        }

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:writingreport", $context);

        $questionid = constants::get_question_id($params['editorid']);
        if ($questionid) {
            $record = $DB->get_records('tiny_authory_tech_comments', [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'],
                'resourceid' => $params['id'],
                'userid' => $params['userid'],
                'questionid' => $questionid,
                'courseid' => $params['courseid'],
            ], 'id desc', 'usercomment, timemodified');
        } else {
            $record = $DB->get_records('tiny_authory_tech_comments', [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'],
                'resourceid' => $params['id'],
                'userid' => $params['userid'],
                'courseid' => $params['courseid'],
            ], 'id desc', 'usercomment, timemodified');
        }

        if ($record) {
            return json_encode(array_values($record));
        } else {
            return json_encode([]);
        }
    }

    /**
     * Returns description of get_autosave_content return value
     *
     * @return external_value Returns a text parameter containing the autosaved content
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'autosave content');
    }
}
