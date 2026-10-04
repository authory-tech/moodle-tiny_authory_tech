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
use moodle_exception;
use stdClass;

/**
 * External function authory_tech_store_writing_differences: store writing difference data.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class store_writing_differences extends external_api {
    /**
     * Returns parameters for store_user_writing method
     *
     * @return external_function_parameters Parameters definition for storing user writing data
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'fileid' => new external_value(PARAM_INT, 'file id', VALUE_REQUIRED, 0, true),
            'reconstructed_text' => new external_value(PARAM_TEXT, 'original writing contents', VALUE_REQUIRED, "", true),
            'submitted_text' => new external_value(PARAM_TEXT, 'writing html contents', VALUE_REQUIRED, "", true),
            'meta' => new external_value(PARAM_TEXT, 'meta data', VALUE_DEFAULT, null, true),
        ]);
    }

    /**
     * Store writing differences between reconstructed and submitted text
     *
     * @param int $fileid File identifier
     * @param string $reconstructedtext Original reconstructed text
     * @param string $submittedtext Final submitted text
     * @param string|null $meta Optional metadata
     */
    public static function execute($fileid, $reconstructedtext, $submittedtext, $meta = null) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'fileid' => $fileid,
                'reconstructed_text' => $reconstructedtext,
                'submitted_text' => $submittedtext,
                'meta' => $meta,
            ],
        );

        $context = context_system::instance(); // Assuming a system-wide capability check.
        self::validate_context($context);
        require_capability('tiny/authory_tech:editsettings', $context);

        $recordexists = $DB->record_exists('tiny_authory_tech_writing_diff', ['file_id' => $params['fileid']]);
        $record = $recordexists
            ? $DB->get_record('tiny_authory_tech_writing_diff', ['file_id' => $params['fileid']])
            : new stdClass();
        $record->file_id = $params['fileid'];
        $record->reconstructed_text = $params['reconstructed_text'];
        $record->submitted_text = $params['submitted_text'];
        $record->meta = $params['meta']; // Add the meta field.

        try {
            if ($recordexists) {
                $DB->update_record('tiny_authory_tech_writing_diff', $record);
            } else {
                $DB->insert_record('tiny_authory_tech_writing_diff', $record);
            }

            return [
                'status' => get_string('success', 'tiny_authory_tech'),
                'message' => get_string('data_save', 'tiny_authory_tech'),
            ];
        } catch (moodle_exception $e) {
            // Handle the exception.
            return [
                'status' => get_string('failed', 'tiny_authory_tech'),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns parameters for store_user_writing method
     *
     * @return external_function_parameters Parameters definition for storing user writing data
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status message'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
