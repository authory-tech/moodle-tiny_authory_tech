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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function authory_tech_generate_webtoken: generate a webservice token.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_webtoken extends external_api {
    /**
     * Returns parameters for generate_webtoken method
     *
     * @return external_function_parameters Parameters definition for generating web token
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Generates a web token for authentication
     *
     * @return array Array containing the generated token
     */
    public static function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/locallib.php');

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('tiny/authory_tech:editsettings', $context);

        $token = tiny_authory_tech_create_token_for_user();
        if ($token) {
            set_config('authory_tech_token', $token, 'tiny_authory_tech');
            unset_config('ApiSyncInterval', 'tiny_authory_tech');
        }
        return ['token' => $token];
    }

    /**
     * Returns description of method result value for generate_webtoken
     *
     * @return external_single_structure Returns structure containing the generated token
     */
    public static function execute_returns() {
        return new external_single_structure([
            'token' => new external_value(PARAM_TEXT, 'token'),
        ]);
    }
}
