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
use dml_exception;
use stdClass;

/**
 * External function authory_tech_resubmit_payload_data: resubmit payload data.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resubmit_payload_data extends external_api {
    /**
     * Returns the parameters for the resubmit_payload_data function
     *
     * @return external_function_parameters The parameters structure containing:
     *         - file_id (int): Optional file ID parameter
     *         - cmid (int): Optional course module ID parameter
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'file_id' => new external_value(PARAM_INT, 'FILE ID', VALUE_DEFAULT, 0),
                'cmid' => new external_value(PARAM_INT, 'CMID ID', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Resubmits payload data for a file
     *
     * @param int $fileid The ID of the file to resubmit
     * @param int $cmid The course module ID
     * @return bool True if resubmission was successful, false otherwise
     */
    public static function execute($fileid, $cmid) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'file_id' => $fileid,
                'cmid' => $cmid,
            ]
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:write", $context);

        try {
            $record = new stdClass();
            $record->id = $params['file_id'];
            $record->uploaded = 0;
            return $DB->update_record('tiny_authory_tech_files', $record);
        } catch (dml_exception $e) {
            return false;
        }
    }

    /**
     * Returns description of resubmit_payload_data return value
     *
     * @return external_value Returns a boolean parameter indicating if resubmission was successful
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'resubmit message');
    }
}
