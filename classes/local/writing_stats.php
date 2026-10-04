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

namespace tiny_authory_tech\local;

use curl;

/**
 * Writing-analytics helpers shared by the dashboard and replay web services.
 *
 * Combines type-server responses with statistics computed locally from the stored
 * keystroke content, so the dashboard still has data when the type-server is down.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class writing_stats {
    /**
     * Interprets type-server's raw HTTP response (curl itself already succeeded —
     * see the $result === false check in get_student_stats()) and merges in
     * Moodle-local fallback data. Pure and directly unit-testable: no curl, no DB.
     *
     * A non-2xx response (e.g. type-server's DB is unreachable) still comes back
     * as a decodable JSON body like {"error": "database error"} — decode it, but
     * don't let it masquerade as real stats. wpm/class_avg_wpm can only come from
     * type-server, so on failure they're dropped entirely rather than silently
     * rendering as 0; duration/typing/submission fields still come from the local
     * fallback since those don't depend on type-server at all.
     *
     * @param int $httpcode HTTP status code from the type-server call
     * @param string $rawresult Raw response body from type-server
     * @param array $localfallback keys: submission_text, filename, duration_seconds,
     *              typing_duration_seconds, pasted_texts
     * @return array The merged payload, ready for json_encode()
     */
    public static function build_student_stats_payload($httpcode, $rawresult, array $localfallback) {
        $decoded = json_decode($rawresult, true);
        $statsok = $httpcode === 200 && is_array($decoded) && !isset($decoded['error']);

        $merged = $statsok ? $decoded : [];
        $merged['stats_available'] = $statsok;

        $merged['submission_text'] = $localfallback['submission_text'] ?? '';
        $merged['filename']        = $localfallback['filename'] ?? '';
        if (empty($merged['duration_seconds']) && ($localfallback['duration_seconds'] ?? 0) > 0) {
            $merged['duration_seconds'] = $localfallback['duration_seconds'];
        }
        if (empty($merged['typing_duration_seconds']) && ($localfallback['typing_duration_seconds'] ?? 0) > 0) {
            $merged['typing_duration_seconds'] = $localfallback['typing_duration_seconds'];
        }
        $merged['pasted_texts'] = $localfallback['pasted_texts'] ?? [];

        return $merged;
    }

    /**
     * Compute assignment overview stats from tiny_authory_tech_files.content.
     * Used as fallback when the typeserver has no TimescaleDB data yet.
     *
     * @param int $cmid Course module ID
     * @param object $db Moodle $DB global
     * @return string JSON matching the typeserver /assignments/:cmid/stats shape
     */
    public static function compute_overview_stats_from_content($cmid, $db): string {
        $sql = 'SELECT id, userid, content
                  FROM {tiny_authory_tech_files}
                 WHERE cmid = :cmid AND content IS NOT NULL AND content != :empty
              ORDER BY userid ASC';
        $files = $db->get_records_sql($sql, ['cmid' => $cmid, 'empty' => '']);

        $students = [];
        foreach ($files as $file) {
            $events = json_decode($file->content, true);
            if (!is_array($events) || count($events) < 2) {
                continue;
            }
            $timestamps = array_values(array_filter(array_column($events, 't'), static fn($t) => $t > 0));
            if (count($timestamps) < 2) {
                continue;
            }
            sort($timestamps);
            // Gap-capped, same as compute_duration_from_content(): duration only counts
            // gaps up to the window-closed threshold (5 min), and wpm's denominator only
            // counts gaps up to the typing threshold (5 s) — a raw max-min span would fold
            // multi-session/multi-day breaks into both numbers.
            $sessionms = 0;
            $typingms  = 0;
            for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
                $gap = $timestamps[$i] - $timestamps[$i - 1];
                if ($gap > 0 && $gap < 300000) {
                    $sessionms += $gap;
                }
                if ($gap > 0 && $gap < 5000) {
                    $typingms += $gap;
                }
            }
            $sessionseconds = $sessionms / 1000;
            $typingseconds  = $typingms / 1000;
            if ($sessionseconds < 1) {
                continue;
            }
            $charsinserted = 0;
            foreach ($events as $ev) {
                foreach (($ev['d'] ?? []) as $op) {
                    if (!empty($op['insert'])) {
                        $charsinserted += mb_strlen($op['insert']);
                    }
                }
            }
            $wpm = $typingseconds < 1 ? 0 : $charsinserted / 5.0 / ($typingseconds / 60.0);
            $students[(int)$file->userid] = [
                'person_id'        => (int)$file->userid,
                'avg_wpm'          => round($wpm, 2),
                'duration_seconds' => round($sessionseconds, 1),
                'wpm_diff'         => '+0.00',
            ];
        }

        if (empty($students)) {
            return json_encode(['error' => 'No data available']);
        }

        $students = array_values($students);
        $count = count($students);
        $classavgwpm      = array_sum(array_column($students, 'avg_wpm')) / $count;
        $classavgduration = array_sum(array_column($students, 'duration_seconds')) / $count;

        foreach ($students as &$s) {
            $s['wpm_diff'] = sprintf('%+.2f', round($s['avg_wpm'] - $classavgwpm, 2));
        }
        unset($s);

        return json_encode([
            'cmid'                       => (int)$cmid,
            'class_avg_wpm'              => round($classavgwpm, 2),
            'class_avg_duration_seconds' => round($classavgduration, 1),
            'students'                   => $students,
        ]);
    }

    /**
     * Compute session and typing durations from delta events in tiny_authory_tech_files.content.
     *
     * Returns ['session' => int, 'typing' => int] both in whole seconds.
     * 'session' = sum of consecutive inter-event gaps up to the window-closed threshold
     * (5 min, matching windowClosedThresholdMs in type-server's analysis.go) — this
     * excludes multi-session/multi-day breaks between keystrokes, which a naive
     * max(t) - min(t) span would otherwise fold into "time spent."
     * 'typing'  = sum of consecutive inter-event gaps that are < 5 s (active keyboard time).
     * Returns ['session' => 0, 'typing' => 0] when data is missing or malformed.
     *
     * @param int $fileid tiny_authory_tech_files.id
     * @param object $db  Moodle $DB global
     * @return array{session:int,typing:int}
     */
    public static function compute_duration_from_content($fileid, $db): array {
        $rec = $db->get_record('tiny_authory_tech_files', ['id' => $fileid], 'content', IGNORE_MISSING);
        if (!$rec || empty($rec->content)) {
            return ['session' => 0, 'typing' => 0];
        }
        $events = json_decode($rec->content, true);
        if (!is_array($events) || count($events) < 2) {
            return ['session' => 0, 'typing' => 0];
        }
        $timestamps = array_values(array_filter(array_column($events, 't'), static fn($t) => $t > 0));
        if (count($timestamps) < 2) {
            return ['session' => 0, 'typing' => 0];
        }
        sort($timestamps);
        $sessionms = 0;
        $typingms = 0;
        for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
            $gap = $timestamps[$i] - $timestamps[$i - 1];
            if ($gap > 0 && $gap < 300000) {
                $sessionms += $gap;
            }
            if ($gap > 0 && $gap < 5000) {
                $typingms += $gap;
            }
        }
        return ['session' => intval($sessionms / 1000), 'typing' => intval($typingms / 1000)];
    }

    /**
     * Extract pasted text segments from stored delta events (same source as the replay).
     *
     * @param int $fileid tiny_authory_tech_files.id
     * @param object $db Moodle $DB global
     * @return string[] Pasted texts in chronological order
     */
    public static function compute_pastes_from_content($fileid, $db): array {
        $rec = $db->get_record('tiny_authory_tech_files', ['id' => $fileid], 'content', IGNORE_MISSING);
        if (!$rec || empty($rec->content)) {
            return [];
        }
        $events = json_decode($rec->content, true);
        if (!is_array($events)) {
            return [];
        }
        $pastes = [];
        foreach ($events as $ev) {
            if (($ev['meta'] ?? '') !== 'paste') {
                continue;
            }
            foreach (($ev['d'] ?? []) as $op) {
                if (!empty($op['insert']) && mb_strlen($op['insert']) > 1) {
                    $pastes[] = $op['insert'];
                    break;
                }
            }
        }
        return $pastes;
    }

    /**
     * Call typeserver /sessions/:fileid/replay-annotations and return decoded JSON.
     *
     * @param int $fileid tiny_authory_tech_files.id (equals typeserver resource_id)
     * @param object $db Moodle $DB global
     * @return object|null Decoded response or null on any failure
     */
    public static function fetch_typeserver_annotations($fileid, $db) {
        $secret    = get_config('tiny_authory_tech', 'secretkey');
        $serverurl = get_config('tiny_authory_tech', 'python_server');

        if (empty($secret) || empty($serverurl)) {
            return null;
        }

        $wstoken = get_config('tiny_authory_tech', 'authory_tech_token');
        if (empty($wstoken)) {
            $service = $db->get_record('external_services', ['shortname' => 'authory_tech_json_service']);
            if ($service) {
                $adminuser = get_admin();
                $dbtoken   = $db->get_record(
                    'external_tokens',
                    ['userid' => $adminuser->id, 'externalserviceid' => $service->id],
                    '*',
                    IGNORE_MULTIPLE
                );
                $wstoken = $dbtoken ? $dbtoken->token : '';
            }
        }

        if (empty($wstoken)) {
            return null;
        }

        $expires      = time() + 600;
        $sig          = hash_hmac('sha256', $wstoken . ':' . $expires, $secret);
        $teachertoken = $sig . ':' . $expires;

        $url  = rtrim($serverurl, '/') . '/sessions/' . intval($fileid) . '/replay-annotations';
        $curl = new curl();
        $opts = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_HTTPHEADER'     => [
                'X-Teacher-Token: ' . $teachertoken,
                'X-Ws-Token: ' . $wstoken,
                'Accept: application/json',
            ],
        ];

        $result = $curl->get($url, [], $opts);

        if ($result === false) {
            return null;
        }

        return json_decode($result);
    }
}
