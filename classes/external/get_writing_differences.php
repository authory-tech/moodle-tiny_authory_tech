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
use tiny_authory_tech\helper;

/**
 * External function authory_tech_get_writing_differences: return the stored writing difference data.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_writing_differences extends external_api {
    /**
     * Returns parameters for authory_tech_get_writing_diff method
     *
     * @return external_single_structure Returns structure containing status and message
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'fileid' => new external_value(PARAM_INT, 'file id', VALUE_REQUIRED, 0, true),
        ]);
    }

    /**
     * Get writing differences for a user submission
     *
     * @param int $fileid File identifier
     * @return array Writing differences data
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($fileid) {
        global $DB;

        $vparams = self::validate_parameters(
            self::execute_parameters(),
            [
                'fileid' => $fileid,
            ],
        );

        // The module and the owner come from the stored record, never from the request.
        $file = $DB->get_record('tiny_authory_tech_files', ['id' => $vparams['fileid']], 'id, userid, cmid', MUST_EXIST);
        $context = context_module::instance($file->cmid);
        self::validate_context($context);
        helper::require_data_access((int) $file->userid, $context);

        $sql = "SELECT WD.*, CF.cmid, CF.resourceid, CF.modulename, COUNT(CC.id) AS commentscount, CF.userid, CF.questionid
                  FROM {tiny_authory_tech_writing_diff} WD
                  JOIN {tiny_authory_tech_files} CF ON CF.id = WD.file_id
             LEFT JOIN {tiny_authory_tech_comments} CC ON CC.resourceid = CF.resourceid
                                                AND CC.modulename = CF.modulename
                                                AND CC.cmid = CF.cmid
                                                AND CC.userid = CF.userid
                                                AND CC.questionid = CF.questionid
                 WHERE WD.file_id = :fileid
              GROUP BY WD.id, CF.userid, CF.questionid, CF.cmid, CF.resourceid, CF.modulename";

        $params = ['fileid' => $vparams['fileid']];
        $data = $DB->get_record_sql($sql, $params);
        if ($data) {
            // Same encoding as stored (base64 of a JSON string), but with the HTML cleaned.
            $data->submitted_text = base64_encode(json_encode(helper::clean_submitted_text($data->submitted_text, $context)));
            $comments = $DB->get_records(
                'tiny_authory_tech_comments',
                [
                    'resourceid' => $data->resourceid,
                    'modulename' => $data->modulename,
                    'cmid' => $data->cmid,
                    'userid' => $data->userid,
                    'questionid' => $data->questionid,
                ],
            );
            $data->comments = $comments;
        }

        return ['data' => json_encode($data)];
    }

    /**
     * Returns description of method result value for authory_tech_get_writing_differencs
     *
     * @return external_single_structure Returns structure containing content data
     */
    public static function execute_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_TEXT, 'content data'),
        ]);
    }
}
