<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';
require_once ROOT_PATH . '/app/controllers/PatientController.php';

/**
 * AppointmentController — the day's queue and the booking diary.
 *
 * GET  /appointments?from=&to=&patient_id=&upcoming=1   → list()
 * POST /appointments                                    → create()   walk_in=true joins the queue now
 * PUT  /appointments/{id}                               → update()   reschedule / edit (not yet seen)
 * POST /appointments/{id}/status                        → setStatus()
 *
 * A walk-in is just an appointment that has already arrived, so one list
 * serves "who is waiting", "who is coming" and "who was seen". Finalising a
 * prescription linked to an appointment completes it (see PrescriptionController).
 */
class AppointmentController extends BaseController
{
    private const ACTIVE = ['scheduled', 'confirmed', 'arrived'];

    /** from-status => allowed next statuses */
    private const FLOW = [
        'scheduled' => ['confirmed', 'arrived', 'cancelled', 'no_show'],
        'confirmed' => ['arrived', 'cancelled', 'no_show'],
        'arrived'   => ['completed', 'cancelled'],
        'no_show'   => ['arrived'],            // turned up late
        'completed' => [],
        'cancelled' => [],
    ];

    // ── GET /appointments ────────────────────────────────────────────────────
    public function list(): void
    {
        $doctor = $this->requireClinic();
        $sql    = $this->baseSelect() . ' WHERE a.doctor_id = :d';
        $params = [':d' => $doctor['id']];

        $from = $this->utc($this->request->get('from'));
        $to   = $this->utc($this->request->get('to'));
        if ($from) { $sql .= ' AND a.scheduled_at >= :from'; $params[':from'] = $from; }
        if ($to)   { $sql .= ' AND a.scheduled_at < :to';    $params[':to']   = $to; }

        $pid = (int) $this->request->get('patient_id', 0);
        if ($pid > 0) { $sql .= ' AND a.patient_id = :pid'; $params[':pid'] = $pid; }

        if ($this->request->get('upcoming')) {
            $sql .= " AND a.status IN ('scheduled','confirmed') AND a.scheduled_at >= :now";
            $params[':now'] = gmdate('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY a.scheduled_at ASC, a.id ASC LIMIT 300';

        $rows = $this->db->getRows($sql, $params);
        Response::success(['items' => array_map([self::class, 'shape'], $rows)], 'Appointments fetched');
    }

    // ── POST /appointments ───────────────────────────────────────────────────
    public function create(): void
    {
        $doctor  = $this->requireClinic();
        $patient = $this->ownPatient((int) $this->request->get('patient_id'), (int) $doctor['id']);
        $walkIn  = filter_var($this->request->get('walk_in'), FILTER_VALIDATE_BOOLEAN);
        $in      = $this->parse($walkIn);

        $when = $walkIn ? time() : $in['ts'];
        $when = $walkIn ? gmdate('Y-m-d H:i:s', $when) : gmdate('Y-m-d H:i:s', $when);

        // The same person twice in one day is almost always a slip.
        if (!filter_var($this->request->get('force'), FILTER_VALIDATE_BOOLEAN)) {
            $dup = $this->db->getRow(
                $this->baseSelect() . " WHERE a.doctor_id = :d AND a.patient_id = :p AND a.status IN ('scheduled','confirmed','arrived')
                   AND ABS(TIMESTAMPDIFF(HOUR, a.scheduled_at, :w)) < 12 ORDER BY a.scheduled_at LIMIT 1",
                [':d' => $doctor['id'], ':p' => $patient['id'], ':w' => $when]
            );
            if ($dup) {
                $msg = $dup['status'] === 'arrived'
                    ? $patient['name'] . ' is already waiting in the queue.'
                    : $patient['name'] . ' already has an appointment around that time.';
                Response::send(409, ['existing' => self::shape($dup)], $msg, false);
            }
        }

        $clinic = $this->db->getRow('SELECT id FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $doctor['id']]);
        $id = $this->db->insert('appointments', [
            'doctor_id'    => $doctor['id'],
            'clinic_id'    => $clinic['id'] ?? null,
            'patient_id'   => $patient['id'],
            'scheduled_at' => $when,
            'duration_min' => $in['duration'],
            'reason'       => $in['reason'],
            'notes'        => $in['notes'],
            'status'       => $walkIn ? 'arrived' : 'scheduled',
        ]);
        $this->db->update('patients', ['updated_at' => CommonHelper::now()], 'id = :id', [':id' => $patient['id']]);

        Response::created(['appointment' => $this->load($id, (int) $doctor['id'])], $walkIn ? 'Added to the queue' : 'Appointment booked');
    }

    // ── PUT /appointments/{id} ───────────────────────────────────────────────
    public function update(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);
        if (!in_array($appt['status'], ['scheduled', 'confirmed'], true)) {
            Response::error('Only upcoming appointments can be changed.', 409);
        }
        $in = $this->parse(false);

        $this->db->update('appointments', [
            'scheduled_at' => gmdate('Y-m-d H:i:s', $in['ts']),
            'duration_min' => $in['duration'],
            'reason'       => $in['reason'],
            'notes'        => $in['notes'],
            'status'       => 'scheduled',            // a moved appointment needs confirming again
            'reminder_24h_sent' => 0, 'reminder_1h_sent' => 0, 'reminder_24h_at' => null, 'reminder_1h_at' => null,
        ], 'id = :id', [':id' => $id]);

        Response::success(['appointment' => $this->load($id, (int) $doctor['id'])], 'Appointment updated');
    }

    // ── POST /appointments/{id}/status ───────────────────────────────────────
    public function setStatus(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);

        $this->validate(['status' => 'required|in:scheduled,confirmed,arrived,completed,cancelled,no_show']);
        if ($this->isStaff() && $this->request->input('status') === 'completed') {
            Response::forbidden('A visit is completed when the doctor finishes the prescription.');
        }
        $next = (string) $this->request->get('status');
        if (!in_array($next, self::FLOW[$appt['status']] ?? [], true)) {
            Response::error("An appointment that is “{$appt['status']}” can’t be marked “{$next}”.", 409);
        }

        $data = ['status' => $next];
        if ($next === 'cancelled') {
            $reason = self::clean((string) ($this->request->get('cancel_reason') ?? ''));
            $data['cancelled_by'] = in_array($this->request->get('cancelled_by'), ['doctor', 'patient'], true) ? $this->request->get('cancelled_by') : 'doctor';
            $data['cancel_reason'] = $reason === '' ? null : mb_substr($reason, 0, 300);
        }
        $this->db->update('appointments', $data, 'id = :id', [':id' => $id]);

        Response::success(['appointment' => $this->load($id, (int) $doctor['id'])], 'Appointment updated');
    }

    // ── Input ────────────────────────────────────────────────────────────────
    private function parse(bool $walkIn): array
    {
        $this->validate([
            'reason'       => 'nullable|string|max:500',
            'notes'        => 'nullable|string|max:500',
            'duration_min' => 'nullable|integer',
        ]);

        $ts = null;
        if (!$walkIn) {
            $raw = trim((string) ($this->request->get('scheduled_at') ?? ''));
            $ts  = $raw === '' ? false : strtotime($raw . (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $raw) ? '' : ' UTC'));
            if ($ts === false) {
                Response::validationError(['scheduled_at' => ['Choose a date and time for the appointment.']]);
            }
            if ($ts < time() - 15 * 60) {
                Response::validationError(['scheduled_at' => ['That time has already passed.']]);
            }
            if ($ts > strtotime('+2 years')) {
                Response::validationError(['scheduled_at' => ['That date is too far ahead.']]);
            }
        }

        $dur = $this->request->get('duration_min');
        $dur = ($dur === null || $dur === '') ? 15 : (int) $dur;
        if ($dur < 5 || $dur > 180) {
            Response::validationError(['duration_min' => ['Duration must be between 5 and 180 minutes.']]);
        }
        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;

        return ['ts' => $ts, 'duration' => $dur, 'reason' => $opt('reason'), 'notes' => $opt('notes')];
    }

    private function utc(mixed $raw): ?string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') return null;
        $ts = strtotime($raw . (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $raw) ? '' : ' UTC'));
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    // ── Data ─────────────────────────────────────────────────────────────────
    private function ownPatient(int $id, int $doctorId): array
    {
        $p = $id > 0 ? $this->db->getRow('SELECT * FROM patients WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $id, ':d' => $doctorId]) : null;
        if (!$p) Response::validationError(['patient_id' => ['Choose a patient from your register.']]);
        return $p;
    }

    private function rowOrFail(int $id, int $doctorId): array
    {
        $r = $this->db->getRow('SELECT * FROM appointments WHERE id = :i AND doctor_id = :d', [':i' => $id, ':d' => $doctorId]);
        if (!$r) Response::notFound('Appointment not found.');
        return $r;
    }

    private function baseSelect(): string
    {
        return 'SELECT a.*, p.name AS patient_name, p.sex AS patient_sex, p.mobile AS patient_mobile, p.uhid AS patient_uhid,
                       p.age_years, p.date_of_birth, p.known_allergies
                FROM appointments a JOIN patients p ON p.id = a.patient_id';
    }

    private function load(int $id, int $doctorId): array
    {
        $r = $this->db->getRow($this->baseSelect() . ' WHERE a.id = :i AND a.doctor_id = :d', [':i' => $id, ':d' => $doctorId]);
        return self::shape($r);
    }

    private static function shape(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'status'          => $r['status'],
            'scheduled_at'    => $r['scheduled_at'],
            // When they checked in (the row's last change): drives "waiting 12 min".
            'arrived_at'      => $r['status'] === 'arrived' ? $r['updated_at'] : null,
            'duration_min'    => (int) $r['duration_min'],
            'reason'          => $r['reason'],
            'notes'           => $r['notes'],
            'cancel_reason'   => $r['cancel_reason'],
            'prescription_id' => $r['prescription_id'] === null ? null : (int) $r['prescription_id'],
            'patient'         => [
                'id' => (int) $r['patient_id'], 'name' => $r['patient_name'], 'sex' => $r['patient_sex'], 'uhid' => $r['patient_uhid'],
                'mobile' => $r['patient_mobile'], 'known_allergies' => $r['known_allergies'],
                'age_label' => PatientController::ageLabel(['date_of_birth' => $r['date_of_birth'], 'age_years' => $r['age_years']]),
            ],
        ];
    }
}
