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
 * Injects the "View Authory Dashboard" button into the assignment view page.
 * Loaded automatically on page-mod-assign-view via constants::BODY_IDS.
 *
 * @module     tiny_authory_tech/inject_assign_dashboard_button
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    return {
        init: function() {
            $(document).ready(function() {
                var url = new URL(window.location.href);
                var cmid = url.searchParams.get('id');
                if (!cmid) {
                    return;
                }

                var dashUrl = M.cfg.wwwroot
                    + '/lib/editor/tiny/plugins/authory_tech/assign_dashboard.php?id=' + cmid;

                var $btn = $('<a>', {
                    href: dashUrl,
                    'class': 'btn btn-primary authory-dash-launch-btn',
                    css: {
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '6px',
                        marginBottom: '16px',
                        backgroundColor: '#234fc2',
                        borderColor: '#234fc2',
                    },
                    html: '<i class="fa fa-bar-chart"></i>&nbsp;View Authory Writing Dashboard'
                });

                // Insert above the assignment intro / submission summary block
                var $target = $('[data-region="submission-status-table"], .submissionsummary, .generalbox').first();
                if ($target.length) {
                    $target.before($btn);
                } else {
                    // Fallback: after page heading
                    $('header.card-header, .page-header-headings').first().after($btn);
                }
            });
        }
    };
});
