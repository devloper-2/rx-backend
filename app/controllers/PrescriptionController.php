<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';
require_once ROOT_PATH . '/app/controllers/PatientController.php';

/**
 * PrescriptionController — write, find and manage prescriptions.
 *
 * POST   /prescriptions                       → create()     status draft | final
 * GET    /prescriptions?since=&page=          → list()       recent, newest first
 * GET    /prescriptions/{id}                  → detail()
 * PUT    /prescriptions/{id}                  → update()     drafts only
 * DELETE /prescriptions/{id}                  → delete()     drafts only
 * POST   /prescriptions/{id}/finalize         → finalize()
 * POST   /prescriptions/{id}/cancel           → cancel()     final → cancelled
 * GET    /patients/{id}/prescriptions         → forPatient()
 *
 * A finalised prescription is a medical record: it cannot be edited, only
 * cancelled (and replaced by a new one).
 */
class PrescriptionController extends BaseController
{
    private const SLOTS = ['morning', 'afternoon', 'evening', 'night'];
    private const FOOD  = ['before', 'after', 'with'];
    private const ADVICE_CATS = ['diet', 'activity', 'sleep', 'hygiene', 'wound_care', 'other'];
    private const SEVERITY = ['mild', 'moderate', 'severe'];

    /** column => [min, max, integer?] */
    private const VITALS = [
        'weight_kg'        => [0.3, 500, false],
        'height_cm'        => [20, 250, false],
        'bp_systolic'      => [40, 300, true],
        'bp_diastolic'     => [20, 200, true],
        'pulse_bpm'        => [20, 250, true],
        'temperature_f'    => [90, 110, false],
        'spo2_pct'         => [50, 100, true],
        'rbs_mg_dl'        => [10, 1500, true],
        'respiratory_rate' => [4, 80, true],
    ];

    // ── POST /prescriptions ──────────────────────────────────────────────────
    public function create(): void
    {
        $doctor  = $this->requireDoctor();
        $patient = $this->ownPatient((int) $this->request->get('patient_id'), (int) $doctor['id']);
        $in      = $this->parse();
        $status  = $this->request->get('status', 'final');
        if (!in_array($status, ['draft', 'final'], true)) {
            Response::validationError(['status' => ['Status must be draft or final.']]);
        }
        if ($status === 'final') $this->assertHasContent($in);

        $appointment = $this->ownAppointment((int) $this->request->get('appointment_id'), (int) $doctor['id'], (int) $patient['id']);
        $clinic = $this->primaryClinic((int) $doctor['id']);
        $this->db->beginTransaction();
        try {
            $id = $this->db->insert('prescriptions', [
                'doctor_id'  => $doctor['id'],
                'clinic_id'  => $clinic['id'] ?? null,
                'patient_id' => $patient['id'],
                'visit_date' => $in['visit_date'],
                'status'     => $status,
            ] + $in['row']);
            $this->writeChildren($id, $in);
            InvestigationHelper::save($id, (int) $doctor['id'], (int) $patient['id'], $in['investigations']);
            $this->touchPatient((int) $patient['id']);
            if ($appointment) {
                $this->db->update('appointments', ['prescription_id' => $id], 'id = :a', [':a' => $appointment['id']]);
            }
            if ($status === 'final') $this->completeAppointment($id);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        Response::created(['prescription' => $this->load($id, (int) $doctor['id'])], $status === 'final' ? 'Prescription saved' : 'Draft saved');
    }

    // ── PUT /prescriptions/{id} ──────────────────────────────────────────────
    public function update(array $params): void
    {
        $doctor = $this->requireDoctor();
        $id     = (int) $params['id'];
        $rx     = $this->rowOrFail($id, (int) $doctor['id']);
        $this->assertDraft($rx);

        $in = $this->parse();
        // The editor sends status=final when the doctor finishes a draft: save and finalise as one step.
        $status = $this->request->get('status', 'draft');
        if (!in_array($status, ['draft', 'final'], true)) {
            Response::validationError(['status' => ['Status must be draft or final.']]);
        }
        if ($status === 'final') $this->assertHasContent($in);

        $this->db->beginTransaction();
        try {
            $this->db->update('prescriptions', ['visit_date' => $in['visit_date'], 'status' => $status] + $in['row'], 'id = :id', [':id' => $id]);
            $this->clearChildren($id);
            $this->writeChildren($id, $in);
            InvestigationHelper::save($id, (int) $doctor['id'], (int) $rx['patient_id'], $in['investigations']);
            $this->touchPatient((int) $rx['patient_id']);
            if ($status === 'final') $this->completeAppointment($id);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        Response::success(['prescription' => $this->load($id, (int) $doctor['id'])], $status === 'final' ? 'Prescription saved' : 'Draft saved');
    }

    // ── POST /prescriptions/{id}/finalize ────────────────────────────────────
    public function finalize(array $params): void
    {
        $doctor = $this->requireDoctor();
        $id     = (int) $params['id'];
        $rx     = $this->rowOrFail($id, (int) $doctor['id']);
        $this->assertDraft($rx);

        $full = $this->load($id, (int) $doctor['id']);
        if (!$full['diagnosis'] && !$full['chief_complaint'] && !$full['medicines'] && !$full['investigations']['lab'] && !$full['investigations']['imaging']) {
            Response::validationError(['prescription' => ['Add a diagnosis, complaint or at least one medicine first.']]);
        }
        $this->db->update('prescriptions', ['status' => 'final'], 'id = :id', [':id' => $id]);
        $this->touchPatient((int) $rx['patient_id']);
        $this->completeAppointment($id);
        Response::success(['prescription' => $this->load($id, (int) $doctor['id'])], 'Prescription saved');
    }

    // ── POST /prescriptions/{id}/cancel ──────────────────────────────────────
    public function cancel(array $params): void
    {
        $doctor = $this->requireDoctor();
        $id     = (int) $params['id'];
        $rx     = $this->rowOrFail($id, (int) $doctor['id']);
        if ($rx['status'] !== 'final') {
            Response::error('Only a finalised prescription can be cancelled.', 409);
        }
        $this->db->update('prescriptions', ['status' => 'cancelled'], 'id = :id', [':id' => $id]);
        InvestigationHelper::cancelFor($id);
        Response::success(['prescription' => $this->load($id, (int) $doctor['id'])], 'Prescription cancelled');
    }

    // ── DELETE /prescriptions/{id} ───────────────────────────────────────────
    public function delete(array $params): void
    {
        $doctor = $this->requireDoctor();
        $id     = (int) $params['id'];
        $rx     = $this->rowOrFail($id, (int) $doctor['id']);
        $this->assertDraft($rx, 'Only drafts can be deleted. Cancel a finalised prescription instead.');
        $this->db->update('prescriptions', ['deleted_at' => CommonHelper::now()], 'id = :id', [':id' => $id]);
        InvestigationHelper::cancelFor($id);
        Response::success([], 'Draft deleted');
    }

    // ── GET /prescriptions/{id} ──────────────────────────────────────────────
    public function detail(array $params): void
    {
        $doctor = $this->requireDoctor();
        $this->rowOrFail((int) $params['id'], (int) $doctor['id']);
        Response::success(['prescription' => $this->load((int) $params['id'], (int) $doctor['id'])], 'Prescription fetched');
    }

    // ── GET /prescriptions ───────────────────────────────────────────────────
    public function list(): void
    {
        $doctor = $this->requireDoctor();
        $sql    = 'SELECT p.*, pt.name AS patient_name, pt.sex AS patient_sex, pt.age_years, pt.date_of_birth, pt.uhid
                   FROM prescriptions p JOIN patients pt ON pt.id = p.patient_id
                   WHERE p.doctor_id = :d AND p.deleted_at IS NULL';
        $params = [':d' => $doctor['id']];

        $since = trim((string) $this->request->get('since', ''));
        if ($since !== '' && ($ts = strtotime($since)) !== false) {
            $sql .= ' AND p.visit_date >= :since';
            $params[':since'] = gmdate('Y-m-d H:i:s', $ts);
        }
        $status = $this->request->get('status');
        if (in_array($status, ['draft', 'final', 'cancelled'], true)) {
            $sql .= ' AND p.status = :st';
            $params[':st'] = $status;
        }
        $sql .= ' ORDER BY p.visit_date DESC, p.id DESC';

        $page = $this->db->paginate($sql, $params, $this->getPage(), $this->getLimit(20, 50));
        Response::success([
            'items'      => $this->summaries($page['data'], true),
            'pagination' => $page['pagination'],
        ], 'Prescriptions fetched');
    }

    // ── GET /patients/{id}/prescriptions ─────────────────────────────────────
    public function forPatient(array $params): void
    {
        $doctor  = $this->requireDoctor();
        $patient = $this->ownPatient((int) $params['id'], (int) $doctor['id']);
        $page = $this->db->paginate(
            'SELECT p.*, pt.name AS patient_name, pt.sex AS patient_sex, pt.age_years, pt.date_of_birth, pt.uhid
             FROM prescriptions p JOIN patients pt ON pt.id = p.patient_id
             WHERE p.doctor_id = :d AND p.patient_id = :p AND p.deleted_at IS NULL
             ORDER BY p.visit_date DESC, p.id DESC',
            [':d' => $doctor['id'], ':p' => $patient['id']],
            $this->getPage(), $this->getLimit(20, 50)
        );
        Response::success([
            'items'      => $this->summaries($page['data'], false),
            'pagination' => $page['pagination'],
        ], 'Prescriptions fetched');
    }

    // ── Input ────────────────────────────────────────────────────────────────
    /** Validate the request body; returns DB row + child arrays. Aborts with 422 on bad input. */
    private function parse(): array
    {
        $row = [];
        $err = [];

        foreach (self::VITALS as $col => [$min, $max, $int]) {
            $raw = $this->request->get($col);
            if ($raw === null || $raw === '') { $row[$col] = null; continue; }
            if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
                $err[$col] = ["Enter a value between {$min} and {$max}."];
                continue;
            }
            $row[$col] = $int ? (int) round((float) $raw) : round((float) $raw, 1);
        }
        if (($row['bp_systolic'] ?? null) !== null && ($row['bp_diastolic'] ?? null) !== null && $row['bp_diastolic'] >= $row['bp_systolic']) {
            $err['bp_diastolic'] = ['Diastolic must be lower than systolic.'];
        }
        $row['bmi'] = (!empty($row['weight_kg']) && !empty($row['height_cm']))
            ? round($row['weight_kg'] / (($row['height_cm'] / 100) ** 2), 2) : null;

        $text = function (string $k, int $max) use (&$err) { return $this->limited($k, $max, $err); };
        $row['chief_complaint'] = $text('chief_complaint', 2000);
        $row['diagnosis']       = $text('diagnosis', 2000);
        $row['clinical_notes']  = $text('clinical_notes', 4000);
        $row['followup_note']   = $text('followup_note', 500);

        $fu = trim((string) ($this->request->get('followup_date') ?? ''));
        if ($fu === '') {
            $row['followup_date'] = null;
        } elseif (($t = strtotime($fu)) === false || $t < strtotime('-1 day')) {
            $err['followup_date'] = ['Choose a follow-up date from today onwards.'];
        } elseif ($t > strtotime('+5 years')) {
            $err['followup_date'] = ['That follow-up date is too far away.'];
        } else {
            $row['followup_date'] = date('Y-m-d', $t);
        }

        $visit = trim((string) ($this->request->get('visit_date') ?? ''));
        $vt    = $visit === '' ? time() : strtotime($visit);
        if ($vt === false || $vt > time() + 86400) {
            $err['visit_date'] = ['Visit date cannot be in the future.'];
            $vt = time();
        }

        $meds    = $this->parseMedicines($err);
        $symp    = $this->parseSymptoms($err);
        $advice  = $this->parseAdvice($err);
        $inv     = InvestigationHelper::parse($this->request->get('investigations'), $err);

        if ($err) Response::validationError($err);

        return ['row' => $row, 'visit_date' => gmdate('Y-m-d H:i:s', $vt), 'medicines' => $meds, 'symptoms' => $symp, 'advice' => $advice, 'investigations' => $inv];
    }

    private function limited(string $key, int $max, array &$err): ?string
    {
        $v = trim(strip_tags((string) ($this->request->get($key) ?? '')));
        // keep line breaks for notes, but collapse spaces/tabs
        $v = trim(preg_replace("/[ \t]+/u", ' ', $v));
        if (mb_strlen($v) > $max) {
            $err[$key] = ["Please keep this under {$max} characters."];
            return null;
        }
        return $v === '' ? null : $v;
    }

    private function parseMedicines(array &$err): array
    {
        $raw = $this->request->get('medicines', []);
        if (!is_array($raw)) { $err['medicines'] = ['Medicines must be a list.']; return []; }
        if (count($raw) > 30) { $err['medicines'] = ['Too many medicines (max 30).']; return []; }

        $out = [];
        foreach (array_values($raw) as $i => $m) {
            $k = "medicines.$i";
            if (!is_array($m)) { $err[$k] = ['Invalid medicine.']; continue; }

            $name = self::clean((string) ($m['medicine_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 200) { $err[$k] = ['Each medicine needs a name (max 200 characters).']; continue; }

            [$slots, $bad] = $this->normaliseSchedule($m['dose_schedule'] ?? []);
            if ($bad) { $err[$k] = [$bad]; continue; }

            $days = $m['duration_days'] ?? null;
            if ($days !== null && $days !== '') {
                if (!is_numeric($days) || (int) $days < 1 || (int) $days > 3650) { $err[$k] = ['Duration must be 1–3650 days.']; continue; }
                $days = (int) $days;
            } else {
                $days = null;
            }

            $perDay = array_sum(array_column($slots, 'qty'));
            $total  = $m['total_qty'] ?? null;
            $total  = (is_numeric($total) && (float) $total >= 0 && (float) $total <= 99999) ? (float) $total
                    : ($days !== null && $perDay > 0 ? round($perDay * $days, 2) : 0);

            $freq = $slots ? self::freqLabel($slots) : (self::clean((string) ($m['freq_display'] ?? '')) ?: 'SOS');
            $medId = isset($m['medicine_id']) && (int) $m['medicine_id'] > 0 ? (int) $m['medicine_id'] : null;
            $catId = null;
            if ($medId !== null) {
                $cat = $this->db->getRow('SELECT category_id FROM medicines WHERE id = :i AND (doctor_id IS NULL OR doctor_id = :d)', [':i' => $medId, ':d' => $this->authUser['doctor_id']]);
                if (!$cat) { $medId = null; } else { $catId = (int) $cat['category_id']; }
            }

            $out[] = [
                'medicine_id'   => $medId,
                'medicine_name' => $name,
                'category_id'   => $catId,
                'total_qty'     => $total,
                'qty_unit'      => mb_substr(self::clean((string) ($m['qty_unit'] ?? 'piece')) ?: 'piece', 0, 20),
                'duration_days' => $days,
                'duration_note' => ($n = self::clean((string) ($m['duration_note'] ?? ''))) === '' ? null : mb_substr($n, 0, 100),
                'dose_schedule' => json_encode($slots, JSON_UNESCAPED_UNICODE),
                // times_per_day is a generated column (JSON_LENGTH of dose_schedule): never written.
                'freq_display'  => mb_substr($freq, 0, 50),
                'instructions'  => ($n = self::clean((string) ($m['instructions'] ?? ''))) === '' ? null : mb_substr($n, 0, 500),
                'is_substitutable' => !empty($m['is_substitutable']) ? 1 : 0,
                'sort_order'    => min(255, $i),
            ];
        }
        return $out;
    }

    /** @return array{0: array, 1: ?string} cleaned slots, error message */
    private function normaliseSchedule(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) return [[], null];
        if (!is_array($raw)) return [[], 'Dose schedule must be a list.'];

        $slots = [];
        $seen  = [];
        foreach ($raw as $s) {
            if (!is_array($s)) return [[], 'Invalid dose slot.'];
            $slot = $s['slot'] ?? null;
            if (!in_array($slot, self::SLOTS, true) || isset($seen[$slot])) return [[], 'Each time of day can be used once.'];
            $seen[$slot] = true;
            $qty = $s['qty'] ?? null;
            if (!is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 100) return [[], 'Dose quantity must be more than 0.'];
            $food = $s['food_relation'] ?? null;
            $o = ['slot' => $slot, 'qty' => round((float) $qty, 2) + 0];
            if (in_array($food, self::FOOD, true)) $o['food_relation'] = $food;
            if (!empty($s['unit'])) $o['unit'] = mb_substr(self::clean((string) $s['unit']), 0, 20);
            $slots[] = $o;
        }
        usort($slots, fn ($a, $b) => array_search($a['slot'], self::SLOTS) <=> array_search($b['slot'], self::SLOTS));
        return [$slots, null];
    }

    /** "1-0-1" (morning-afternoon-night), or four digits when "evening" is used. */
    private static function freqLabel(array $slots): string
    {
        $by    = array_column($slots, 'qty', 'slot');
        $order = isset($by['evening']) ? ['morning', 'afternoon', 'evening', 'night'] : ['morning', 'afternoon', 'night'];
        return implode('-', array_map(fn ($s) => isset($by[$s]) ? (string) ($by[$s] + 0) : '0', $order));
    }

    private function parseSymptoms(array &$err): array
    {
        $raw = $this->request->get('symptoms', []);
        if (!is_array($raw)) { $err['symptoms'] = ['Symptoms must be a list.']; return []; }
        if (count($raw) > 30) { $err['symptoms'] = ['Too many symptoms.']; return []; }
        $out = [];
        foreach (array_values($raw) as $s) {
            if (!is_array($s)) continue;
            $id  = isset($s['symptom_id']) && (int) $s['symptom_id'] > 0 ? (int) $s['symptom_id'] : null;
            $custom = self::clean((string) ($s['custom_symptom'] ?? ''));
            if ($id === null && $custom === '') continue;
            if ($id !== null && !$this->db->getRow('SELECT id FROM symptoms WHERE id = :i AND (doctor_id IS NULL OR doctor_id = :d)', [':i' => $id, ':d' => $this->authUser['doctor_id']])) {
                continue;
            }
            $sev = $s['severity'] ?? null;
            $out[] = [
                'symptom_id' => $id,
                'custom_symptom' => $id === null ? mb_substr($custom, 0, 200) : null,
                'severity' => in_array($sev, self::SEVERITY, true) ? $sev : null,
                'duration' => ($d = self::clean((string) ($s['duration'] ?? ''))) === '' ? null : mb_substr($d, 0, 100),
            ];
        }
        return $out;
    }

    private function parseAdvice(array &$err): array
    {
        $raw = $this->request->get('advice', []);
        if (!is_array($raw)) { $err['advice'] = ['Advice must be a list.']; return []; }
        if (count($raw) > 30) { $err['advice'] = ['Too much advice (max 30 lines).']; return []; }
        $out = [];
        foreach (array_values($raw) as $i => $a) {
            if (!is_array($a)) continue;
            $tid = isset($a['template_id']) && (int) $a['template_id'] > 0 ? (int) $a['template_id'] : null;
            $custom = self::clean((string) ($a['custom_text'] ?? ''));
            $cat = $a['category'] ?? 'other';
            if ($tid !== null) {
                $t = $this->db->getRow('SELECT id, category FROM advice_templates WHERE id = :i AND (doctor_id IS NULL OR doctor_id = :d)', [':i' => $tid, ':d' => $this->authUser['doctor_id']]);
                if (!$t) continue;
                $cat = $t['category'];
                $custom = '';
            } elseif ($custom === '') {
                continue;
            }
            $out[] = [
                'template_id' => $tid,
                'custom_text' => $tid === null ? mb_substr($custom, 0, 500) : null,
                'category'    => in_array($cat, self::ADVICE_CATS, true) ? $cat : 'other',
                'sort_order'  => min(127, $i),
            ];
        }
        return $out;
    }

    private function assertHasContent(array $in): void
    {
        if (!$in['row']['diagnosis'] && !$in['row']['chief_complaint'] && !$in['medicines'] && !$in['symptoms'] && InvestigationHelper::isEmpty($in['investigations'])) {
            Response::validationError(['prescription' => ['Add a diagnosis, complaint or at least one medicine before saving.']]);
        }
    }

    // ── Persistence ──────────────────────────────────────────────────────────
    private function writeChildren(int $id, array $in): void
    {
        foreach ($in['medicines'] as $m) $this->db->insert('prescription_medicines', ['prescription_id' => $id] + $m);
        foreach ($in['symptoms'] as $s)  $this->db->insert('prescription_symptoms', ['prescription_id' => $id] + $s);
        foreach ($in['advice'] as $a)    $this->db->insert('prescription_advice', ['prescription_id' => $id] + $a);
    }

    private function clearChildren(int $id): void
    {
        foreach (['prescription_medicines', 'prescription_symptoms', 'prescription_advice'] as $t) {
            $this->db->delete($t, 'prescription_id = :p', [':p' => $id]);
        }
    }

    /** Bump the patient so they float to the top of the register after a visit. */
    private function touchPatient(int $patientId): void
    {
        $this->db->update('patients', ['updated_at' => CommonHelper::now()], 'id = :id', [':id' => $patientId]);
    }

    /** An open appointment of this patient that a new prescription may be attached to (or null). */
    private function ownAppointment(int $id, int $doctorId, int $patientId): ?array
    {
        if ($id <= 0) return null;
        $a = $this->db->getRow(
            "SELECT id FROM appointments WHERE id = :i AND doctor_id = :d AND patient_id = :p AND status IN ('scheduled','confirmed','arrived')",
            [':i' => $id, ':d' => $doctorId, ':p' => $patientId]
        );
        if (!$a) Response::validationError(['appointment_id' => ['That appointment is no longer open.']]);
        return $a;
    }

    /** Seeing the patient and finalising the prescription closes the visit. */
    private function completeAppointment(int $prescriptionId): void
    {
        $this->db->update('appointments', ['status' => 'completed'], "prescription_id = :r AND status IN ('scheduled','confirmed','arrived')", [':r' => $prescriptionId]);
    }

    private function ownPatient(int $id, int $doctorId): array
    {
        $p = $id > 0 ? $this->db->getRow('SELECT * FROM patients WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $id, ':d' => $doctorId]) : null;
        if (!$p) Response::validationError(['patient_id' => ['Choose a patient from your register.']]);
        return $p;
    }

    private function rowOrFail(int $id, int $doctorId): array
    {
        $r = $this->db->getRow('SELECT * FROM prescriptions WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $id, ':d' => $doctorId]);
        if (!$r) Response::notFound('Prescription not found.');
        return $r;
    }

    private function assertDraft(array $rx, string $msg = 'A saved prescription can’t be edited. Cancel it and write a new one instead.'): void
    {
        if ($rx['status'] !== 'draft') Response::error($msg, 409);
    }

    private function primaryClinic(int $doctorId): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC LIMIT 1',
            [':d' => $doctorId]
        );
    }

    // ── Shaping ──────────────────────────────────────────────────────────────
    private function load(int $id, int $doctorId): array
    {
        $rx = $this->db->getRow(
            'SELECT p.*, pt.uhid, pt.name AS patient_name, pt.sex AS patient_sex, pt.mobile AS patient_mobile, pt.age_years,
                    pt.date_of_birth, pt.known_allergies, pt.chronic_conditions AS patient_conditions, pt.blood_group
             FROM prescriptions p JOIN patients pt ON pt.id = p.patient_id WHERE p.id = :i AND p.doctor_id = :d',
            [':i' => $id, ':d' => $doctorId]
        );
        $meds = $this->db->getRows('SELECT * FROM prescription_medicines WHERE prescription_id = :i AND deleted_at IS NULL ORDER BY sort_order, id', [':i' => $id]);
        $sym  = $this->db->getRows(
            'SELECT ps.*, s.name AS symptom_name FROM prescription_symptoms ps LEFT JOIN symptoms s ON s.id = ps.symptom_id
             WHERE ps.prescription_id = :i AND ps.deleted_at IS NULL ORDER BY ps.id', [':i' => $id]);
        $adv  = $this->db->getRows(
            'SELECT pa.*, t.text AS template_text FROM prescription_advice pa LEFT JOIN advice_templates t ON t.id = pa.template_id
             WHERE pa.prescription_id = :i AND pa.deleted_at IS NULL ORDER BY pa.sort_order, pa.id', [':i' => $id]);
        $clinic = $rx['clinic_id'] ? $this->db->getRow('SELECT * FROM clinics WHERE id = :c', [':c' => $rx['clinic_id']]) : null;

        $num = fn ($v) => $v === null ? null : $v + 0;
        return [
            'id'          => (int) $rx['id'],
            'status'      => $rx['status'],
            'visit_date'  => $rx['visit_date'],
            'patient'     => [
                'id' => (int) $rx['patient_id'], 'uhid' => $rx['uhid'], 'name' => $rx['patient_name'], 'sex' => $rx['patient_sex'],
                'mobile' => $rx['patient_mobile'], 'age_label' => PatientController::ageLabel(['date_of_birth' => $rx['date_of_birth'], 'age_years' => $rx['age_years']]),
                'known_allergies' => $rx['known_allergies'], 'blood_group' => $rx['blood_group'],
            ],
            'clinic' => $clinic ? [
                'name' => $clinic['name'], 'address' => implode(', ', array_filter([$clinic['address_line1'], $clinic['address_line2'], $clinic['city'], $clinic['state'], $clinic['pincode']])),
                'phone' => $clinic['phone'], 'timings' => $clinic['timings'],
            ] : null,
            'vitals' => [
                'weight_kg' => $num($rx['weight_kg']), 'height_cm' => $num($rx['height_cm']), 'bmi' => $num($rx['bmi']),
                'bp_systolic' => $num($rx['bp_systolic']), 'bp_diastolic' => $num($rx['bp_diastolic']), 'pulse_bpm' => $num($rx['pulse_bpm']),
                'temperature_f' => $num($rx['temperature_f']), 'spo2_pct' => $num($rx['spo2_pct']), 'rbs_mg_dl' => $num($rx['rbs_mg_dl']),
                'respiratory_rate' => $num($rx['respiratory_rate']),
            ],
            'chief_complaint' => $rx['chief_complaint'],
            'symptoms' => array_map(fn ($s) => [
                'symptom_id' => $s['symptom_id'] === null ? null : (int) $s['symptom_id'],
                'name' => $s['symptom_name'] ?? $s['custom_symptom'], 'severity' => $s['severity'], 'duration' => $s['duration'],
            ], $sym),
            'diagnosis'      => $rx['diagnosis'],
            'clinical_notes' => $rx['clinical_notes'],
            'medicines' => array_map(fn ($m) => [
                'id' => (int) $m['id'],
                'medicine_id' => $m['medicine_id'] === null ? null : (int) $m['medicine_id'],
                'medicine_name' => $m['medicine_name'],
                'qty_unit' => $m['qty_unit'], 'total_qty' => $num($m['total_qty']),
                'duration_days' => $m['duration_days'] === null ? null : (int) $m['duration_days'],
                'duration_note' => $m['duration_note'],
                'dose_schedule' => json_decode((string) $m['dose_schedule'], true) ?: [],
                'times_per_day' => $m['times_per_day'] === null ? null : (int) $m['times_per_day'],
                'freq_display' => $m['freq_display'], 'instructions' => $m['instructions'],
                'is_substitutable' => (bool) $m['is_substitutable'],
            ], $meds),
            'advice' => array_map(fn ($a) => [
                'id' => (int) $a['id'], 'template_id' => $a['template_id'] === null ? null : (int) $a['template_id'],
                'category' => $a['category'], 'text' => $a['template_text'] ?? $a['custom_text'],
            ], $adv),
            'investigations' => InvestigationHelper::forPrescription($id),
            'followup_date' => $rx['followup_date'], 'followup_note' => $rx['followup_note'],
            'created_at' => $rx['created_at'],
        ];
    }

    /** Compact rows for lists: counts and the first few medicine names. */
    private function summaries(array $rows, bool $withPatient): array
    {
        if (!$rows) return [];
        $ids  = array_column($rows, 'id');
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $meds = $this->db->getRows(
            "SELECT prescription_id, medicine_name FROM prescription_medicines WHERE prescription_id IN ($in) AND deleted_at IS NULL ORDER BY sort_order, id", $ids);
        $by = [];
        foreach ($meds as $m) $by[$m['prescription_id']][] = $m['medicine_name'];

        return array_map(function ($r) use ($by, $withPatient) {
            $names = $by[$r['id']] ?? [];
            $o = [
                'id' => (int) $r['id'], 'status' => $r['status'], 'visit_date' => $r['visit_date'],
                'diagnosis' => $r['diagnosis'], 'chief_complaint' => $r['chief_complaint'],
                'medicines_count' => count($names), 'medicine_names' => array_slice($names, 0, 3),
                'followup_date' => $r['followup_date'],
                'patient' => [
                    'id' => (int) $r['patient_id'], 'name' => $r['patient_name'], 'sex' => $r['patient_sex'], 'uhid' => $r['uhid'],
                    'age_label' => PatientController::ageLabel(['date_of_birth' => $r['date_of_birth'], 'age_years' => $r['age_years']]),
                ],
            ];
            return $o;
        }, $rows);
    }
}
