<?php

declare(strict_types=1);

/**
 * Practice insights: how busy the clinic is, what it treats, what it earns, who has gone quiet.
 * All dates are the doctor's local calendar days; the app sends `today` (Y-m-d) and `tz_offset` (minutes east of UTC).
 */
class InsightsController extends BaseController
{
    private const PERIODS = [7, 30, 90];
    private const QUIET_DAYS = 90;

    /** GET insights?days=7|30|90&today=YYYY-MM-DD&tz_offset=330 */
    public function index(): void
    {
        $d    = (int) $this->requireDoctor()['id'];
        $days = (int) $this->request->get('days', 30);
        if (!in_array($days, self::PERIODS, true)) $days = 30;
        $off  = max(-840, min(840, (int) $this->request->get('tz_offset', 0)));
        $t    = (string) $this->request->get('today', '');
        $today = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t . ' UTC') !== false ? $t : gmdate('Y-m-d', time() + $off * 60);

        $day   = fn (int $n) => gmdate('Y-m-d', strtotime($today . ' UTC') + $n * 86400);
        $utc   = fn (string $localDate) => gmdate('Y-m-d H:i:s', strtotime($localDate . ' 00:00:00 UTC') - $off * 60);   // local midnight → UTC
        $start = $day(-$days + 1);
        $pStart = $day(-2 * $days + 1);
        $from  = $utc($start);
        $to    = $utc($day(1));
        $pFrom = $utc($pStart);

        $cur  = $this->totals($d, $from, $to);
        $prev = $this->totals($d, $pFrom, $from);

        // Eight local weeks (Mon–Sun) ending with the current one, for the trend bars.
        $dow      = (int) gmdate('N', strtotime($today . ' UTC'));            // 1 = Monday
        $weekMon  = $day(-($dow - 1));
        $firstMon = gmdate('Y-m-d', strtotime($weekMon . ' UTC') - 7 * 7 * 86400);
        $wFrom    = $utc($firstMon);
        $buckets  = [];
        for ($i = 0; $i < 8; $i++) {
            $m = gmdate('Y-m-d', strtotime($firstMon . ' UTC') + $i * 7 * 86400);
            $buckets[$m] = ['week_start' => $m, 'visits' => 0, 'new_patients' => 0, 'collected' => 0.0];
        }
        $bucketOf = function (string $utcStamp) use ($off, $buckets): ?string {
            $local = strtotime($utcStamp . ' UTC') + $off * 60;
            $mon   = gmdate('Y-m-d', $local - ((int) gmdate('N', $local) - 1) * 86400);
            return isset($buckets[$mon]) ? $mon : null;
        };
        foreach ($this->db->getRows("SELECT visit_date AS ts FROM prescriptions WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t", [':d' => $d, ':f' => $wFrom, ':t' => $to]) as $r) {
            if (($k = $bucketOf($r['ts'])) !== null) $buckets[$k]['visits']++;
        }
        foreach ($this->db->getRows('SELECT created_at AS ts FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND created_at >= :f AND created_at < :t', [':d' => $d, ':f' => $wFrom, ':t' => $to]) as $r) {
            if (($k = $bucketOf($r['ts'])) !== null) $buckets[$k]['new_patients']++;
        }
        foreach ($this->db->getRows('SELECT paid_at AS ts, amount FROM bill_payments WHERE doctor_id = :d AND paid_at >= :f AND paid_at < :t', [':d' => $d, ':f' => $wFrom, ':t' => $to]) as $r) {
            if (($k = $bucketOf($r['ts'])) !== null) $buckets[$k]['collected'] += (float) $r['amount'];
        }

        // What the practice treats: the doctor's own wording, folded by case and spacing.
        $diag = $this->db->getRows(
            "SELECT MIN(TRIM(diagnosis)) AS name, COUNT(*) AS n FROM prescriptions
             WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t AND diagnosis IS NOT NULL AND TRIM(diagnosis) <> ''
             GROUP BY LOWER(TRIM(diagnosis)) ORDER BY n DESC, name LIMIT 6", [':d' => $d, ':f' => $from, ':t' => $to]);
        $meds = $this->db->getRows(
            "SELECT MIN(m.medicine_name) AS name, COUNT(DISTINCT m.prescription_id) AS n FROM prescription_medicines m
             JOIN prescriptions p ON p.id = m.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
             WHERE p.doctor_id = :d AND m.deleted_at IS NULL AND p.visit_date >= :f AND p.visit_date < :t
             GROUP BY LOWER(m.medicine_name) ORDER BY n DESC, name LIMIT 6", [':d' => $d, ':f' => $from, ':t' => $to]);

        // Busiest weekdays (local) over the period.
        $wd = array_fill(0, 7, 0);   // 0 = Sunday
        foreach ($this->db->getRows("SELECT visit_date AS ts FROM prescriptions WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t", [':d' => $d, ':f' => $from, ':t' => $to]) as $r) {
            $wd[(int) gmdate('w', strtotime($r['ts'] . ' UTC') + $off * 60)]++;
        }

        $sex = $this->db->getRows(
            "SELECT sex, COUNT(*) AS n FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND is_active = 1 GROUP BY sex", [':d' => $d]);

        // Chronic patients who have gone quiet: last final visit more than QUIET_DAYS ago (or never).
        $cut = gmdate('Y-m-d H:i:s', time() - self::QUIET_DAYS * 86400);
        $quiet = $this->db->getRows(
            "SELECT pt.id, pt.name, pt.mobile, pt.chronic_conditions, MAX(p.visit_date) AS last_visit
             FROM patients pt LEFT JOIN prescriptions p ON p.patient_id = pt.id AND p.doctor_id = pt.doctor_id AND p.status = 'final' AND p.deleted_at IS NULL
             WHERE pt.doctor_id = :d AND pt.deleted_at IS NULL AND pt.is_active = 1 AND pt.chronic_conditions IS NOT NULL AND pt.chronic_conditions <> ''
             GROUP BY pt.id HAVING MAX(p.visit_date) IS NULL OR MAX(p.visit_date) < :c ORDER BY MAX(p.visit_date) IS NULL, MAX(p.visit_date) ASC LIMIT 10", [':d' => $d, ':c' => $cut]);
        $quietCount = (int) $this->db->getRow(
            "SELECT COUNT(*) AS n FROM (SELECT pt.id, MAX(p.visit_date) AS lv FROM patients pt LEFT JOIN prescriptions p ON p.patient_id = pt.id AND p.doctor_id = pt.doctor_id AND p.status = 'final' AND p.deleted_at IS NULL
               WHERE pt.doctor_id = :d AND pt.deleted_at IS NULL AND pt.is_active = 1 AND pt.chronic_conditions IS NOT NULL AND pt.chronic_conditions <> '' GROUP BY pt.id HAVING MAX(p.visit_date) IS NULL OR MAX(p.visit_date) < :c) q",
            [':d' => $d, ':c' => $cut])['n'];

        Response::success([
            'days'         => $days,
            'range'        => ['from' => $start, 'to' => $today],
            'current'      => $cur,
            'previous'     => $prev,
            'weeks'        => array_values(array_map(fn ($b) => ['collected' => round($b['collected'], 2)] + $b, $buckets)),
            'diagnoses'    => array_map(fn ($r) => ['name' => $r['name'], 'count' => (int) $r['n']], $diag),
            'medicines'    => array_map(fn ($r) => ['name' => $r['name'], 'count' => (int) $r['n']], $meds),
            'weekdays'     => $wd,
            'sex'          => array_map(fn ($r) => ['sex' => $r['sex'], 'count' => (int) $r['n']], $sex),
            'quiet'        => [
                'days'  => self::QUIET_DAYS,
                'count' => $quietCount,
                'items' => array_map(fn ($r) => [
                    'patient_id' => (int) $r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'conditions' => $r['chronic_conditions'],
                    'last_visit' => $r['last_visit'], 'days_since' => $r['last_visit'] === null ? null : (int) floor((time() - strtotime($r['last_visit'] . ' UTC')) / 86400),
                ], $quiet),
            ],
        ], 'Insights fetched');
    }

    /** Headline numbers for [from, to) in UTC. */
    private function totals(int $d, string $from, string $to): array
    {
        $rx = $this->db->getRow(
            "SELECT COUNT(*) AS visits, COUNT(DISTINCT patient_id) AS patients FROM prescriptions
             WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t", [':d' => $d, ':f' => $from, ':t' => $to]);
        $np = $this->db->getRow('SELECT COUNT(*) AS n FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND created_at >= :f AND created_at < :t', [':d' => $d, ':f' => $from, ':t' => $to]);
        $ap = $this->db->getRow(
            "SELECT COALESCE(SUM(status = 'no_show'), 0) AS missed, COALESCE(SUM(status IN ('completed','no_show')), 0) AS due FROM appointments
             WHERE doctor_id = :d AND scheduled_at >= :f AND scheduled_at < :t", [':d' => $d, ':f' => $from, ':t' => $to]);
        $col = $this->db->getRow('SELECT COALESCE(SUM(amount), 0) AS n FROM bill_payments WHERE doctor_id = :d AND paid_at >= :f AND paid_at < :t', [':d' => $d, ':f' => $from, ':t' => $to]);
        $due = (int) $ap['due'];
        return [
            'visits'       => (int) $rx['visits'],
            'patients'     => (int) $rx['patients'],
            'new_patients' => (int) $np['n'],
            'collected'    => (float) $col['n'],
            'no_show_rate' => $due > 0 ? round((int) $ap['missed'] / $due * 100, 1) : null,
        ];
    }
}
