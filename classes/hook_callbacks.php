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
 * Tiny authory_tech plugin.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tiny_authory_tech;

use context_course;
use context_module;
use core\hook\output\before_footer_html_generation;
use core_component;
use core_course\hook\after_form_definition;
use core_course\hook\after_form_submission;
use tiny_authory_tech\constants;
use function array_key_exists;

/**
 * Tiny authory_tech plugin hook callback class.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author Brain Station 23 <sales@brainstation-23.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Hook to modify the output before footer HTML is generated.
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook) {
        global $PAGE, $COURSE, $USER, $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/locallib.php');
        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');

        if (empty($COURSE) || during_initial_install()) {
            return;
        }

        $techstatus = tiny_authory_tech_status($COURSE->id);
        $context       = "";

        if (!$techstatus) {
            return;
        }

        $cmid = tiny_authory_tech_get_cmid($COURSE->id) ?? 0;
        if ($cmid) {
            $context = context_module::instance($cmid);
        }
        if ($context && !has_capability('tiny/authory_tech:writingreport', $context, $USER->id)) {
            return;
        }

        $context  = context_course::instance($COURSE->id);
        $userrole = constants::is_teacher_admin($context) ? 'teacher_admin' : '';

        $PAGE->requires->js_call_amd('tiny_authory_tech/settings', 'init', [1, $userrole]);

        if (array_key_exists($PAGE->bodyid, constants::BODY_IDS)) {
            if (constants::is_active()) {
                $module = constants::BODY_IDS[$PAGE->bodyid][0];
                $params = [];

                // Modules that receive hasApiKey.
                if ($module !== 'inject_assign_dashboard_button') {
                    $params[] = constants::has_api_key();
                }
                // Pdfannotator also needs userid (before enableReplay).
                if ($module === 'append_pdfannotator') {
                    $params[] = $USER->id;
                }
                // Modules that receive enableReplay.
                $replaymodules = [
                    'append_fourm_post', 'show_url_in_submission_grade', 'append_submissions_table',
                    'show_url_in_quiz_detail', 'append_lesson_grade_table', 'append_pdfannotator',
                ];
                if (in_array($module, $replaymodules)) {
                    $params[] = constants::enable_replay();
                }

                if ($module !== 'inject_assign_dashboard_button' || !empty($userrole)) {
                    $PAGE->requires->js_call_amd(
                        "tiny_authory_tech/" . $module,
                        'init',
                        $params
                    );
                }
            }
        }
    }

    /**
     * Hook to modify the form after its definition.
     *
     * @param after_form_definition $hook The hook instance
     */
    public static function after_form_definition(after_form_definition $hook) {
        global $COURSE, $CFG;

        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');

        if (tiny_authory_tech_is_instance_disabled()) {
            return;
        }

        $mform = $hook->mform;
        $mform->addElement('header', 'Authory.tech', get_string('pluginname', 'tiny_authory_tech'), [], [
            'collapsed' => false,
        ]);
        // Add a static element for the notice above the enable/disable dropdown.
        $noticemsg = get_config('tiny_authory_tech', 'note_text') ?: get_string('authory_tech_enable_notice', 'tiny_authory_tech');
        $noticeurl = get_config('tiny_authory_tech', 'note_url') ?: 'https://authory.tech';
        $urltext   = get_config('tiny_authory_tech', 'note_url_text') ?: get_string('authory_tech_more_info', 'tiny_authory_tech');

        $notice = "{$noticemsg} <a href='{$noticeurl}' target='_blank'>{$urltext}</a>";

        $mform->addElement('static', 'authory_tech_notice', '', $notice);

        $mform->addElement('select', 'authory_tech_status', get_string('authory_tech_status', 'tiny_authory_tech'), [
            '0' => get_string('disabled', 'tiny_authory_tech'),
            '1' => get_string('enabled', 'tiny_authory_tech'),
        ]);

        $default = get_config('tiny_authory_tech', "authory_tech-$COURSE->id");
        $mform->setDefault('authory_tech_status', $default);
    }

    /**
     * Hook to handle form submission after it is processed.
     *
     * @param after_form_submission $hook The hook instance containing the form submission data
     */
    public static function after_form_submission(after_form_submission $hook) {

        $courseid = $hook->get_data()->id;
        $status   = $hook->get_data()->authory_tech_status ?? false;
        $name     = "authory_tech-$courseid";
        set_config($name, $status, 'tiny_authory_tech');
    }
}
