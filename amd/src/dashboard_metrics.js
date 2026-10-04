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
 * Pure, framework-independent display logic for the teacher writing-analytics
 * dashboard, extracted out of assign_dashboard.js so it can be unit-tested
 * directly (see tests/js/dashboard_metrics.test.js) without a browser, jQuery,
 * or a running Moodle instance.
 *
 * @module     tiny_authory_tech/dashboard_metrics
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Format a duration in seconds as a human-readable string.
     * Shows seconds when under a minute, otherwise whole minutes.
     *
     * The unit wording is passed in as localised strings with a {$a} placeholder
     * (e.g. "{$a} sec"), so this module stays free of the Moodle string API.
     *
     * @param {number} seconds
     * @param {{seconds: string, minutes: string}} units Localised "{$a} sec" / "{$a} min" templates
     * @returns {string}
     */
    function formatDuration(seconds, units) {
        if (!seconds || seconds <= 0) {
            return '—';
        }
        if (seconds < 60) {
            return units.seconds.replace('{$a}', Math.round(seconds));
        }
        return units.minutes.replace('{$a}', Math.round(seconds / 60));
    }

    /**
     * Resolves the "Typing Speed" card's display value and comparison figures from
     * a raw stats object (the JSON response of authory_tech_get_student_stats).
     *
     * When stats.stats_available is false (type-server couldn't be reached, or
     * returned an error), avg_wpm/class_avg_wpm/wpm_diff are meaningless — this
     * renders an explicit "unavailable" state instead of a misleading "0 WPM".
     *
     * Deliberately returns raw numbers/flags rather than a pre-built English
     * sentence: this module stays framework-independent (no Moodle string API
     * dependency), so the caller (assign_dashboard.js, which has access to
     * core/str) is the one that composes the final, translatable comparison text.
     *
     * @param {Object} stats Raw stats object from authory_tech_get_student_stats
     * @returns {{statsAvailable: boolean, displayValue: (number|string),
     *           wpmDiffFormatted: string, classAvgWpm: number,
     *           comparisonClass: string, progressPct: number}}
     */
    function resolveTypingSpeedDisplay(stats) {
        stats = stats || {};
        var statsAvailable = !!stats.stats_available;
        var wpm            = statsAvailable ? Math.round(stats.avg_wpm || 0) : 0;
        var classAvgWpm    = statsAvailable ? Math.round(stats.class_avg_wpm || 0) : 0;
        var wpmDiff        = statsAvailable ? (parseFloat(stats.wpm_diff) || 0) : 0;

        var absDiff  = Math.abs(wpmDiff);
        var comparisonClass = absDiff < 5 ? 'deviation-low' : (absDiff < 15 ? 'deviation-medium' : 'deviation-high');
        var sign     = wpmDiff >= 0 ? '+' : '';

        var progressPct = classAvgWpm > 0
            ? Math.min(100, Math.round(wpm / (classAvgWpm * 2) * 100))
            : Math.min(100, wpm);

        return {
            statsAvailable: statsAvailable,
            displayValue: statsAvailable ? wpm : '—',
            wpmDiffFormatted: sign + wpmDiff.toFixed(1),
            classAvgWpm: classAvgWpm,
            comparisonClass: comparisonClass,
            progressPct: progressPct,
        };
    }

    return {
        formatDuration: formatDuration,
        resolveTypingSpeedDisplay: resolveTypingSpeedDisplay,
    };
});
