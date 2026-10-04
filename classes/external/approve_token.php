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
 * External function authory_tech_approve_token: generate Reports for download.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_token extends external_api {
    /**
     * authory_tech_approve_token_func_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'token' => new external_value(PARAM_TEXT, 'usertoken', VALUE_DEFAULT, ''),
            ],
        );
    }

    /**
     * Verifies and approves a token by sending it to a remote server for validation
     *
     * @param string $token The token to verify and approve
     * @return bool|string Returns the server response if successful, false on failure
     * @throws \coding_exception If parameters are invalid
     * @throws \dml_exception If there is a database error
     * @throws \moodle_exception If token verification fails or there are other errors
     */
    public static function execute($token) {
        global $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'token' => $token,
            ],
        );
        // Check if the user has the required capability.
        $context = context_system::instance(); // Assuming a system-wide capability check.
        self::validate_context($context);
        require_capability('tiny/authory_tech:editsettings', $context);

        $result = tiny_authory_tech_approve_token();

        return $result;
    }


    /**
     * Returns the expected return value for the token approval function
     *
     * @return external_value The return value definition for token approval response
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Token Approved');
    }
}
