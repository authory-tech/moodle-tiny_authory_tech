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

namespace tiny_authory_tech\tests; // phpcs:ignore moodle.PHPUnit.TestCaseNames.UnexpectedLevel2NS

/**
 * Unit tests for authory_tech_json_func_data's writing-analytics helpers.
 *
 * Covers two regressions that shipped to production with zero test coverage:
 *  - "Bug 2": get_student_stats() silently forwarded type-server's error
 *    responses as if they were valid stats (build_student_stats_payload).
 *  - "Bug 3": duration/typing-time were computed as a raw max(t)-min(t) span,
 *    ballooning to the full wall-clock gap between writing sessions instead of
 *    active engaged time (compute_duration_from_content,
 *    compute_overview_stats_from_content).
 *
 * @package    tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \authory_tech_json_func_data
 */
final class externallib_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/externallib.php');
    }

    /**
     * Invokes a private/protected static method via reflection.
     *
     * @param string $method Method name on authory_tech_json_func_data
     * @param array $args Positional arguments
     * @return mixed
     */
    private function call_private_static(string $method, array $args) {
        $ref = new \ReflectionMethod(\authory_tech_json_func_data::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    /**
     * Inserts a tiny_authory_tech_files row with the given delta-event timestamps
     * as its content, and returns the inserted id.
     *
     * @param int $userid
     * @param int $cmid
     * @param array $timestampsms Unix-ms timestamps, one per synthetic keystroke
     * @return int tiny_authory_tech_files.id
     */
    private function insert_file_with_events(int $userid, int $cmid, array $timestampsms): int {
        global $DB;
        $events = array_map(
            fn($t) => ['t' => $t, 'd' => [['insert' => 'a']], 'meta' => 'keystroke'],
            $timestampsms
        );
        return $DB->insert_record('tiny_authory_tech_files', (object) [
            'userid'       => $userid,
            'cmid'         => $cmid,
            'courseid'     => 1,
            'resourceid'   => 1,
            'filename'     => 'f.json',
            'content'      => json_encode($events),
            'timemodified' => time(),
            'uploaded'     => 0,
        ]);
    }

    // -------------------------------------------------------------------
    // build_student_stats_payload() — Bug 2: silent error-masking
    // -------------------------------------------------------------------

    public function test_payload_marks_stats_available_when_typeserver_succeeds(): void {
        $typeserver = json_encode(['avg_wpm' => 42.5, 'class_avg_wpm' => 38.0, 'wpm_diff' => '+4.50']);
        $merged = \authory_tech_json_func_data::build_student_stats_payload(200, $typeserver, [
            'submission_text'         => 'hello',
            'filename'                => 'f.json',
            'duration_seconds'        => 100,
            'typing_duration_seconds' => 50,
            'pasted_texts'            => [],
        ]);

        $this->assertTrue($merged['stats_available']);
        $this->assertSame(42.5, $merged['avg_wpm']);
        $this->assertSame('hello', $merged['submission_text']);
        $this->assertSame(
            100,
            $merged['duration_seconds'],
            'type-server did not send its own duration, so the local fallback should be used'
        );
    }

    public function test_payload_marks_stats_unavailable_on_typeserver_error_response(): void {
        // This is the exact live bug: type-server's DB was down and returned
        // {"error": "database error"} with HTTP 500 — it must not be treated as
        // valid stats just because the body decodes to an array.
        $typeserver = json_encode(['error' => 'database error']);
        $merged = \authory_tech_json_func_data::build_student_stats_payload(500, $typeserver, [
            'submission_text'         => 'hello',
            'filename'                => 'f.json',
            'duration_seconds'        => 92,
            'typing_duration_seconds' => 3,
            'pasted_texts'            => [],
        ]);

        $this->assertFalse($merged['stats_available']);
        $this->assertArrayNotHasKey('avg_wpm', $merged);
        $this->assertSame(
            92,
            $merged['duration_seconds'],
            'duration must still come from the local fallback even when type-server fails'
        );
        $this->assertSame(3, $merged['typing_duration_seconds']);
    }

    public function test_payload_treats_non_200_as_unavailable_even_without_error_key(): void {
        // Belt-and-suspenders: even a 200-shaped body arriving with a non-200
        // status must not be trusted.
        $typeserver = json_encode(['avg_wpm' => 99]);
        $merged = \authory_tech_json_func_data::build_student_stats_payload(503, $typeserver, [
            'submission_text' => '', 'filename' => '', 'duration_seconds' => 0,
            'typing_duration_seconds' => 0, 'pasted_texts' => [],
        ]);
        $this->assertFalse($merged['stats_available']);
    }

    public function test_payload_falls_back_when_typeserver_duration_is_zero(): void {
        // empty(0) is true in PHP — a type-server response with duration_seconds
        // explicitly 0 must still fall back to the local value, not keep the 0.
        $typeserver = json_encode(['avg_wpm' => 10, 'duration_seconds' => 0]);
        $merged = \authory_tech_json_func_data::build_student_stats_payload(200, $typeserver, [
            'submission_text' => '', 'filename' => '', 'duration_seconds' => 77,
            'typing_duration_seconds' => 0, 'pasted_texts' => [],
        ]);
        $this->assertSame(77, $merged['duration_seconds']);
    }

    public function test_payload_always_uses_local_pasted_texts(): void {
        // "Always use local content as source of truth for paste texts" —
        // type-server's own value, if any, must never win.
        $typeserver = json_encode(['avg_wpm' => 10, 'pasted_texts' => ['from type-server']]);
        $merged = \authory_tech_json_func_data::build_student_stats_payload(200, $typeserver, [
            'submission_text' => '', 'filename' => '', 'duration_seconds' => 0,
            'typing_duration_seconds' => 0, 'pasted_texts' => ['local paste'],
        ]);
        $this->assertSame(['local paste'], $merged['pasted_texts']);
    }

    // -------------------------------------------------------------------
    // compute_duration_from_content() — Bug 3: raw span vs. gap-capped time
    // -------------------------------------------------------------------

    public function test_compute_duration_normal_typing_counts_toward_both(): void {
        $this->resetAfterTest();
        global $DB;
        $base = 1_700_000_000_000;
        // 3 gaps of 2s each — well under both the 5s typing cap and 300s session cap.
        $id = $this->insert_file_with_events(2, 1, [$base, $base + 2000, $base + 4000, $base + 6000]);

        $result = $this->call_private_static('compute_duration_from_content', [$id, $DB]);
        $this->assertEquals(6, $result['session']);
        $this->assertEquals(6, $result['typing']);
    }

    public function test_compute_duration_within_session_pause_counts_toward_session_not_typing(): void {
        $this->resetAfterTest();
        global $DB;
        $base = 1_700_000_000_000;
        // A single 90s pause: over the 5s typing cap, under the 300s session cap.
        $id = $this->insert_file_with_events(2, 1, [$base, $base + 90_000]);

        $result = $this->call_private_static('compute_duration_from_content', [$id, $DB]);
        $this->assertEquals(90, $result['session']);
        $this->assertEquals(0, $result['typing']);
    }

    public function test_compute_duration_multi_day_gap_does_not_balloon_session(): void {
        $this->resetAfterTest();
        global $DB;
        $base = 1_700_000_000_000;
        $twodaysms = 2 * 24 * 3600 * 1000;
        // Two 3s gaps of real typing, separated by a 2-day gap (student closed
        // the tab and came back later). A naive max(t)-min(t) span would report
        // ~172806 seconds here.
        $id = $this->insert_file_with_events(2, 1, [
            $base,
            $base + 3000,
            $base + 3000 + $twodaysms,
            $base + 3000 + $twodaysms + 3000,
        ]);

        $result = $this->call_private_static('compute_duration_from_content', [$id, $DB]);
        $this->assertEquals(6, $result['session'], 'only the two 3s gaps should count; the 2-day gap must be excluded');
        $this->assertLessThan(300, $result['session']);
    }

    public function test_compute_duration_returns_zero_for_missing_content(): void {
        $this->resetAfterTest();
        global $DB;
        $id = $DB->insert_record('tiny_authory_tech_files', (object) [
            'userid' => 2, 'cmid' => 1, 'courseid' => 1, 'resourceid' => 1,
            'filename' => 'f.json', 'content' => null,
            'timemodified' => time(), 'uploaded' => 0,
        ]);
        $result = $this->call_private_static('compute_duration_from_content', [$id, $DB]);
        $this->assertSame(['session' => 0, 'typing' => 0], $result);
    }

    // -------------------------------------------------------------------
    // compute_overview_stats_from_content() — Bug 3 at the class-overview level
    // -------------------------------------------------------------------

    public function test_compute_overview_stats_multi_day_gap_does_not_balloon_class_average(): void {
        $this->resetAfterTest();
        global $DB;
        $cmid = 55;
        $base = 1_700_000_000_000;
        $twodaysms = 2 * 24 * 3600 * 1000;

        // Student A: normal typing only.
        $this->insert_file_with_events(2001, $cmid, [$base, $base + 2000, $base + 4000]);
        // Student B: same small-gap typing, but with a 2-day gap in the middle.
        $this->insert_file_with_events(2002, $cmid, [
            $base, $base + 2000, $base + 2000 + $twodaysms, $base + 2000 + $twodaysms + 2000,
        ]);

        $result = $this->call_private_static('compute_overview_stats_from_content', [$cmid, $DB]);
        $decoded = json_decode($result, true);

        $this->assertLessThan(
            300,
            $decoded['class_avg_duration_seconds'],
            "class average must not be dragged up by student B's multi-day gap"
        );
    }
}
