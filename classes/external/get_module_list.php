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
use tiny_authory_tech\tiny_authory_tech_data;

/**
 * External function authory_tech_get_module_list: get quiz list by course.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_module_list extends external_api {
    /**
     * get_module_list_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'page' => new external_value(PARAM_INT, 'pagenumber', VALUE_DEFAULT, null),
                'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, null),
            ],
        );
    }

    /**
     * Get list of modules in a course
     *
     * @param int|null $page Page number
     * @param int|null $courseid ID of the course
     * @return false|string JSON encoded list of modules or false on failure
     * @throws \coding_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($page, $courseid) {

        global $CFG, $DB;
        require_once($CFG->libdir . '/accesslib.php');

        // Validate parameters.
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'page' => $page,
                'courseid' => $courseid,
            ],
        );

        // Get course context.
        $cm = $DB->get_record('course_modules', ['course' => $params['courseid']], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);
        // Get the list of modules in the course.
        $modules = tiny_authory_tech_data::get_courses_modules($params);

        // Return the module list as JSON.
        return json_encode($modules);
    }



     /**
      * Returns description of get_module_list() result value
      *
      * @return external_value The return value definition for module list response
      */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'All quizzes');
    }
}
