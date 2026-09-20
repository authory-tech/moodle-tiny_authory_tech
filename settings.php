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
 * Tiny authory_tech plugin settings.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__ . '/locallib.php');
global $CFG, $DB, $PAGE;

$PAGE->requires->js_call_amd('tiny_authory_tech/token_approve', 'init', [1]);

$ADMIN->add('editortiny', new admin_category('tiny_authory_tech', new lang_string('pluginname', 'tiny_authory_tech')));

if ($ADMIN->fulltree) {
    // Prerequisite check: web services + REST protocol must be enabled for the ML server callback.
    $wsprotocols = array_map('trim', explode(',', (string)($CFG->webserviceprotocols ?? '')));
    $wsenabled   = !empty($CFG->enablewebservices);
    $restenabled = in_array('rest', $wsprotocols);

    if (!$wsenabled || !$restenabled) {
        $overviewurl  = (new moodle_url('/admin/webservice/overview.php'))->out(false);
        $protocolsurl = (new moodle_url('/admin/settings.php', ['section' => 'webserviceprotocols']))->out(false);
        $items = [];
        if (!$wsenabled) {
            $items[] = get_string('ws_prereq_enablewebservices', 'tiny_authory_tech', ['url' => $overviewurl]);
        }
        if (!$restenabled) {
            $items[] = get_string('ws_prereq_restprotocol', 'tiny_authory_tech', ['url' => $protocolsurl]);
        }
        $warninghtml = html_writer::div(
            html_writer::tag('strong', get_string('ws_prereq_heading', 'tiny_authory_tech')) .
            html_writer::alist($items),
            'alert alert-warning'
        );
        $settings->add(new admin_setting_heading('authory_tech_ws_prereq', '', $warninghtml));
    }

    // Live status panel.
    $apikey    = get_config('tiny_authory_tech', 'secretkey');
    $serverurl = get_config('tiny_authory_tech', 'python_server');
    $pending   = $DB->count_records_sql("SELECT COUNT(*) FROM {tiny_authory_tech_files} WHERE timemodified > uploaded");
    $lastts    = $DB->get_field_sql("SELECT MAX(uploaded) FROM {tiny_authory_tech_files} WHERE uploaded > 0");
    $lastupload = $lastts ? userdate($lastts) : get_string('status_never', 'tiny_authory_tech');

    $badgeok  = '<span class="badge bg-success text-white">%s</span>';
    $badgebad = '<span class="badge bg-danger text-white">%s</span>';
    $badgewarn = '<span class="badge bg-warning text-dark">%s</span>';

    $keyhtml    = $apikey
        ? sprintf($badgeok, get_string('status_configured', 'tiny_authory_tech'))
        : sprintf($badgebad, get_string('status_not_configured', 'tiny_authory_tech'));
    $serverhtml = $serverurl
        ? '<code>' . htmlspecialchars($serverurl, ENT_QUOTES) . '</code>'
        : sprintf($badgebad, get_string('status_not_configured', 'tiny_authory_tech'));
    $pendinghtml = $pending > 0
        ? sprintf($badgebad, $pending)
        : sprintf($badgeok, '0');

    $statustable  = '<table class="generaltable w-auto mt-2 mb-3"><tbody>';
    $cell = '<tr><th scope="row" class="pr-4">';
    $statustable .= $cell . get_string('status_api_key', 'tiny_authory_tech') . '</th><td>' . $keyhtml . '</td></tr>';
    $statustable .= $cell . get_string('status_server_url', 'tiny_authory_tech') . '</th><td>' . $serverhtml . '</td></tr>';
    $statustable .= $cell . get_string('status_pending_uploads', 'tiny_authory_tech') . '</th><td>' . $pendinghtml . '</td></tr>';
    $statustable .= $cell . get_string('status_last_upload', 'tiny_authory_tech') . '</th><td>' . $lastupload . '</td></tr>';

    // Plan info rows.
    $plan = get_config('tiny_authory_tech', 'plan');
    if ($plan) {
        $plannames = [
            'trial'       => get_string('plan_trial', 'tiny_authory_tech'),
            'centro'      => get_string('plan_centro', 'tiny_authory_tech'),
            'institution' => get_string('plan_institution', 'tiny_authory_tech'),
        ];
        $planhtml = $plannames[$plan] ?? htmlspecialchars($plan, ENT_QUOTES);
        $statustable .= $cell . get_string('status_plan', 'tiny_authory_tech') . '</th><td>' . $planhtml . '</td></tr>';

        if ($plan === 'trial') {
            $trialstart = (int) get_config('tiny_authory_tech', 'trial_start');
            if ($trialstart) {
                $expiryts   = $trialstart + 60 * DAYSECS;
                $daysremain = max(0, (int) (($expiryts - time()) / DAYSECS));
                $expirydate = userdate($expiryts, get_string('strftimedatefullshort'));
                $expiredtext = get_string('status_trial_expiry', 'tiny_authory_tech') . ' — ' . $expirydate;
                if (time() > $expiryts) {
                    $expiryhtml = sprintf($badgebad, $expiredtext);
                } else if ($daysremain <= 14) {
                    $expiryhtml = sprintf($badgewarn, $expiredtext);
                } else {
                    $expiryhtml = $expiredtext;
                }
                $expirykey = get_string('status_trial_expiry', 'tiny_authory_tech');
                $statustable .= $cell . $expirykey . '</th><td>' . $expiryhtml . '</td></tr>';
            }

            $studentlimit = (int)(get_config('tiny_authory_tech', 'student_limit') ?: 35);
            $courselimit = (int)(get_config('tiny_authory_tech', 'course_limit') ?: 2);
            $studentused = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {tiny_authory_tech_files}"
            );
            $courseused = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT courseid) FROM {tiny_authory_tech_files} WHERE courseid > 0"
            );
            $studentskey = get_string('status_students_used', 'tiny_authory_tech');
            $courseskey = get_string('status_courses_used', 'tiny_authory_tech');
            $statustable .= $cell . $studentskey . '</th><td>' . "$studentused / $studentlimit" . '</td></tr>';
            $statustable .= $cell . $courseskey . '</th><td>' . "$courseused / $courselimit" . '</td></tr>';
        } else if ($plan === 'centro') {
            $studentlimit = (int)(get_config('tiny_authory_tech', 'student_limit') ?: 300);
            $studentused = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {tiny_authory_tech_files}"
            );
            $studentskey = get_string('status_students_used', 'tiny_authory_tech');
            $statustable .= $cell . $studentskey . '</th><td>' . "$studentused / $studentlimit" . '</td></tr>';
        }
    }

    $statustable .= '</tbody></table>';

    $settings->add(new admin_setting_heading(
        'authory_tech_status_panel',
        get_string('status_panel_heading', 'tiny_authory_tech'),
        $statustable
    ));
    // End status panel.

    $information = html_writer::tag(
        'p',
        get_string('pluginname_desc_new_2', 'tiny_authory_tech')
    );

    $information .= html_writer::tag(
        'p',
        get_string('pluginname_desc_new_3', 'tiny_authory_tech') . ' ' .
        html_writer::link(
            'mailto:info@authory.tech',
            'info@authory.tech'
        ),
        ['style' => 'margin-bottom: 2rem;']
    );

    $settings->add(
        new admin_setting_heading(
            'authory_tech_settings',
            "",
            $information
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/secretkey',
            get_string('secretkey', 'tiny_authory_tech'),
            get_string('secretkey_desc', 'tiny_authory_tech') . '' .
            "<br/><a id='approve_token' href='#' class='btn btn-primary'>  " .
            get_string('test_token', 'tiny_authory_tech') . " </a><span id='token_message'></span>",
            "",
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/python_server',
            get_string('api_url', 'tiny_authory_tech'),
            get_string('api_addr_url', 'tiny_authory_tech') .
            "<br/><a id='test_connection' href='#' class='btn btn-primary mt-1'>  " .
            get_string('test_connection', 'tiny_authory_tech') . " </a><span id='connection_message'></span>",
            '',
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/host_url',
            get_string('moodle_host', 'tiny_authory_tech'),
            get_string('host_domain', 'tiny_authory_tech'),
            $CFG->wwwroot,
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'tiny_authory_tech/enable_replay',
            get_string('enable_replay', "tiny_authory_tech"),
            get_string('enable_replay_desc', 'tiny_authory_tech'),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'tiny_authory_tech/enable_state_popup',
            get_string('enable_state_popup', "tiny_authory_tech"),
            get_string('enable_state_popup_desc', 'tiny_authory_tech'),
            1
        )
    );

    $settings->add(
        new admin_setting_heading(
            'authory_tech_queue_heading',
            get_string('queue_heading', 'tiny_authory_tech'),
            get_string('queue_heading_desc', 'tiny_authory_tech')
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/queue_url',
            get_string('queue_url', 'tiny_authory_tech'),
            get_string('queue_url_desc', 'tiny_authory_tech'),
            '',
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/queue_access_key',
            get_string('queue_access_key', 'tiny_authory_tech'),
            get_string('queue_access_key_desc', 'tiny_authory_tech'),
            '',
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configpasswordunmask(
            'tiny_authory_tech/queue_secret_key',
            get_string('queue_secret_key', 'tiny_authory_tech'),
            get_string('queue_secret_key_desc', 'tiny_authory_tech'),
            ''
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/authory_tech_token',
            get_string('webservicetoken', "tiny_authory_tech"),
            "<a id='generate_authory_tech_token' href='#' class=''>  " .
            get_string('generate', 'tiny_authory_tech') . " </a>" . ' ' .
            get_string('webservicetoken_des', 'tiny_authory_tech') . "<br><span id='authory_tech_token_'></span>",
            '',
            PARAM_TEXT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/syncinterval',
            get_string('syncinterval', 'tiny_authory_tech'),
            get_string('syncinterval_des', 'tiny_authory_tech'),
            10,
            PARAM_TEXT
        )
    );

    $settings->add(
        new admin_setting_configempty(
            'tiny_authory_tech/authory_tech_disable',
            get_string('authory_tech_disable', 'tiny_authory_tech'),
            "<a href='#authory_tech_disable' class='btn btn-primary mb-1' id='authory_tech_disable' >" .
            get_string('disable', 'tiny_authory_tech') . "</a>
            <a href='#authory_tech_enable' class='btn btn-primary mb-1' id='authory_tech_enable'>" .
            get_string('enable', 'tiny_authory_tech') . "
            </a><br><span id='authory_tech_disable_'></span><br>" .
            get_string('authory_tech_disable_des', 'tiny_authory_tech'),
        )
    );

    $settings->add(
        new admin_setting_heading(
            'authory_tech_more_info',
            get_string('new_admin_heading', 'tiny_authory_tech'),
            get_string('new_admin_desc', "tiny_authory_tech"),
        )
    );

        $settings->add(
            new admin_setting_configtext(
                'tiny_authory_tech/note_text',
                get_string('note_text_title', 'tiny_authory_tech'),
                "",
                get_string('authory_tech_enable_notice', 'tiny_authory_tech'),
                PARAM_TEXT
            )
        );

    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/note_url_text',
            get_string('note_url_text', 'tiny_authory_tech'),
            "",
            get_string('authory_tech_more_info', 'tiny_authory_tech'),
            PARAM_TEXT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'tiny_authory_tech/note_url',
            get_string('note_url_title', 'tiny_authory_tech'),
            "",
            "https://authory.tech",
            PARAM_TEXT
        )
    );
}
