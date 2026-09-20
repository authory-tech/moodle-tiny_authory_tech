/**
 * Pure, framework-independent display logic for the teacher writing-analytics
 * dashboard, extracted out of assign_dashboard.js so it can be unit-tested
 * directly (see dashboard_metrics.test.js) without a browser, jQuery, or a
 * running Moodle instance.
 *
 * UMD-wrapped: works as an AMD module (Moodle/RequireJS) and as a CommonJS
 * module (Jest), from the same source.
 *
 * @module     tiny_authory_tech/dashboard_metrics
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function(root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.tinyAuthoryTechDashboardMetrics = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    /**
     * Format a duration in seconds as a human-readable string.
     * Shows seconds when under a minute, otherwise whole minutes.
     * @param {number} seconds
     * @returns {string}
     */
    function formatDuration(seconds) {
        if (!seconds || seconds <= 0) {
            return '—';
        }
        if (seconds < 60) {
            return Math.round(seconds) + ' sec';
        }
        return Math.round(seconds / 60) + ' min';
    }

    /**
     * Resolves the "Typing Speed" card's display value and comparison text from
     * a raw stats object (the JSON response of authory_tech_get_student_stats).
     *
     * When stats.stats_available is false (type-server couldn't be reached, or
     * returned an error), avg_wpm/class_avg_wpm/wpm_diff are meaningless — this
     * renders an explicit "unavailable" state instead of a misleading "0 WPM".
     *
     * @param {Object} stats Raw stats object from authory_tech_get_student_stats
     * @returns {{statsAvailable: boolean, displayValue: (number|string),
     *           comparisonText: string, comparisonClass: string, progressPct: number}}
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
        var comparisonText = statsAvailable
            ? (sign + wpmDiff.toFixed(1) + ' WPM vs class average (' + classAvgWpm + ' WPM)')
            : 'Typing speed unavailable';

        var progressPct = classAvgWpm > 0
            ? Math.min(100, Math.round(wpm / (classAvgWpm * 2) * 100))
            : Math.min(100, wpm);

        return {
            statsAvailable: statsAvailable,
            displayValue: statsAvailable ? wpm : '—',
            comparisonText: comparisonText,
            comparisonClass: comparisonClass,
            progressPct: progressPct,
        };
    }

    return {
        formatDuration: formatDuration,
        resolveTypingSpeedDisplay: resolveTypingSpeedDisplay,
    };
}));
