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
 * Tiny authory_tech plugin ad-hoc task to process forum events off the request thread.
 *
 * @package tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tiny_authory_tech\task;

use core\task\adhoc_task;
use tiny_authory_tech\observers;

/**
 * Performs the comment/file tracking record updates that used to run synchronously inside
 * mod_forum event observers, so that posting/updating a forum message isn't slowed down by
 * them.
 *
 * @package tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_forum_event extends adhoc_task {
    /**
     * Execution function.
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute() {
        $data      = json_decode($this->get_custom_data_as_string(), true) ?? [];
        $type      = $data['type'] ?? '';
        $eventdata = $data['eventdata'] ?? [];

        if (empty($eventdata)) {
            return;
        }

        if ($type === 'discussion') {
            observers::process_discussion_event($eventdata);
        } else {
            observers::process_post_event($eventdata);
        }
    }
}
