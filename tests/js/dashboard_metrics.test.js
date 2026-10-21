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
 * Jest tests for the pure display logic in amd/src/dashboard_metrics.js.
 *
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// dashboard_metrics.js is a plain AMD module (as Moodle's build expects), so give it
// a minimal define() that hands back its factory's exports.
let exported;
global.define = (deps, factory) => {
    exported = factory();
};
require('../../amd/src/dashboard_metrics');
delete global.define;
const {formatDuration, resolveTypingSpeedDisplay} = exported;

const UNITS = {seconds: '{$a} sec', minutes: '{$a} min'};

describe('formatDuration', () => {
    test('zero or negative seconds render as an em dash', () => {
        expect(formatDuration(0, UNITS)).toBe('—');
        expect(formatDuration(-5, UNITS)).toBe('—');
    });

    test('under a minute renders as whole seconds', () => {
        expect(formatDuration(45, UNITS)).toBe('45 sec');
    });

    test('uses the localised unit templates it is given', () => {
        expect(formatDuration(30, {seconds: '{$a} s', minutes: '{$a} min'})).toBe('30 s');
        expect(formatDuration(180, {seconds: '{$a} s', minutes: '{$a} minuts'})).toBe('3 minuts');
    });

    test('a minute or more renders as whole minutes', () => {
        expect(formatDuration(60, UNITS)).toBe('1 min');
        expect(formatDuration(125, UNITS)).toBe('2 min');
    });
});

describe('resolveTypingSpeedDisplay', () => {
    test('stats_available true renders the real WPM and comparison figures', () => {
        const result = resolveTypingSpeedDisplay({
            stats_available: true,
            avg_wpm: 42.4,
            class_avg_wpm: 38,
            wpm_diff: '+4.40',
        });
        expect(result.statsAvailable).toBe(true);
        expect(result.displayValue).toBe(42);
        // Raw pieces, not a pre-built sentence — the caller (which has access to
        // core/str) composes the translatable comparison text from these.
        expect(result.wpmDiffFormatted).toBe('+4.4');
        expect(result.classAvgWpm).toBe(38);
    });

    // This is the exact live bug: type-server's DB was down and returned an
    // error response — the dashboard must show "unavailable", never a
    // misleading "0 words per minute".
    test('stats_available false renders an em dash, never 0', () => {
        const result = resolveTypingSpeedDisplay({
            stats_available: false,
        });
        expect(result.statsAvailable).toBe(false);
        expect(result.displayValue).toBe('—');
        expect(result.displayValue).not.toBe(0);
    });

    test('missing stats_available field is treated as unavailable', () => {
        const result = resolveTypingSpeedDisplay({avg_wpm: 50});
        expect(result.statsAvailable).toBe(false);
        expect(result.displayValue).toBe('—');
    });

    test('undefined stats object is treated as unavailable, not a crash', () => {
        const result = resolveTypingSpeedDisplay(undefined);
        expect(result.statsAvailable).toBe(false);
        expect(result.displayValue).toBe('—');
    });

    test('deviation class reflects how far wpm is from the class average', () => {
        const low = resolveTypingSpeedDisplay({stats_available: true, avg_wpm: 40, class_avg_wpm: 38, wpm_diff: '+2.00'});
        const medium = resolveTypingSpeedDisplay({stats_available: true, avg_wpm: 48, class_avg_wpm: 38, wpm_diff: '+10.00'});
        const high = resolveTypingSpeedDisplay({stats_available: true, avg_wpm: 58, class_avg_wpm: 38, wpm_diff: '+20.00'});
        expect(low.comparisonClass).toBe('deviation-low');
        expect(medium.comparisonClass).toBe('deviation-medium');
        expect(high.comparisonClass).toBe('deviation-high');
    });

    test('progress bar is capped at 100% even for a wpm far above 2x class average', () => {
        const result = resolveTypingSpeedDisplay({
            stats_available: true, avg_wpm: 500, class_avg_wpm: 40, wpm_diff: '+460.00',
        });
        expect(result.progressPct).toBe(100);
    });

    test('progress bar with no class average falls back to raw wpm capped at 100', () => {
        const result = resolveTypingSpeedDisplay({stats_available: true, avg_wpm: 55, class_avg_wpm: 0, wpm_diff: '+0.00'});
        expect(result.progressPct).toBe(55);
    });
});
