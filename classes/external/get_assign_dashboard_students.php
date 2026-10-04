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
 * External function authory_tech_get_assign_dashboard_students: get students with submission data for an assignment dashboard.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_dashboard_students extends external_api {
    /**
     * Returns parameters for get_assign_dashboard_students
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Get all students who have submission data for a given assignment cmid.
     *
     * @param int $cmid Course module ID
     * @return string JSON encoded array of students
     * @throws \coding_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($cmid) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid]
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

        $sql = "SELECT u.id AS userid, u.firstname, u.lastname, f.id AS file_id
                  FROM {tiny_authory_tech_files} f
                  JOIN {user} u ON f.userid = u.id
                 WHERE f.cmid = :cmid
                   AND f.modulename = 'assign'
                   AND f.id = (
                       SELECT MAX(f2.id)
                         FROM {tiny_authory_tech_files} f2
                        WHERE f2.userid = f.userid
                          AND f2.cmid = f.cmid
                          AND f2.modulename = f.modulename
                   )
              ORDER BY u.lastname, u.firstname";

        $rows = $DB->get_records_sql($sql, ['cmid' => $params['cmid']]);

        $students = array_values(array_map(function ($row) {
            return [
                'userid'    => (int) $row->userid,
                'firstname' => $row->firstname,
                'lastname'  => $row->lastname,
                'fullname'  => $row->firstname . ' ' . $row->lastname,
                'file_id'   => (int) $row->file_id,
            ];
        }, $rows));

        return json_encode($students);
    }

    /**
     * Returns description of get_assign_dashboard_students() result value
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'JSON array of students');
    }
}
