<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * PatientController — a doctor's own patient register.
 *
 * GET    /patients              → list()    ?q=&page=&limit=
 * POST   /patients              → create()
 * GET    /patients/{id}         → detail()
 * PUT    /patients/{id}         → update()
 * DELETE /patients/{id}         → delete()  (archive, never a hard delete)
 * GET    /catalog/conditions    → conditions()
 *
 * Every query is scoped to the signed-in doctor's id.
 */
class PatientController extends BaseController
{
    private const SEXES  = ['male', 'female', 'other', 'prefer_not_to_say'];
    private const BLOODS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'];

    // ── GET /patients ────────────────────────────────────────────────────────
    public function list(): void
    {
        $doctor = $this->requireClinic();
        $q      = trim((string) $this->request->get('q', ''));

        $sql    = 'SELECT * FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND is_active = 1';
        $params = [':d' => $doctor['id']];

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (name LIKE :q1 OR uhid LIKE :q2 OR mobile LIKE :q3 OR whatsapp_number LIKE :q4)';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }

        // Most recently touched first: that is who the doctor is most likely to look for.
        $sql .= ' ORDER BY updated_at DESC, id DESC';

        $page = $this->db->paginate($sql, $params, $this->getPage(), $this->getLimit(20, 50));

        Response::success([
            'items'      => array_map(fn ($p) => self::publicPatient($p), $page['data']),
            'pagination' => $page['pagination'],
        ], 'Patients fetched');
    }

    // ── POST /patients ───────────────────────────────────────────────────────
    public function create(): void
    {
        $doctor = $this->requireClinic();
        $in     = $this->cleanInput();

        // Families share one phone, so a repeated mobile alone is fine. The same
        // name on the same mobile is most likely a double entry: ask first.
        if ($in['mobile'] !== null && !filter_var($this->request->get('force'), FILTER_VALIDATE_BOOLEAN)) {
            $dup = $this->db->getRow(
                'SELECT * FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND mobile = :m AND LOWER(name) = LOWER(:n) LIMIT 1',
                [':d' => $doctor['id'], ':m' => $in['mobile'], ':n' => $in['name']]
            );
            if ($dup) {
                Response::send(409, ['duplicate' => self::publicPatient($dup)],
                    'A patient with this name and mobile number already exists.', false);
            }
        }

        $this->db->beginTransaction();
        try {
            $uhid = $this->nextUhid((int) $doctor['id']);
            $id   = $this->db->insert('patients', ['doctor_id' => $doctor['id'], 'uhid' => $uhid] + $in['row']);
            $this->syncConditions($id, (int) $doctor['id'], $in['condition_ids']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        Response::created(['patient' => $this->load($id, (int) $doctor['id'])], 'Patient added');
    }

    // ── GET /patients/{id} ───────────────────────────────────────────────────
    public function detail(array $params): void
    {
        $doctor = $this->requireClinic();
        Response::success(['patient' => $this->loadOrFail((int) $params['id'], (int) $doctor['id'])], 'Patient fetched');
    }

    // ── PUT /patients/{id} ───────────────────────────────────────────────────
    public function update(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $this->loadOrFail($id, (int) $doctor['id']);
        $in = $this->cleanInput();

        $this->db->beginTransaction();
        try {
            $this->db->update('patients', $in['row'], 'id = :id AND doctor_id = :d', [':id' => $id, ':d' => $doctor['id']]);
            $this->syncConditions($id, (int) $doctor['id'], $in['condition_ids']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        Response::success(['patient' => $this->load($id, (int) $doctor['id'])], 'Patient updated');
    }

    // ── DELETE /patients/{id} ────────────────────────────────────────────────
    public function delete(array $params): void
    {
        $doctor = $this->requireDoctor();
        $id     = (int) $params['id'];
        $this->loadOrFail($id, (int) $doctor['id']);

        // Medical records are kept: archiving only hides the patient from the register.
        $this->db->update('patients', ['deleted_at' => CommonHelper::now()], 'id = :id AND doctor_id = :d', [':id' => $id, ':d' => $doctor['id']]);
        Response::success([], 'Patient archived');
    }

    // ── GET /catalog/conditions ──────────────────────────────────────────────
    public function conditions(): void
    {
        $doctor = $this->requireClinic();
        $rows   = $this->db->getRows(
            'SELECT id, name, category FROM chronic_conditions
             WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = :d) ORDER BY name',
            [':d' => $doctor['id']]
        );
        Response::success(['items' => $rows], 'Conditions fetched');
    }

    // ── Input handling ───────────────────────────────────────────────────────
    /** Validate the request and return the DB row plus condition ids. */
    private function cleanInput(): array
    {
        $this->validate([
            'name'        => 'required|string|min:2|max:150',
            'sex'         => 'required|in:' . implode(',', self::SEXES),
            'mobile'      => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'email'       => 'nullable|email|max:191',
            'date_of_birth' => 'nullable|date',
            'age_years'   => 'nullable|integer',
            'blood_group' => 'nullable|in:' . implode(',', self::BLOODS),
            'address'     => 'nullable|string|max:500',
            'occupation'  => 'nullable|string|max:150',
            'known_allergies' => 'nullable|string|max:1000',
        ]);

        $text = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;
        $name = self::clean((string) $this->request->get('name'));
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter the patient’s name.']]);
        }

        // Age: a date of birth wins (gives exact months for infants); otherwise years.
        $dob = trim((string) ($this->request->get('date_of_birth') ?? ''));
        $age = $this->request->get('age_years');
        $dobTs = $dob === '' ? null : strtotime($dob);
        if ($dob !== '' && ($dobTs === false || $dobTs > time() || $dobTs < strtotime('1900-01-01'))) {
            Response::validationError(['date_of_birth' => ['Enter a valid date of birth (not in the future).']]);
        }
        if ($dobTs !== null) {
            $age = self::yearsBetween($dobTs, time());
        } elseif ($age === null || $age === '') {
            Response::validationError(['age_years' => ['Enter the patient’s age or date of birth.']]);
        } elseif ((int) $age < 0 || (int) $age > 120) {
            Response::validationError(['age_years' => ['Age must be between 0 and 120.']]);
        }

        $mobile = $this->phone('mobile');
        $wa     = $this->phone('whatsapp_number');
        $email  = $text('email');

        $ids = $this->request->get('condition_ids', []);
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0))) : [];
        if (count($ids) > 30) {
            Response::validationError(['condition_ids' => ['Too many conditions selected.']]);
        }

        $blood = $this->request->get('blood_group');

        return [
            'name'          => $name,
            'mobile'        => $mobile,
            'condition_ids' => $ids,
            'row' => [
                'name'            => $name,
                'sex'             => $this->request->get('sex'),
                'mobile'          => $mobile,
                'whatsapp_number' => $wa,
                'email'           => $email === null ? null : strtolower($email),
                'date_of_birth'   => $dobTs === null ? null : date('Y-m-d', $dobTs),
                'age_years'       => (int) $age,
                'blood_group'     => ($blood === null || $blood === '') ? null : $blood,
                'address'         => $text('address'),
                'occupation'      => $text('occupation'),
                'known_allergies' => $text('known_allergies'),
            ],
        ];
    }

    /** Digits only (a leading + is kept); null when blank; 422 when implausible. */
    private function phone(string $field): ?string
    {
        $raw = trim((string) ($this->request->get($field) ?? ''));
        if ($raw === '') return null;
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 6 || strlen($digits) > 14) {
            Response::validationError([$field => ['Enter a valid phone number.']]);
        }
        return (str_starts_with($raw, '+') ? '+' : '') . $digits;
    }

    /** Next "P0001"-style id for this doctor; the unique key guards against races. */
    private function nextUhid(int $doctorId): string
    {
        $row = $this->db->getRow(
            "SELECT MAX(CAST(SUBSTRING(uhid, 2) AS UNSIGNED)) AS n FROM patients WHERE doctor_id = :d AND uhid REGEXP '^P[0-9]+$'",
            [':d' => $doctorId]
        );
        return 'P' . str_pad((string) (((int) ($row['n'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Replace the patient's structured conditions and keep the readable summary column in step. */
    private function syncConditions(int $patientId, int $doctorId, array $ids): void
    {
        $valid = [];
        if ($ids) {
            $in    = implode(',', array_fill(0, count($ids), '?'));
            $valid = $this->db->getRows(
                "SELECT id, name FROM chronic_conditions WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = ?) AND id IN ($in) ORDER BY name",
                array_merge([$doctorId], $ids)
            );
        }
        $this->db->delete('patient_chronic_conditions', 'patient_id = :p', [':p' => $patientId]);
        foreach ($valid as $c) {
            $this->db->insert('patient_chronic_conditions', [
                'patient_id' => $patientId, 'condition_id' => $c['id'], 'added_by_doctor' => $doctorId,
            ]);
        }
        $this->db->update('patients', ['chronic_conditions' => $valid ? implode(', ', array_column($valid, 'name')) : null],
            'id = :id', [':id' => $patientId]);
    }

    // ── Loading / shaping ────────────────────────────────────────────────────
    private function load(int $id, int $doctorId): ?array
    {
        $row = $this->db->getRow('SELECT * FROM patients WHERE id = :id AND doctor_id = :d AND deleted_at IS NULL', [':id' => $id, ':d' => $doctorId]);
        if (!$row) return null;
        $conds = $this->db->getRows(
            'SELECT c.id, c.name FROM patient_chronic_conditions pc JOIN chronic_conditions c ON c.id = pc.condition_id WHERE pc.patient_id = :p ORDER BY c.name',
            [':p' => $id]
        );
        return self::publicPatient($row) + ['conditions' => $conds];
    }

    private function loadOrFail(int $id, int $doctorId): array
    {
        $p = $this->load($id, $doctorId);
        if (!$p) Response::notFound('Patient not found.');
        return $p;
    }

    private static function yearsBetween(int $from, int $to): int
    {
        return (int) (new DateTime('@' . $from))->diff(new DateTime('@' . $to))->y;
    }

    /** "3 m", "8 y" — months for babies, otherwise years. */
    public static function ageLabel(array $p): ?string
    {
        $dob = $p['date_of_birth'] ?? null;
        if ($dob && $dob !== '0000-00-00' && ($ts = strtotime($dob)) !== false) {
            $d = (new DateTime('@' . $ts))->diff(new DateTime('now', new DateTimeZone('UTC')));
            if ($d->y < 1) return $d->m < 1 ? max(1, $d->d) . ' d' : $d->m . ' m';
            return $d->y . ' y';
        }
        return $p['age_years'] === null ? null : $p['age_years'] . ' y';
    }

    public static function publicPatient(array $p): array
    {
        $dobOk = !empty($p['date_of_birth']) && $p['date_of_birth'] !== '0000-00-00';
        return [
            'id'              => (int) $p['id'],
            'uhid'            => $p['uhid'],
            'name'            => $p['name'],
            'sex'             => $p['sex'],
            'mobile'          => $p['mobile'],
            'whatsapp_number' => $p['whatsapp_number'],
            'email'           => $p['email'],
            'date_of_birth'   => $dobOk ? $p['date_of_birth'] : null,
            'age_years'       => $p['age_years'] === null ? null : (int) $p['age_years'],
            'age_label'       => self::ageLabel($p),
            'blood_group'     => $p['blood_group'],
            'address'         => $p['address'],
            'occupation'      => $p['occupation'],
            'known_allergies' => $p['known_allergies'],
            'chronic_summary' => $p['chronic_conditions'],
            'created_at'      => $p['created_at'],
            'updated_at'      => $p['updated_at'],
        ];
    }
}
