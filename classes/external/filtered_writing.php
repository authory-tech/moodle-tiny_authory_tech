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
 * External function authory_tech_filtered_writing: generate Reports for download.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filtered_writing extends external_api {
    /**
     * Returns parameters for filtering writing data
     *
     * @return external_function_parameters Parameters definition for filtering writing data
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_TEXT, 'id', VALUE_REQUIRED, 0),
            ],
        );
    }

    /**
     * Get filtered writing data for a course
     *
     * @param int $id Course ID
     * @return string|false JSON encoded data containing filtered writing statistics
     * @throws \coding_exception If parameters are invalid
     * @throws \dml_exception If database query fails
     * @throws \invalid_parameter_exception If parameters validation fails
     * @throws \moodle_exception If context validation fails
     */
    public static function execute($id) {
        global $DB, $USER;

        $vparams = self::validate_parameters(
            self::execute_parameters(),
            [
                'id' => $id,
            ],
        );

        $userid = $USER->id;
        $params = [];

        $cm = $DB->get_record('course_modules', ['course' => $vparams['id']], '*', IGNORE_MULTIPLE);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

        $attempts = "SELECT qa.resourceid AS attemptid,qa.timemodified,uw.score,uw.copy_behavior, u.id AS userid,
                            u.firstname, u.lastname, u.email,  qa.cmid AS cmid ,qa.courseid,qa.filename,uw.word_count,
                            uw.words_per_minute , uw.total_time_seconds ,uw.backspace_percent
                       FROM {user} u
                       JOIN {tiny_authory_tech_files} qa ON u.id = qa.userid
                  LEFT JOIN {tiny_authory_tech_user_writing} uw ON qa.id = uw.file_id
                      WHERE qa.userid! = 1";

        if ($userid != 0) {
            $attempts .= " AND  qa.userid = :userid";
            $params['userid'] = $userid;
        }
        if ($vparams['id'] != 0) {
            $attempts .= "  AND qa.courseid = :id";
            $params['id'] = $vparams['id'];
        }
        $res = $DB->get_records_sql($attempts, $params);
        $recs = [];
        foreach ($res as $key => $value) {
            $value->timemodified = date("l jS \of F Y h:i:s A", $value->timemodified);
            $value->icon = 'fa fa-circle-o';
            $value->color = 'grey';
            array_push($recs, $value);
        }
        $resncount = ['count' => count($res), 'data' => $recs];
        return json_encode($resncount);
    }

    /**
     * Returns description of method result value for authory_tech_filtered_writing_func
     *
     * @return external_value The return value definition for filtered writing data
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Comment Link');
    }
}
