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
 * Tiny authory_tech plugin upload file using cron to the api server.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tiny_authory_tech\task;
use core\task\scheduled_task;
/**
 * Tiny authory_tech plugin upload file using cron to the api server.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_student_json_cron extends scheduled_task {
    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'tiny_authory_tech');
    }

    /**
     * Execution function
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');

        mtrace('[authory_tech] Starting upload task...');

        // Determine upload target: SQS queue takes priority over direct type-server HTTP.
        $queueurl       = get_config('tiny_authory_tech', 'queue_url');
        $queueaccesskey = get_config('tiny_authory_tech', 'queue_access_key');
        $queuesecretkey = get_config('tiny_authory_tech', 'queue_secret_key');
        $usequeue       = !empty($queueurl) && !empty($queueaccesskey) && !empty($queuesecretkey);

        // Retrieve the Moodle web service token — needed by both paths
        // (stored in keystroke_session.ws_token for type-server callbacks).
        $wstoken = get_config('tiny_authory_tech', 'authory_tech_token') ?: '';
        if (!$wstoken) {
            $service = $DB->get_record('external_services', ['shortname' => 'authory_tech_json_service']);
            if ($service) {
                $dbtoken = $DB->get_record(
                    'external_tokens',
                    ['userid' => get_admin()->id, 'externalserviceid' => $service->id],
                    '*',
                    IGNORE_MULTIPLE
                );
                $wstoken = $dbtoken ? $dbtoken->token : '';
            }
        }

        if (!$usequeue && empty($wstoken)) {
            mtrace('[authory_tech] No upload target configured (no SQS queue and no web service token). Aborting.');
            return;
        }

        mtrace('[authory_tech] Upload mode: ' . ($usequeue ? 'SQS queue' : 'type-server HTTP'));

        $sql = "SELECT tcf.*
                FROM {tiny_authory_tech_files} tcf
                WHERE tcf.timemodified > tcf.uploaded";
        $filerecords = $DB->get_records_sql($sql);

        $total   = count($filerecords);
        $success = 0;
        $failed  = 0;

        mtrace("[authory_tech] {$total} file(s) pending upload.");

        foreach ($filerecords as $filerecord) {
            $answer = $filerecord->original_content ?? '';

            if ($usequeue) {
                $uploaded = tiny_authory_tech_publish_to_sqs(
                    $filerecord,
                    $wstoken,
                    $answer,
                    $queueurl,
                    $queueaccesskey,
                    $queuesecretkey
                );
            } else {
                $uploaded = tiny_authory_tech_upload_multipart_record(
                    $filerecord,
                    $filerecord->filename,
                    $wstoken,
                    $answer
                );
            }

            if ($uploaded) {
                $filerecord->uploaded = time();
                $DB->update_record('tiny_authory_tech_files', $filerecord);
                $success++;
            } else {
                $failed++;
            }
        }

        mtrace("[authory_tech] Upload complete: {$success} succeeded, {$failed} failed.");
    }
}
