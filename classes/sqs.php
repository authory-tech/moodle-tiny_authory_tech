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
 * Minimal SigV4 signer for Scaleway SQS-compatible API.
 * Implements only SendMessage — no Composer dependency required.
 *
 * @package   tiny_authory_tech
 * @copyright 2026 SEPTUM QA <info@authory.tech>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tiny_authory_tech;

/**
 * Lightweight AWS SigV4 client for sending SQS messages without a Composer dependency.
 */
class sqs {
    /**
     * Publish a JSON message to an SQS queue using AWS SigV4 authentication.
     *
     * @param string $queueurl  Full SQS queue URL (e.g. https://sqs.mnq.fr-par.scaleway.com/queue-name)
     * @param string $accesskey SQS access key (QUEUE_ACCESS_KEY)
     * @param string $secretkey SQS secret key (QUEUE_SECRET_KEY)
     * @param string $body      Message body — must be a valid UTF-8 string (JSON)
     * @return bool True on success, false on failure
     */
    public static function send_message(string $queueurl, string $accesskey, string $secretkey, string $body): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $parsed    = parse_url($queueurl);
        $host      = $parsed['host'];
        $path      = $parsed['path'] ?? '/';
        $region    = self::extract_region($host);

        $now       = new \DateTime('now', new \DateTimeZone('UTC'));
        $amzdate   = $now->format('Ymd\THis\Z');
        $datestamp = $now->format('Ymd');

        $postbody    = 'Action=SendMessage&MessageBody=' . rawurlencode($body);
        $payloadhash = hash('sha256', $postbody);

        // Canonical headers must be lowercase, sorted, each ending with \n.
        $canonicalheaders =
            "content-type:application/x-www-form-urlencoded\n" .
            "host:{$host}\n" .
            "x-amz-date:{$amzdate}\n";
        $signedheaders = 'content-type;host;x-amz-date';

        $canonicalrequest = implode("\n", [
            'POST',
            $path,
            '', // Empty canonical query string.
            $canonicalheaders, // Already ends with \n, so this adds a blank separator line.
            $signedheaders,
            $payloadhash,
        ]);

        $credentialscope = "{$datestamp}/{$region}/sqs/aws4_request";
        $stringtosign    = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzdate,
            $credentialscope,
            hash('sha256', $canonicalrequest),
        ]);

        $signingkey = self::derive_signing_key($secretkey, $datestamp, $region, 'sqs');
        $signature  = hash_hmac('sha256', $stringtosign, $signingkey);

        $authorization =
            "AWS4-HMAC-SHA256 Credential={$accesskey}/{$credentialscope}, " .
            "SignedHeaders={$signedheaders}, Signature={$signature}";

        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/x-www-form-urlencoded',
                "X-Amz-Date: {$amzdate}",
                "Authorization: {$authorization}",
            ],
        ];

        $result   = $curl->post($queueurl, $postbody, $options);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($result === false) {
            \debugging("[tiny_authory_tech] SQS curl error: {$curl->error}", DEBUG_DEVELOPER);
            return false;
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            \debugging("[tiny_authory_tech] SQS HTTP {$httpcode}: {$result}", DEBUG_DEVELOPER);
            return false;
        }

        return true;
    }

    /**
     * Derive an AWS SigV4 signing key via HMAC chaining.
     *
     * @param string $secret  SQS secret key
     * @param string $date    UTC date string in Ymd format
     * @param string $region  AWS/Scaleway region (e.g. fr-par)
     * @param string $service Service name (e.g. sqs)
     * @return string Raw binary HMAC signing key
     */
    private static function derive_signing_key(string $secret, string $date, string $region, string $service): string {
        $kdate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kregion  = hash_hmac('sha256', $region, $kdate, true);
        $kservice = hash_hmac('sha256', $service, $kregion, true);
        return      hash_hmac('sha256', 'aws4_request', $kservice, true);
    }

    /**
     * Extract the region from a Scaleway or AWS SQS host.
     *
     * @param string $host Hostname from the queue URL
     * @return string Region string, defaults to fr-par
     */
    private static function extract_region(string $host): string {
        if (preg_match('/sqs\.mnq\.([^.]+)\.scaleway\.com/', $host, $m)) {
            return $m[1];
        }
        if (preg_match('/sqs\.([^.]+)\.amazonaws\.com/', $host, $m)) {
            return $m[1];
        }
        return 'fr-par';
    }
}
