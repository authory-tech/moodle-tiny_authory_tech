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
use moodle_exception;
use stdClass;
use tiny_authory_tech\constants;

/**
 * External function authory_tech_write_local_to_json: store User writing as json.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_local_to_json extends external_api {
    /**
     * Returns parameters for write_local_to_json method
     *
     * @return external_function_parameters Parameters definition for writing local data to JSON
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'resourceId' => new external_value(PARAM_INT, 'resourceId', VALUE_DEFAULT, 0),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
                'modulename' => new external_value(PARAM_TEXT, 'Modulename', VALUE_DEFAULT, ""),
                'editorid' => new external_value(PARAM_TEXT, 'editorid', VALUE_DEFAULT, ""),
                'json_data' => new external_value(PARAM_RAW, 'JSON Data', VALUE_DEFAULT, ""),
                'originalText' => new external_value(PARAM_RAW, 'original submission Text', VALUE_DEFAULT, ""),
            ],
        );
    }

    /**
     * Write delta event array to database
     *
     * @param int $resourceid Resource identifier
     * @param int $cmid Course module ID
     * @param string $modulename Module name
     * @param string|null $editorid Editor identifier
     * @param string $jsondata JSON-encoded array of delta events ({t, d, meta})
     * @param string $originaltext Original submission text
     * @return string name of the saved record.
     * @throws moodle_exception If the course module does not exist, or a capability or plan limit check fails
     */
    public static function execute(
        $resourceid = 0,
        $cmid = 0,
        $modulename = 'quiz',
        $editorid = null,
        $jsondata = '',
        $originaltext = ""
    ) {
        global $USER, $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'resourceId'   => $resourceid,
                'cmid'         => $cmid,
                'modulename'   => $modulename,
                'editorid'     => $editorid,
                'json_data'    => $jsondata,
                'originalText' => $originaltext,
            ],
        );

        if (
            $params['resourceId'] == 0 && $params['modulename'] !== 'forum' && $params['modulename'] !== 'oublog' &&
            $params['modulename'] !== 'pdfannotator'
        ) {
            $params['resourceId'] = $params['cmid'];
            // For Quiz and Assignment there is no resourceid that's why cmid is resourceid.
        }

        // Every save belongs to a course module, so the context, the capability and the plan limits
        // are checked on every call. A missing or unknown cmid is rejected here.
        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $courseid = (int) $cm->course;
        $params['courseId'] = $courseid;

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('tiny/authory_tech:write', $context);

        // Enforce plan limits.
        if (constants::is_trial_expired()) {
            throw new moodle_exception('trial_expired', 'tiny_authory_tech');
        }
        if (!constants::within_course_limit($courseid)) {
            throw new moodle_exception('course_limit_reached', 'tiny_authory_tech');
        }
        if (!constants::within_student_limit($courseid)) {
            throw new moodle_exception('student_limit_reached', 'tiny_authory_tech');
        }

        $questionid = constants::get_question_id($params['editorid']);

        $fname = $USER->id . '_' . $params['resourceId'] . '_' . $params['cmid'] . '_attempt' . '.json';
        if ($questionid) {
            $fname = $USER->id . '_' . $params['resourceId'] . '_' . $params['cmid'] . '_' . $questionid . '_attempt' . '.json';
        }

        $table = 'tiny_authory_tech_files';
        $inp = '';

        if ($questionid) {
            $inp = $DB->get_record($table, [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'],
                'resourceid' => $params['resourceId'],
                'userid' => $USER->id,
                'questionid' => $questionid,
            ]);
        } else {
            $inp = $DB->get_record($table, [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'],
                'resourceid' => $params['resourceId'],
                'userid' => $USER->id,
            ]);
        }
        $temparray = [];

        // Auto save function call.
        $params['questionid'] = $questionid ?? 0;
        constants::authory_tech_auto_save($params);

        // Delta events use the canonical format {t, d, meta} — validate and strip unknowns.
        $incoming = json_decode($params['json_data'], true) ?: [];
        $normalized = array_map(static function ($ev) {
            return [
                't'    => (int)($ev['t'] ?? 0),
                'd'    => $ev['d'] ?? [],
                'meta' => $ev['meta'] ?? 'keystroke',
            ];
        }, $incoming);

        if ($inp) {
            $temparray = json_decode($inp->content, true) ?: [];
            foreach ($normalized as $value) {
                $temparray[] = $value;
            }
            $inp->content = json_encode($temparray);
            $inp->original_content = $params['originalText'];
            $inp->uploaded = 0;
            $DB->update_record($table, $inp);
            return 'true';
        } else {
            $dataobj = new stdClass();
            $dataobj->userid = $USER->id;
            $dataobj->resourceid = $params['resourceId'];
            $dataobj->cmid = $params['cmid'];
            $dataobj->modulename = $params['modulename'];
            $dataobj->courseid = $courseid;
            $dataobj->timemodified = time();
            $dataobj->filename = $fname;
            $dataobj->content = json_encode($normalized);
            $dataobj->original_content = $params['originalText'];
            $dataobj->questionid = $questionid ?? 0;
            $dataobj->uploaded = 0;
            $DB->insert_record($table, $dataobj);
            return $fname;
        }
    }

     /**
      * Method write_local_to_json_returns
      * @return external_value Returns the filename as a text parameter
      *
      */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'filename');
    }
}
