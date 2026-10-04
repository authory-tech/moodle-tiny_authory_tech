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
use stdClass;
use tiny_authory_tech\constants;
use tiny_authory_tech\helper;
use tiny_authory_tech\local\writing_stats;

/**
 * External function authory_tech_get_writing_statistics: return the stored analytics data.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_writing_statistics extends external_api {
    /**
     * Returns parameters for store_user_writing method
     *
     * @return external_function_parameters Parameters definition for storing user writing data
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_REQUIRED, 0, true),
            'fileid' => new external_value(PARAM_INT, 'file id', VALUE_REQUIRED, 0, true),
        ]);
    }

    /**
     * Get analytics data for a user submission
     *
     * @param int $cmid Course module ID
     * @param int $fileid File ID
     * @return array Analytics data
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute($cmid, $fileid) {
        global $DB;

        $vparams = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'fileid' => $fileid,
            ],
        );

        $context = context_module::instance($vparams['cmid']);
        self::validate_context($context);
        require_capability('tiny/authory_tech:writingreport', $context);

        $sql = "SELECT u.*, d.meta as effort_ratio, cf.userid, cf.uploaded
                  FROM {tiny_authory_tech_user_writing} u
             LEFT JOIN {tiny_authory_tech_writing_diff} d ON u.file_id = d.file_id
             LEFT JOIN {tiny_authory_tech_files} cf ON u.file_id = cf.id
                 WHERE u.file_id = :fileid";

        $params = ['fileid' => $vparams['fileid']];
        $rec = $DB->get_record_sql($sql, $params);
        if (isset($rec->effort_ratio)) {
            $rec->effort_ratio = round($rec->effort_ratio * 100, 2);
        }

        if ($rec) {
            $firstfileid = helper::get_user_first_file_id((int)($rec->userid ?? 0));
            $rec->first_file = ($firstfileid !== null && $firstfileid == $rec->file_id) ? 1 : 0;
            $rec->resubmit = constants::is_resubmitable($rec, $vparams['fileid']);
            $rec->file_id = $vparams['fileid'];
            $rec->cmid = $vparams['cmid'];
        } else {
            $rec = new stdClass();
            $rec->resubmit = constants::is_resubmitable($rec, $vparams['fileid']);
            $rec->file_id = $vparams['fileid'];
            $rec->cmid = $vparams['cmid'];
        }

        if (empty($rec->total_time_seconds)) {
            $durations = writing_stats::compute_duration_from_content($vparams['fileid'], $DB);
            if ($durations['session'] > 0) {
                $rec->total_time_seconds  = $durations['session'];
                $rec->typing_time_seconds = $durations['typing'];
            }
        }

        if (empty($rec->total_time_seconds)) {
            $annotations = writing_stats::fetch_typeserver_annotations($vparams['fileid'], $DB);
            if ($annotations !== null && isset($annotations->analytics->duration_ms)) {
                $rec->total_time_seconds = intval($annotations->analytics->duration_ms / 1000);
                if (isset($annotations->analytics->typing_duration_ms)) {
                    $rec->typing_time_seconds = intval($annotations->analytics->typing_duration_ms / 1000);
                }
            }
        }

        return ['data' => json_encode($rec)];
    }

    /**
     * Returns parameters for authory_tech_get_analytics method
     *
     * @return external_function_parameters Parameters definition for storing quality metrics data
     */
    public static function execute_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_TEXT, 'Record object'),
        ]);
    }
}
