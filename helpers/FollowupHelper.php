<?php

declare(strict_types=1);

/**
 * FollowupHelper — works out who needs the doctor's attention today.
 *
 * Five lists, all derived from what is already recorded (nothing to maintain by hand):
 *   followups        a final prescription's follow-up date is near/past and the patient has not been seen since
 *   refills          a chronic patient's medicines have run (or are about to run) out and they have not been seen since
 *   results_to_review a lab / imaging result is in but not yet reviewed
 *   pending_results  tests ordered a few days ago with no result yet
 *   missed           a booked patient did not come and has not been rebooked or seen
 *
 * Items the doctor snoozed or dismissed (followup_actions) are left out.
 */
class FollowupHelper
{
    public const PENDING_DAYS        = 3;   // routine orders waiting this long are "chasing" candidates
    public const PENDING_DAYS_URGENT = 1;
    public const REFILL_LEAD_DAYS    = 3;   // flag medicines this many days before they run out
    public const LOOKBACK_DAYS       = 45;

    /** @param string $today doctor's local date (Y-m-d) @return array<string,array> */
    public static function compute(int $doctorId, string $today): array
    {
        $db   = Database::getInstance();
        $now  = gmdate('Y-m-d H:i:s');
        $t    = strtotime($today . ' 00:00:00 UTC');
        $day  = fn (int $off) => gmdate('Y-m-d', $t + $off * 86400);

        $patientCols = 'pt.name AS pt_name, pt.sex AS pt_sex, pt.mobile AS pt_mobile, pt.uhid AS pt_uhid, pt.age_years, pt.date_of_birth, pt.known_allergies';
        $notSnoozed  = fn (string $kind, string $idCol) =>
            "NOT EXISTS (SELECT 1 FROM followup_actions f WHERE f.doctor_id = :d_$kind AND f.kind = '$kind' AND f.reference_id = $idCol AND (f.until IS NULL OR f.until > :now_$kind))";
        $laterVisit  = "NOT EXISTS (SELECT 1 FROM prescriptions q WHERE q.doctor_id = p.doctor_id AND q.patient_id = p.patient_id
                         AND q.status = 'final' AND q.deleted_at IS NULL AND q.visit_date > p.visit_date)";

        // ── follow-ups ──
        $rows = $db->getRows(
            "SELECT p.id, p.patient_id, p.visit_date, p.followup_date, p.followup_note, p.diagnosis, $patientCols,
                    (SELECT MAX(w.sent_at) FROM wa_notifications w WHERE w.doctor_id = p.doctor_id AND w.reference_type = 'prescription'
                       AND w.reference_id = p.id AND w.template_name = 'followup') AS reminded_at,
                    (SELECT MIN(a.scheduled_at) FROM appointments a WHERE a.doctor_id = p.doctor_id AND a.patient_id = p.patient_id
                       AND a.status IN ('scheduled','confirmed','arrived') AND a.scheduled_at >= p.visit_date) AS booked_at
             FROM prescriptions p JOIN patients pt ON pt.id = p.patient_id AND pt.deleted_at IS NULL
             WHERE p.doctor_id = :d AND p.status = 'final' AND p.deleted_at IS NULL AND p.followup_date IS NOT NULL
               AND p.followup_date BETWEEN :from AND :to AND $laterVisit AND " . $notSnoozed('followup', 'p.id') . "
             ORDER BY p.followup_date ASC LIMIT 100",
            [':d' => $doctorId, ':from' => $day(-self::LOOKBACK_DAYS), ':to' => $day(7), ':d_followup' => $doctorId, ':now_followup' => $now]
        );
        $followups = [];
        foreach ($rows as $r) {
            $diff   = (int) round(($t - strtotime($r['followup_date'] . ' 00:00:00 UTC')) / 86400);  // >0 = overdue
            $followups[] = self::item('followup', (int) $r['id'], $r, [
                'title'           => $diff > 0 ? "Follow-up was due " . self::ago($diff) : ($diff === 0 ? 'Follow-up due today' : 'Follow-up due in ' . (-$diff) . ' day' . ($diff === -1 ? '' : 's')),
                'detail'          => trim(($r['diagnosis'] ?? '') . (!empty($r['followup_note']) ? ' · ' . $r['followup_note'] : ''), ' ·'),
                'due_date'        => $r['followup_date'],
                'days'            => $diff,
                'bucket'          => $diff > 0 ? 'overdue' : ($diff === 0 ? 'today' : 'soon'),
                'booked_at'       => $r['booked_at'],
                'reminded_at'     => $r['reminded_at'],
                'prescription_id' => (int) $r['id'],
            ]);
        }

        // ── refills (chronic patients whose supply has run out) ──
        $rows = $db->getRows(
            "SELECT p.id, p.patient_id, p.visit_date, $patientCols, pt.chronic_conditions AS pt_chronic,
                    (SELECT MAX(pm.duration_days) FROM prescription_medicines pm WHERE pm.prescription_id = p.id AND pm.deleted_at IS NULL
                       AND pm.duration_days IS NOT NULL AND pm.times_per_day > 0) AS span,
                    (SELECT MAX(w.sent_at) FROM wa_notifications w WHERE w.doctor_id = p.doctor_id AND w.reference_type = 'prescription'
                       AND w.reference_id = p.id AND w.template_name = 'refill') AS reminded_at,
                    (SELECT MIN(a.scheduled_at) FROM appointments a WHERE a.doctor_id = p.doctor_id AND a.patient_id = p.patient_id
                       AND a.status IN ('scheduled','confirmed','arrived') AND a.scheduled_at >= p.visit_date) AS booked_at
             FROM prescriptions p JOIN patients pt ON pt.id = p.patient_id AND pt.deleted_at IS NULL
             WHERE p.doctor_id = :d AND p.status = 'final' AND p.deleted_at IS NULL AND p.visit_date >= :since
               AND pt.chronic_conditions IS NOT NULL AND pt.chronic_conditions <> '' AND $laterVisit AND " . $notSnoozed('refill', 'p.id') . "
             ORDER BY p.visit_date ASC LIMIT 300",
            [':d' => $doctorId, ':since' => $day(-150) . ' 00:00:00', ':d_refill' => $doctorId, ':now_refill' => $now]
        );
        $refills = [];
        foreach ($rows as $r) {
            if ($r['span'] === null) continue;
            $runOut = gmdate('Y-m-d', strtotime(substr($r['visit_date'], 0, 10) . ' UTC') + (int) $r['span'] * 86400);
            $diff   = (int) round(($t - strtotime($runOut . ' 00:00:00 UTC')) / 86400);   // >0 = already out
            if ($diff < -self::REFILL_LEAD_DAYS || $diff > 60) continue;
            $names = array_column($db->getRows(
                'SELECT medicine_name FROM prescription_medicines WHERE prescription_id = :p AND deleted_at IS NULL AND times_per_day > 0 ORDER BY sort_order, id LIMIT 4',
                [':p' => $r['id']]
            ), 'medicine_name');
            $refills[] = self::item('refill', (int) $r['id'], $r, [
                'title'           => $diff > 0 ? 'Medicines ran out ' . self::ago($diff) : ($diff === 0 ? 'Medicines run out today' : 'Medicines run out in ' . (-$diff) . ' day' . ($diff === -1 ? '' : 's')),
                'detail'          => implode(', ', $names),
                'due_date'        => $runOut,
                'days'            => $diff,
                'bucket'          => $diff > 0 ? 'overdue' : ($diff === 0 ? 'today' : 'soon'),
                'booked_at'       => $r['booked_at'],
                'reminded_at'     => $r['reminded_at'],
                'prescription_id' => (int) $r['id'],
                'conditions'      => $r['pt_chronic'],
            ]);
        }
        usort($refills, fn ($a, $b) => $b['days'] <=> $a['days']);

        // ── results waiting for the doctor's eyes ──
        $toReview = [];
        foreach ($db->getRows(
            "SELECT o.id, o.patient_id, o.result_date, o.order_date, $patientCols FROM lab_orders o
             JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
             JOIN patients pt ON pt.id = o.patient_id AND pt.deleted_at IS NULL
             WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND o.status = 'report_ready' ORDER BY o.result_date ASC LIMIT 100", [':d' => $doctorId]) as $r) {
            $s     = InvestigationHelper::shapeLab(['id' => $r['id']] + $db->getRow('SELECT * FROM lab_orders WHERE id = :i', [':i' => $r['id']]));
            $names = array_column($db->getRows('SELECT COALESCE(c.name, t.custom_test) n FROM lab_order_tests t LEFT JOIN lab_tests_catalog c ON c.id = t.test_id WHERE t.lab_order_id = :o ORDER BY t.id LIMIT 3', [':o' => $r['id']]), 'n');
            $when  = $r['result_date'] ?? $r['order_date'];
            $toReview[] = self::item('lab_review', (int) $r['id'], $r, [
                'title' => $s['abnormal_count'] > 0 ? "Result in: {$s['abnormal_count']} abnormal" : 'Result in, not yet reviewed',
                'detail' => implode(', ', $names) . ($s['tests_count'] > 3 ? ' +' . ($s['tests_count'] - 3) . ' more' : ''),
                'due_date' => substr($when, 0, 10), 'days' => (int) round(($t - strtotime(substr($when, 0, 10) . ' 00:00:00 UTC')) / 86400),
                'bucket' => $s['abnormal_count'] > 0 ? 'overdue' : 'today', 'abnormal_count' => $s['abnormal_count'], 'order_kind' => 'lab',
            ]);
        }
        foreach ($db->getRows(
            "SELECT o.id, o.patient_id, o.result_date, o.created_at, o.modality, o.body_part, $patientCols FROM radiology_orders o
             JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
             JOIN patients pt ON pt.id = o.patient_id AND pt.deleted_at IS NULL
             WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND o.status = 'report_ready' ORDER BY o.result_date ASC LIMIT 100", [':d' => $doctorId]) as $r) {
            $when = $r['result_date'] ?? $r['created_at'];
            $toReview[] = self::item('imaging_review', (int) $r['id'], $r, [
                'title' => 'Report in, not yet reviewed',
                'detail' => trim((InvestigationHelper::MODALITY_LABELS[$r['modality']] ?? 'Imaging') . ' ' . ($r['body_part'] ?? '')),
                'due_date' => substr($when, 0, 10), 'days' => (int) round(($t - strtotime(substr($when, 0, 10) . ' 00:00:00 UTC')) / 86400),
                'bucket' => 'today', 'order_kind' => 'imaging',
            ]);
        }
        usort($toReview, fn ($a, $b) => ($b['abnormal_count'] ?? 0) <=> ($a['abnormal_count'] ?? 0) ?: $b['days'] <=> $a['days']);

        // ── ordered a while ago, still nothing back ──
        $pending = [];
        $routineCut = gmdate('Y-m-d H:i:s', time() - self::PENDING_DAYS * 86400);
        $urgentCut  = gmdate('Y-m-d H:i:s', time() - self::PENDING_DAYS_URGENT * 86400);
        foreach ($db->getRows(
            "SELECT o.id, o.patient_id, o.order_date, o.urgency, $patientCols FROM lab_orders o
             JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
             JOIN patients pt ON pt.id = o.patient_id AND pt.deleted_at IS NULL
             WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND o.status IN ('ordered','sample_collected','processing')
               AND o.order_date <= IF(o.urgency = 'routine', :rc, :uc) AND " . $notSnoozed('lab_pending', 'o.id') . " ORDER BY o.order_date ASC LIMIT 100",
            [':d' => $doctorId, ':rc' => $routineCut, ':uc' => $urgentCut, ':d_lab_pending' => $doctorId, ':now_lab_pending' => $now]) as $r) {
            $names = array_column($db->getRows('SELECT COALESCE(c.name, t.custom_test) n FROM lab_order_tests t LEFT JOIN lab_tests_catalog c ON c.id = t.test_id WHERE t.lab_order_id = :o ORDER BY t.id LIMIT 3', [':o' => $r['id']]), 'n');
            $days = (int) floor((time() - strtotime($r['order_date'] . ' UTC')) / 86400);
            $pending[] = self::item('lab_pending', (int) $r['id'], $r, [
                'title' => "Lab result pending for $days day" . ($days === 1 ? '' : 's'), 'detail' => implode(', ', $names),
                'due_date' => substr($r['order_date'], 0, 10), 'days' => $days, 'bucket' => $r['urgency'] !== 'routine' ? 'overdue' : 'soon', 'order_kind' => 'lab', 'urgency' => $r['urgency'],
            ]);
        }
        foreach ($db->getRows(
            "SELECT o.id, o.patient_id, o.created_at, o.urgency, o.modality, o.body_part, $patientCols FROM radiology_orders o
             JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
             JOIN patients pt ON pt.id = o.patient_id AND pt.deleted_at IS NULL
             WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND o.status = 'ordered'
               AND o.created_at <= IF(o.urgency = 'routine', :rc, :uc) AND " . $notSnoozed('imaging_pending', 'o.id') . " ORDER BY o.created_at ASC LIMIT 100",
            [':d' => $doctorId, ':rc' => $routineCut, ':uc' => $urgentCut, ':d_imaging_pending' => $doctorId, ':now_imaging_pending' => $now]) as $r) {
            $days = (int) floor((time() - strtotime($r['created_at'] . ' UTC')) / 86400);
            $pending[] = self::item('imaging_pending', (int) $r['id'], $r, [
                'title' => "Report pending for $days day" . ($days === 1 ? '' : 's'),
                'detail' => trim((InvestigationHelper::MODALITY_LABELS[$r['modality']] ?? 'Imaging') . ' ' . ($r['body_part'] ?? '')),
                'due_date' => substr($r['created_at'], 0, 10), 'days' => $days, 'bucket' => $r['urgency'] !== 'routine' ? 'overdue' : 'soon', 'order_kind' => 'imaging', 'urgency' => $r['urgency'],
            ]);
        }
        usort($pending, fn ($a, $b) => $b['days'] <=> $a['days']);

        // ── booked but did not come ──
        $rows = $db->getRows(
            "SELECT a.id, a.patient_id, a.scheduled_at, a.reason, $patientCols,
                    (SELECT MAX(w.sent_at) FROM wa_notifications w WHERE w.doctor_id = a.doctor_id AND w.reference_type = 'appointment'
                       AND w.reference_id = a.id AND w.template_name = 'missed') AS reminded_at
             FROM appointments a JOIN patients pt ON pt.id = a.patient_id AND pt.deleted_at IS NULL
             WHERE a.doctor_id = :d AND a.status = 'no_show' AND a.scheduled_at >= :since
               AND NOT EXISTS (SELECT 1 FROM appointments b WHERE b.doctor_id = a.doctor_id AND b.patient_id = a.patient_id AND b.id <> a.id
                               AND b.status IN ('scheduled','confirmed','arrived','completed') AND b.scheduled_at > a.scheduled_at)
               AND NOT EXISTS (SELECT 1 FROM prescriptions q WHERE q.doctor_id = a.doctor_id AND q.patient_id = a.patient_id AND q.status = 'final'
                               AND q.deleted_at IS NULL AND q.visit_date > a.scheduled_at)
               AND " . $notSnoozed('missed', 'a.id') . " ORDER BY a.scheduled_at DESC LIMIT 100",
            [':d' => $doctorId, ':since' => gmdate('Y-m-d H:i:s', time() - 14 * 86400), ':d_missed' => $doctorId, ':now_missed' => $now]
        );
        $missed = [];
        foreach ($rows as $r) {
            $days = (int) floor((time() - strtotime($r['scheduled_at'] . ' UTC')) / 86400);
            $missed[] = self::item('missed', (int) $r['id'], $r, [
                'title' => 'Missed appointment ' . ($days <= 0 ? 'today' : self::ago($days)), 'detail' => $r['reason'] ?? '',
                'due_date' => substr($r['scheduled_at'], 0, 10), 'days' => $days, 'bucket' => 'overdue', 'reminded_at' => $r['reminded_at'],
            ]);
        }

        $attention = count(array_filter($followups, fn ($i) => $i['bucket'] !== 'soon' && !$i['booked_at']))
                   + count(array_filter($refills, fn ($i) => $i['bucket'] !== 'soon' && !$i['booked_at']))
                   + count($toReview) + count($pending) + count($missed);

        return [
            'followups' => $followups, 'refills' => $refills, 'results_to_review' => $toReview, 'pending_results' => $pending, 'missed' => $missed,
            'attention' => $attention,
        ];
    }

    private static function ago(int $days): string
    {
        return $days === 1 ? 'yesterday' : ($days < 14 ? "$days days ago" : (int) round($days / 7) . ' weeks ago');
    }

    /** Common envelope: kind, id, patient, plus the item-specific fields. */
    private static function item(string $kind, int $id, array $row, array $extra): array
    {
        return array_merge([
            'kind'    => $kind,
            'ref_id'  => $id,
            'patient' => [
                'id' => (int) $row['patient_id'], 'name' => $row['pt_name'], 'sex' => $row['pt_sex'], 'uhid' => $row['pt_uhid'],
                'mobile' => $row['pt_mobile'], 'known_allergies' => $row['known_allergies'],
                'age_label' => PatientController::ageLabel(['date_of_birth' => $row['date_of_birth'], 'age_years' => $row['age_years']]),
            ],
            'detail' => '', 'due_date' => null, 'days' => 0, 'bucket' => 'soon', 'booked_at' => null, 'reminded_at' => null,
            'prescription_id' => null, 'order_kind' => null, 'abnormal_count' => 0, 'urgency' => null, 'conditions' => null,
        ], $extra);
    }
}
