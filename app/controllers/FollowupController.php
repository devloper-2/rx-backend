<?php

declare(strict_types=1);

class FollowupController extends BaseController
{
    private const SNOOZABLE = ['followup', 'refill', 'missed', 'lab_pending', 'imaging_pending'];

    /** What the reminder is attached to: kind => [wa reference_type, template, owner table, patient column source] */
    private const REMIND = [
        'followup' => ['prescription', 'followup', 'prescriptions'],
        'refill'   => ['prescription', 'refill', 'prescriptions'],
        'missed'   => ['appointment', 'missed', 'appointments'],
    ];

    /** GET followups?today=YYYY-MM-DD (the doctor's local date) */
    public function index(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        Response::success(FollowupHelper::compute($d, $this->today()), 'Follow-ups fetched');
    }

    /** POST followups/dismiss {kind, ref_id, days?} — hide an item (days omitted = for good). */
    public function dismiss(): void
    {
        $d    = (int) $this->requireDoctor()['id'];
        $kind = (string) $this->request->input('kind', '');
        $ref  = (int) $this->request->input('ref_id', 0);
        $days = $this->request->input('days');
        $err  = [];
        if (!in_array($kind, self::SNOOZABLE, true)) $err['kind'] = 'Unknown kind';
        if ($ref <= 0) $err['ref_id'] = 'ref_id is required';
        if ($days !== null && (!is_numeric($days) || (int) $days < 1 || (int) $days > 365)) $err['days'] = 'days must be 1–365';
        if ($err) Response::validationError($err);
        if (!$this->owns($kind, $ref, $d)) Response::error('Not found', 404);

        $until = $days === null ? null : gmdate('Y-m-d H:i:s', time() + (int) $days * 86400);
        $this->db->executeQuery(
            'INSERT INTO followup_actions (doctor_id, kind, reference_id, until) VALUES (:d, :k, :r, :u)
             ON DUPLICATE KEY UPDATE until = VALUES(until), created_at = UTC_TIMESTAMP()',
            [':d' => $d, ':k' => $kind, ':r' => $ref, ':u' => $until]
        );
        Response::success(['kind' => $kind, 'ref_id' => $ref, 'until' => $until], $until ? 'Snoozed' : 'Dismissed');
    }

    /** POST reminders {kind, ref_id, message} — log a reminder the doctor sent (WhatsApp is opened by the app). */
    public function remind(): void
    {
        $d    = (int) $this->requireDoctor()['id'];
        $kind = (string) $this->request->input('kind', '');
        $ref  = (int) $this->request->input('ref_id', 0);
        $msg  = trim((string) $this->request->input('message', ''));
        $err  = [];
        if (!isset(self::REMIND[$kind])) $err['kind'] = 'Reminders can be sent for follow-ups, refills and missed visits';
        if ($ref <= 0) $err['ref_id'] = 'ref_id is required';
        if ($msg === '' || mb_strlen($msg) > 1000) $err['message'] = 'Message is required (max 1000 characters)';
        if ($err) Response::validationError($err);

        [$type, $template, $table] = self::REMIND[$kind];
        $row = $this->db->getRow(
            "SELECT t.patient_id, pt.mobile FROM $table t JOIN patients pt ON pt.id = t.patient_id
             WHERE t.id = :i AND t.doctor_id = :d" . ($table === 'prescriptions' ? ' AND t.deleted_at IS NULL' : ''),
            [':i' => $ref, ':d' => $d]
        );
        if (!$row) Response::error('Not found', 404);
        if (empty($row['mobile'])) Response::error('This patient has no mobile number', 422);

        $this->db->executeQuery(
            "INSERT INTO wa_notifications (doctor_id, patient_id, reference_type, reference_id, template_name, mobile, message_body, status, sent_at)
             VALUES (:d, :p, :t, :r, :tpl, :m, :b, 'sent', UTC_TIMESTAMP())",
            [':d' => $d, ':p' => $row['patient_id'], ':t' => $type, ':r' => $ref, ':tpl' => $template, ':m' => substr((string) $row['mobile'], 0, 15), ':b' => $msg]
        );
        Response::success(['kind' => $kind, 'ref_id' => $ref, 'reminded_at' => gmdate('Y-m-d H:i:s')], 'Reminder logged');
    }

    private function today(): string
    {
        $t = (string) $this->request->get('today', '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) !== false ? $t : gmdate('Y-m-d');
    }

    private function owns(string $kind, int $ref, int $d): bool
    {
        $table = match ($kind) {
            'followup', 'refill' => 'prescriptions',
            'missed'             => 'appointments',
            'lab_pending'        => 'lab_orders',
            default              => 'radiology_orders',
        };
        return (bool) $this->db->getRow("SELECT id FROM $table WHERE id = :i AND doctor_id = :d", [':i' => $ref, ':d' => $d]);
    }
}
