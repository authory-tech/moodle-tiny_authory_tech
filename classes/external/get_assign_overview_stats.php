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
 * External function authory_tech_get_assign_overview_stats: proxy GET /assignments/:cmid/stats to the type server.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assign_overview_stats extends external_api {
    /**
     * Parameters for get_assign_overview_stats
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Proxy GET /assignments/:cmid/stats to the type server.
     * Returns class-level aggregate and per-student WPM/duration for the overview tab.
     *
     * @param int $cmid Course module ID
     * @return string JSON from type server, or error JSON
     */
    public static function execute($cmid) {
        global $CFG, $DB;

        require_once("$CFG->libdir/filelib.php");

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid]
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:view', $context);

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

        $url = rtrim($serverurl, '/') . '/assignments/' . $params['cmid'] . '/stats';

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

        // Fall back to local content computation when typeserver has no data.
        $decoded = ($result !== false) ? json_decode($result, true) : null;
        if (!is_array($decoded) || !empty($decoded['error']) || empty($decoded['students'])) {
            return writing_stats::compute_overview_stats_from_content($params['cmid'], $DB);
        }

        return $result;
    }

    /**
     * Returns description of get_assign_overview_stats return value
     *
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'Assignment overview stats JSON from type server');
    }
}
