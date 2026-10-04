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
use tiny_authory_tech\helper;

/**
 * External function authory_tech_update_pdf_annote_id: update resourceid for pdf annote analytics file.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_pdf_annote_id extends external_api {
    /**
     * Returns the parameters definition for update_pdf_annote_id external function.
     *
     * @return external_function_parameters Parameters definition containing:
     *         - resourceid (int): Optional ID parameter
     *         - modulename (string): Optional module name parameter
     *         - cmid (int): Optional course module ID parameter
     *         - userid (int): Optional user ID parameter
     *         - courseid (int): Optional course ID parameter
     */
    public static function execute_parameters() {
        return new external_function_parameters([
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'resourceid' => new external_value(PARAM_INT, 'pdf annote comment id', VALUE_DEFAULT, 0),
            ]);
    }

    /**
     * Updates the PDF annotation ID for a comment record
     *
     * @param int $cmid The course module ID
     * @param int $userid The user ID (defaults to current user)
     * @param int $courseid The course ID
     * @param string $modulename The name of the module
     * @param int $resourceid The PDF annotation comment ID
     * @return bool
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     * @throws \required_capability_exception
     */
    public static function execute($cmid, $userid, $courseid, $modulename, $resourceid) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid'       => $cmid,
                'userid'     => $userid,
                'courseid'   => $courseid,
                'modulename' => $modulename,
                'resourceid' => $resourceid,
            ],
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:writingreport", $context);

        helper::update_resource_id($params);
        return true;
    }

    /**
     * Returns description of update_pdf_annote_id return value
     *
     * @return external_value Returns a boolean parameter indicating if the update was successful
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'update pdf annote id');
    }
}
