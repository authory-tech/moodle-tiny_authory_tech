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

/**
 * External function authory_tech_test_connection: test connectivity to the ML server.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_connection extends external_api {
    /**
     * authory_tech_test_connection_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'url' => new external_value(PARAM_URL, 'API URL to test', VALUE_DEFAULT, ''),
            ],
        );
    }

    /**
     * Tests connectivity to the ML server using the provided URL.
     *
     * @param string $url The API URL to test
     * @return string JSON-encoded result with status and message
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public static function execute($url) {
        global $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['url' => $url],
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tiny/authory_tech:editsettings', $context);

        return tiny_authory_tech_test_connection($params['url']);
    }

    /**
     * Returns the return value definition for authory_tech_test_connection
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Connection test result');
    }
}
