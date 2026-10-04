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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use moodle_exception;

/**
 * External function authory_tech_disable_all_course: disable authory_tech for all courses.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class disable_all_course extends external_api {
    /**
     * Returns the parameters for the disable_authory_tech function
     *
     * @return external_function_parameters The parameters structure containing:
     *         - disable (bool): Optional boolean parameter to disable authory_tech
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'disable' => new external_value(PARAM_BOOL, 'status', VALUE_DEFAULT, null),
            ]
        );
    }
    /**
     * Disables authory_tech functionality for all courses
     *
     * @param bool $disable Whether to disable authory_tech
     * @return bool True if authory_tech was successfully disabled for all courses
     */
    public static function execute($disable) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['disable' => $disable],
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tiny/authory_tech:editsettings', $context);

        try {
            $courses = get_courses();
            $value = !$params['disable'];
            foreach ($courses as $course) {
                set_config("authory_tech-{$course->id}", $value, 'tiny_authory_tech');
            }
            return true;
        } catch (moodle_exception $e) {
            // Log error and return false if config update fails.
            debugging('Error disabling authory_tech: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    /**
     * Returns description of disable_authory_tech return value
     *
     * @return external_value Returns a boolean parameter indicating if authory_tech was disabled
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'authory_tech disable message');
    }
}
