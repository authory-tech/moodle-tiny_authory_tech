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
use dml_exception;
use stdClass;

/**
 * External function authory_tech_store_user_writing: storing User Writings.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class store_user_writing extends external_api {
    /**
     * Returns parameters for store_user_writing method
     *
     * @return external_function_parameters Parameters definition for storing user writing data
     */
    public static function execute_parameters() {
        return new external_function_parameters(self::storing_user_writing_param());
    }

    /**
     * Stores user writing data in the database
     *
     * @param int $personid User ID
     * @param int $fileid File ID to store data for
     * @param int $charactercount Total number of characters typed
     * @param int $totaltimeseconds Total time spent writing in seconds
     * @param float $charactersperminute Characters typed per minute
     * @param int $keycount Total number of keystrokes
     * @param float $keysperminute Keystrokes per minute
     * @param int $wordcount Total number of words written
     * @param float $wordsperminute Words written per minute
     * @param float $backspacepercent Percentage of backspace usage
     * @param string $copybehavior Copy/paste behavior flag
     * @param float $score Writing score
     * @param  int $qualityaccess Quality access flag
     * @return array Array containing status and message
     */
    public static function execute(
        $personid,
        $fileid,
        $charactercount,
        $totaltimeseconds,
        $charactersperminute,
        $keycount,
        $keysperminute,
        $wordcount,
        $wordsperminute,
        $backspacepercent,
        $copybehavior,
        $score,
        $qualityaccess,
    ) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'person_id' => $personid,
                'file_id' => $fileid,
                'character_count' => $charactercount,
                'total_time_seconds' => $totaltimeseconds,
                'characters_per_minute' => $charactersperminute,
                'key_count' => $keycount,
                'keys_per_minute' => $keysperminute,
                'word_count' => $wordcount,
                'words_per_minute' => $wordsperminute,
                'backspace_percent' => $backspacepercent,
                'copy_behavior' => $copybehavior,
                'score' => $score,
                'quality_access' => $qualityaccess,
            ],
        );

        try {
            $context = context_system::instance();
            // Assuming a system-wide capability check.
            self::validate_context($context);
            require_capability('tiny/authory_tech:editsettings', $context);

            $backspacepercent = round($params['backspace_percent'], 4);

            // Check if the record exists.
            $recordexists = $DB->record_exists('tiny_authory_tech_user_writing', ['file_id' => $params['file_id']]);
            // Retrieve existing data or initialize a new stdClass object.
            $data = $recordexists
                ? $DB->get_record('tiny_authory_tech_user_writing', ['file_id' => $params['file_id']])
                : new stdClass();

            // Populate data attributes.
            $data->file_id = $params['file_id'];
            $data->total_time_seconds = $params['total_time_seconds'];
            $data->key_count = $params['key_count'];
            $data->keys_per_minute = $params['keys_per_minute'];
            $data->character_count = $params['character_count'];
            $data->characters_per_minute = $params['characters_per_minute'];
            $data->word_count = $params['word_count'];
            $data->words_per_minute = $params['words_per_minute'];
            $data->backspace_percent = $params['backspace_percent'];
            $data->score = $params['score'];
            $data->copy_behavior = $params['copy_behavior'];
            $data->quality_access = $params['quality_access'];

            // Update or insert the record.
            if ($recordexists) {
                $DB->update_record('tiny_authory_tech_user_writing', $data);
            } else {
                $DB->insert_record('tiny_authory_tech_user_writing', $data);
            }

            // Return success status.
            return [
                'status' => get_string('success', 'tiny_authory_tech'),
                'message' => get_string('data_save', 'tiny_authory_tech'),
            ];
        } catch (dml_exception $e) {
            // Return failure status with error message.
            return [
                'status' => get_string('failed', 'tiny_authory_tech'),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method result value for store_user_writing
     *
     * @return external_single_structure Returns structure containing status and message
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'status message'),
            'message' => new external_value(PARAM_TEXT, 'message'),
        ]);
    }

    /**
     * Method storing_user_writing_param
     *
     * @return array array of parameters for storing data
     */
    protected static function storing_user_writing_param() {
        return [
            'person_id' => new external_value(PARAM_INT, 'person or user id', VALUE_REQUIRED),
            'file_id' => new external_value(PARAM_INT, 'file_id', VALUE_REQUIRED),
            'character_count' => new external_value(PARAM_INT, 'character_count', VALUE_REQUIRED),
            'total_time_seconds' => new external_value(PARAM_INT, 'total_time_seconds', VALUE_REQUIRED),
            'characters_per_minute' => new external_value(PARAM_INT, 'characters_per_minute', VALUE_REQUIRED),
            'key_count' => new external_value(PARAM_INT, 'key_count', VALUE_REQUIRED),
            'keys_per_minute' => new external_value(PARAM_INT, 'keys per minutes', VALUE_REQUIRED),
            'word_count' => new external_value(PARAM_INT, 'word_count', VALUE_REQUIRED),
            'words_per_minute' => new external_value(PARAM_INT, 'words_per_minute', VALUE_REQUIRED),
            'backspace_percent' => new external_value(PARAM_FLOAT, 'backspace_percent', VALUE_REQUIRED),
            'copy_behavior' => new external_value(PARAM_FLOAT, 'copy_behavior', VALUE_REQUIRED),
            'score' => new external_value(PARAM_FLOAT, 'score', VALUE_DEFAULT, 0),
            'quality_access' => new external_value(PARAM_INT, 'quality_access', VALUE_DEFAULT, 0),
        ];
    }
}
