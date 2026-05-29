<?php
/**
 * Event digest (directive STR-LOG §"SMTP digest").
 *
 * A scheduled sweep (daily ~05:00, after the daily report) that emails a
 * scrubbed summary of failed/degraded trunk events since the last run.
 *
 * Design constraints (non-negotiable):
 *   - OUT OF THE HOT PATH. Sending happens only in this scheduled sweep,
 *     NEVER synchronously inside MealsDB_Event_Log::record(). A mail
 *     hiccup must not stall checkout or cron.
 *   - The digest leaves the server, so it carries only already-scrubbed
 *     fields (message/context were PII-scrubbed at write time; we add no
 *     raw values here).
 *   - It logs its OWN run to the trunk (category='job', event='event_digest').
 *
 * Recipients reuse the daily report's recipient option so the operator
 * configures one list.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Event_Digest {

    public const HOOK     = 'mealsdb_event_digest';
    public const JOB_NAME = 'event_digest';

    /** Watermark of the last digest's window end (UTC datetime). */
    public const OPT_LAST_RUN = 'mealsdb_event_digest_last_run';

    /**
     * Severity threshold for the per-event detail list. Below this, events
     * are only summarised as a count. Default error+critical detail, with
     * a degraded/warning summary count.
     */
    public const OPT_MIN_SEVERITY = 'mealsdb_event_digest_min_severity';

    private const SEVERITY_RANK = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5,
    ];

    public static function register_hooks(): void {
        add_action(self::HOOK, [self::class, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            // 05:00 site time — after the 04:00 daily report and 04:30
            // retention prune, so the window is settled before we sweep.
            wp_schedule_event(strtotime('tomorrow 05:00:00'), 'daily', self::HOOK);
        }
    }

    /**
     * Sweep failed/degraded events since the last watermark and, if any,
     * email a scrubbed digest. Always advances the watermark and logs its
     * own run.
     */
    public static function run(): void {
        $log_id = MealsDB_Job_Logger::start(self::JOB_NAME);
        try {
            $now_utc   = gmdate('Y-m-d H:i:s');
            $since_utc = (string) get_option(self::OPT_LAST_RUN, '');
            if ($since_utc === '') {
                // First run: look back 24h rather than the whole table.
                $since_utc = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
            }

            $events = MealsDB_Event_Log::query([
                'outcome' => [MealsDB_Event_Log::OUTCOME_FAILED, MealsDB_Event_Log::OUTCOME_DEGRADED],
                'since'   => $since_utc,
                'until'   => $now_utc,
                'limit'   => 1000,
            ]);

            $sent = false;
            if (!empty($events)) {
                $sent = self::send_digest($events, $since_utc, $now_utc);
            }

            // Advance the watermark regardless of send success — a stuck
            // mailer must not cause the same events to be re-sent forever.
            update_option(self::OPT_LAST_RUN, $now_utc, false);

            MealsDB_Job_Logger::finish($log_id, [
                'records_processed' => count($events),
                'mail_sent'         => $sent ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            // Do NOT rethrow — a digest failure is not worth flagging the
            // WP-Cron event as broken; the failure is already on the trunk.
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private static function send_digest(array $events, string $since_utc, string $until_utc): bool {
        $recipients = self::recipients();
        if (empty($recipients)) {
            return false;
        }

        $min_rank = self::min_severity_rank();

        $failed    = 0;
        $degraded  = 0;
        $detail    = [];
        foreach ($events as $e) {
            if (($e['outcome'] ?? '') === MealsDB_Event_Log::OUTCOME_FAILED) {
                $failed++;
            } else {
                $degraded++;
            }
            $rank = self::SEVERITY_RANK[(string) ($e['severity'] ?? 'info')] ?? 1;
            if ($rank >= $min_rank) {
                // message/context were scrubbed at write time; safe to include.
                $detail[] = sprintf(
                    '[%s] %s/%s %s (%s) — %s',
                    (string) ($e['occurred_at'] ?? ''),
                    (string) ($e['category'] ?? ''),
                    (string) ($e['subsystem'] ?? '-'),
                    (string) ($e['event'] ?? ''),
                    strtoupper((string) ($e['outcome'] ?? '')),
                    (string) ($e['message'] ?? '')
                );
            }
        }

        $blogname = function_exists('get_bloginfo') ? wp_specialchars_decode((string) get_bloginfo('name')) : 'Meals DB';
        $subject  = sprintf('[Meals DB] %d failed / %d degraded events', $failed, $degraded);

        $lines   = [];
        $lines[] = sprintf('%s — operational event digest', $blogname);
        $lines[] = sprintf('Window (UTC): %s → %s', $since_utc, $until_utc);
        $lines[] = '';
        $lines[] = sprintf('Failed:   %d', $failed);
        $lines[] = sprintf('Degraded: %d', $degraded);
        $lines[] = '';
        if (!empty($detail)) {
            $lines[] = 'Detail (at or above the configured severity threshold):';
            $lines[] = str_repeat('-', 60);
            // Cap the body so a flood doesn't produce a multi-megabyte mail.
            $shown = array_slice($detail, 0, 200);
            foreach ($shown as $d) {
                $lines[] = $d;
            }
            if (count($detail) > count($shown)) {
                $lines[] = sprintf('... and %d more (see the Event Log dashboard).', count($detail) - count($shown));
            }
        } else {
            $lines[] = 'No events at or above the detail severity threshold; counts only.';
        }
        $lines[] = '';
        $lines[] = 'Review in WP Admin → Meals DB → Event Log.';

        $body = implode("\n", $lines);

        $ok = false;
        foreach ($recipients as $to) {
            if (wp_mail($to, $subject, $body)) {
                $ok = true;
            }
        }
        return $ok;
    }

    /**
     * @return string[] Validated recipient addresses.
     */
    private static function recipients(): array {
        $raw = (string) get_option(MealsDB_Daily_Report::OPT_RECIPIENTS, '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $c) {
            $c = trim($c);
            if ($c !== '' && is_email($c)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private static function min_severity_rank(): int {
        $sev = (string) get_option(self::OPT_MIN_SEVERITY, 'error');
        return self::SEVERITY_RANK[$sev] ?? self::SEVERITY_RANK['error'];
    }
}
