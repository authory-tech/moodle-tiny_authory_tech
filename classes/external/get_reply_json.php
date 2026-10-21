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
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;
use stdClass;
use tiny_authory_tech\helper;

/**
 * External function authory_tech_get_reply_json: return the stored json file.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_reply_json extends external_api {
    /**
     * Returns parameters for getting reply JSON
     *
     * @return external_function_parameters Parameters definition for getting reply JSON
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'filepath' => new external_value(PARAM_TEXT, 'filepath', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get reply JSON data for a file
     *
     * @param string $filepath Path to the file to get reply JSON for
     * @return stdClass Object containing status and data
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws moodle_exception
     */
    public static function execute($filepath) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'filepath' => $filepath,
            ],
        );
        // The module and the owner come from the stored record, never from the file name in the request.
        $filedata = $DB->get_record('tiny_authory_tech_files', ['filename' => $params['filepath']], '*', IGNORE_MULTIPLE);
        if (!$filedata) {
            throw new moodle_exception('filenotfound', 'tiny_authory_tech');
        }
        $context = context_module::instance($filedata->cmid);
        self::validate_context($context);
        helper::require_data_access((int) $filedata->userid, $context);

        $conditions = ['userid' => $filedata->userid, 'resourceid' => $filedata->resourceid, 'cmid' => $filedata->cmid];

        $data = new stdClass();
        try {
            $comments        = $DB->get_records('tiny_authory_tech_comments', $conditions, '', 'usercomment');
            $content         = $filedata->content ? $filedata->content : $content = false;
            $originalcontent = $filedata->original_content ? $filedata->original_content : $originalcontent = false;
            $data->status    = true;
            $commentslist    = [];

            foreach ($comments as $comment) {
                $commentslist[] = $comment->usercomment;
            }

            $commentslist = array_values($commentslist);
            $data->comments = json_encode($commentslist);

            if ($content === false) {
                $data->status = false;
                $content = get_string('filenotfoundor', 'tiny_authory_tech');
            }

            $data->data = $content;
            $data->original = $originalcontent;
        } catch (moodle_exception $e) {
            $data->data = $e->getMessage();
        }
        return $data;
    }

    /**
     * Returns description of method result value for authory_tech_get_reply_json
     *
     * @return external_single_structure Returns structure containing status and data
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, "file status"),
            'data' => new external_value(PARAM_TEXT, 'Reply Json'),
            'comments' => new external_value(PARAM_TEXT, 'Comments'),
            'original' => new external_value(PARAM_TEXT, 'Original Content'),
        ]);
    }
}
