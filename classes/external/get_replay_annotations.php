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
use moodle_exception;
use tiny_authory_tech\helper;

/**
 * External function authory_tech_get_replay_annotations: get pre-computed AI annotations and analytics for a replay session.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_replay_annotations extends external_api {
    /**
     * Parameters for authory_tech_get_replay_annotations
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'filepath' => new external_value(PARAM_TEXT, 'filepath (userid_resourceid_cmid)'),
        ]);
    }

    /**
     * Proxy GET /sessions/:resource_id/replay-annotations to the type server.
     * Returns pre-computed AI annotations and session analytics.
     *
     * @param string $filepath Filepath in userid_resourceid_cmid format
     * @return string JSON response from type server, or error JSON
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws moodle_exception
     */
    public static function execute($filepath) {
        global $CFG, $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['filepath' => $filepath]
        );

        // Look up the actual file record id — this is what the typeserver stores as resource_id.
        // The module and the owner come from the record, never from the file name in the request.
        $filerecord = $DB->get_record(
            'tiny_authory_tech_files',
            ['filename' => $params['filepath']],
            'id, userid, cmid',
            IGNORE_MULTIPLE
        );
        if (!$filerecord) {
            throw new moodle_exception('filenotfound', 'tiny_authory_tech');
        }
        $context = context_module::instance($filerecord->cmid);
        self::validate_context($context);
        helper::require_data_access((int) $filerecord->userid, $context);

        $secret    = get_config('tiny_authory_tech', 'secretkey');
        $serverurl = get_config('tiny_authory_tech', 'python_server');

        if (empty($secret) || empty($serverurl)) {
            return json_encode(['error' => 'Plugin not configured']);
        }

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

        $expires      = time() + 600;
        $sig          = hash_hmac('sha256', $wstoken . ':' . $expires, $secret);
        $teachertoken = $sig . ':' . $expires;

        $url = rtrim($serverurl, '/') . '/sessions/' . $filerecord->id .
            '/replay-annotations?person_id=' . (int) $filerecord->userid;

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
            return json_encode(['error' => $curl->error]);
        }

        return $result;
    }

    /**
     * Returns description of authory_tech_get_replay_annotations return value
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'Replay annotations JSON from type server');
    }
}
