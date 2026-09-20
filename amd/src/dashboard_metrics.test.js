const {formatDuration, resolveTypingSpeedDisplay} = require('./dashboard_metrics');

describe('formatDuration', () => {
    test('zero or negative seconds render as an em dash', () => {
        expect(formatDuration(0)).toBe('—');
        expect(formatDuration(-5)).toBe('—');
    });

    test('under a minute renders as whole seconds', () => {
        expect(formatDuration(45)).toBe('45 sec');
    });

    test('a minute or more renders as whole minutes', () => {
        expect(formatDuration(60)).toBe('1 min');
        expect(formatDuration(125)).toBe('2 min');
    });
});

describe('resolveTypingSpeedDisplay', () => {
    test('stats_available true renders the real WPM and comparison', () => {
        const result = resolveTypingSpeedDisplay({
            stats_available: true,
            avg_wpm: 42.4,
            class_avg_wpm: 38,
            wpm_diff: '+4.40',
        });
        expect(result.statsAvailable).toBe(true);
        expect(result.displayValue).toBe(42);
        expect(result.comparisonText).toBe('+4.4 WPM vs class average (38 WPM)');
    });

    // This is the exact live bug: type-server's DB was down and returned an
    // error response — the dashboard must show "unavailable", never a
    // misleading "0 words per minute".
    test('stats_available false renders an em dash and an unavailable message, never 0', () => {
        const result = resolveTypingSpeedDisplay({
            stats_available: false,
        });
        expect(result.statsAvailable).toBe(false);
        expect(result.displayValue).toBe('—');
        expect(result.displayValue).not.toBe(0);
        expect(result.comparisonText).toBe('Typing speed unavailable');
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
