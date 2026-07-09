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

/**
 * Web services prerequisite check for Authory.tech.
 *
 * @package   tiny_authory_tech
 * @copyright 2026 SEPTUM QA <info@authory.tech>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tiny_authory_tech\check;

use core\check\check;
use core\check\result;
use html_writer;
use moodle_url;

/**
 * Checks that web services and the REST protocol are enabled.
 *
 * The Authory.tech ML server uses a Moodle REST web service token to call back
 * into Moodle (e.g. to store computed analytics). Without these settings the
 * callback silently fails and analytics are never written.
 */
class webservices extends check {
    /**
     * Return the unique string identifier for this check.
     *
     * @return string
     */
    public function get_ref(): string {
        return 'webservices';
    }

    /**
     * Return the human-readable name of this check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check_webservices_name', 'tiny_authory_tech');
    }

    /**
     * Return the action link for resolving this check, if any.
     *
     * @return \core\url|null
     */
    public function get_action_link(): ?\core\url {
        return new \core\url('/admin/webservice/overview.php');
    }

    /**
     * Run the check and return a result object.
     *
     * @return result
     */
    public function get_result(): result {
        global $CFG;

        $protocols   = array_map('trim', explode(',', (string)($CFG->webserviceprotocols ?? '')));
        $wsenabled   = !empty($CFG->enablewebservices);
        $restenabled = in_array('rest', $protocols);

        if ($wsenabled && $restenabled) {
            return new result(result::OK, get_string('check_webservices_ok', 'tiny_authory_tech'));
        }

        $items = [];
        if (!$wsenabled) {
            $overviewurl = (new moodle_url('/admin/webservice/overview.php'))->out(false);
            $items[] = get_string('ws_prereq_enablewebservices', 'tiny_authory_tech', ['url' => $overviewurl]);
        }
        if (!$restenabled) {
            $protocolsurl = (new moodle_url('/admin/settings.php', ['section' => 'webserviceprotocols']))->out(false);
            $items[] = get_string('ws_prereq_restprotocol', 'tiny_authory_tech', ['url' => $protocolsurl]);
        }

        return new result(result::WARNING, html_writer::alist($items));
    }
}
