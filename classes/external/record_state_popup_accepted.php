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
 * External function authory_tech_record_state_popup_accepted: record that the user acknowledged the active-state popup.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_state_popup_accepted extends external_api {
    /**
     * Returns the parameters for the record_state_popup_accepted function
     *
     * @return external_function_parameters The parameters structure containing:
     *         - cmid (int): Course module ID the popup was shown in
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Records that the current user acknowledged the Authory.tech active-state popup.
     *
     * @param int $cmid Course module ID the popup was shown in
     * @return bool True once the event has been triggered
     */
    public static function execute($cmid) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid],
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:write', $context);

        \tiny_authory_tech\event\state_popup_accepted::create(['context' => $context])->trigger();

        return true;
    }

    /**
     * Returns description of method result value for record_state_popup_accepted
     *
     * @return external_value Returns a boolean parameter indicating the event was recorded
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'true once the acceptance event has been recorded');
    }
}
