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
use curl;
use tiny_authory_tech\local\writing_stats;

/**
 * External function authory_tech_get_student_stats: get student writing stats from the type server.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_stats extends external_api {
    /**
     * Parameters for get_student_stats
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'resource_id' => new external_value(PARAM_INT, 'File record ID sent to type server as resource_id'),
                'userid'      => new external_value(PARAM_INT, 'Student user ID (person_id)'),
            ]
        );
    }

    /**
     * Proxy GET /assignments/:cmid/students/:userid/stats to the type server.
     * Auth uses HMAC-SHA256(secret, ws_token:expires) to generate X-Teacher-Token.
     *
     * @param int $resourceid File record ID; the course module and the student are taken from this record
     * @param int $userid Student user ID (kept for compatibility, the file record's owner is used instead)
     * @return string JSON response from type server, or error JSON
     */
    public static function execute($resourceid, $userid) {
        global $CFG, $DB;

        require_once("$CFG->libdir/filelib.php");

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['resource_id' => $resourceid, 'userid' => $userid]
        );

        // The module and the student come from the stored record, never from the request.
        $filerecord = $DB->get_record('tiny_authory_tech_files', ['id' => $params['resource_id']], 'id, userid, cmid', MUST_EXIST);
        $context = context_module::instance($filerecord->cmid);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

        $secret    = get_config('tiny_authory_tech', 'secretkey');
        $serverurl = get_config('tiny_authory_tech', 'python_server');

        if (empty($secret) || empty($serverurl)) {
            return json_encode(['error' => 'Plugin not configured']);
        }

        // Resolve ws_token the same way as the cron task.
        $wstoken = get_config('tiny_authory_tech', 'authory_tech_token');
        if (empty($wstoken)) {
            $service = $DB->get_record('external_services', ['shortname' => 'authory_tech_json_service']);
            if ($service) {
                $adminuser = get_admin();
                $dbtoken   = $DB->get_record(
                    'external_tokens',
                    ['userid' => $adminuser->id, 'externalserviceid' => $service->id],
                    '*',
                    IGNORE_MULTIPLE
                );
                $wstoken = $dbtoken ? $dbtoken->token : '';
            }
        }

        if (empty($wstoken)) {
            return json_encode(['error' => 'No web service token configured']);
        }

        // Build HMAC teacher token: signature:expires.
        $expires      = time() + 600;
        $sig          = hash_hmac('sha256', $wstoken . ':' . $expires, $secret);
        $teachertoken = $sig . ':' . $expires;

        $url = rtrim($serverurl, '/') . '/assignments/' . $filerecord->cmid . '/students/' . (int) $filerecord->userid . '/stats';

        $curl    = new curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_HTTPHEADER'     => [
                'X-Teacher-Token: ' . $teachertoken,
                'X-Ws-Token: ' . $wstoken,
                'Accept: application/json',
            ],
        ];

        $result = $curl->get($url, [], $options);

        if ($result === false) {
            return json_encode(['stats_available' => false, 'error' => $curl->error]);
        }

        // Merge submission text and filename from Moodle DB into the response.
        $filerecordextra = $DB->get_record(
            'tiny_authory_tech_files',
            ['id' => $params['resource_id']],
            'original_content, filename, content',
            IGNORE_MISSING
        );
        $durations = writing_stats::compute_duration_from_content($params['resource_id'], $DB);
        $localfallback = [
            'submission_text'         => $filerecordextra->original_content ?? '',
            'filename'                => $filerecordextra->filename ?? '',
            'duration_seconds'        => $durations['session'],
            'typing_duration_seconds' => $durations['typing'],
            // Always use local content as source of truth for paste texts (matches replay exactly).
            'pasted_texts'            => writing_stats::compute_pastes_from_content($params['resource_id'], $DB),
        ];

        $merged = writing_stats::build_student_stats_payload($curl->info['http_code'] ?? 0, $result, $localfallback);

        return json_encode($merged);
    }

    /**
     * Returns description of get_student_stats return value
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'Student stats JSON from type server');
    }
}
