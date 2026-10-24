<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * DoctorController — the signed-in doctor's own profile.
 *
 * GET /doctor/profile          → profile()
 * PUT /doctor/profile          → updateProfile()
 * PUT /doctor/change-password  → changePassword()
 * GET /dashboard/summary       → summary()
 * GET/PUT /doctor/clinic       → clinic() / updateClinic()
 */
class DoctorController extends BaseController
{
    /**
     * Numbers for the home screen. `since` (UTC datetime) is the start of the
     * doctor's local day, so "today" follows their clock, not the server's.
     */
    public function summary(): void
    {
        $d   = (int) $this->requireDoctor()['id'];
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS total, COALESCE(SUM(created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)), 0) AS this_week
             FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND is_active = 1',
            [':d' => $d]
        );
        $since = strtotime((string) $this->request->get('since', ''));
        $since = $since === false ? strtotime('today') : $since;
        $rx = $this->db->getRow(
            "SELECT COUNT(*) AS total, COALESCE(SUM(visit_date >= :s), 0) AS today
             FROM prescriptions WHERE doctor_id = :d AND deleted_at IS NULL AND status = 'final'",
            [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since)]
        );
        $until = gmdate('Y-m-d H:i:s', $since + 86400);
        $ap = $this->db->getRow(
            "SELECT COALESCE(SUM(status = 'arrived'), 0) AS waiting,
                    COALESCE(SUM(status IN ('scheduled','confirmed')), 0) AS upcoming,
                    COALESCE(SUM(status = 'completed'), 0) AS done,
                    COALESCE(SUM(status <> 'cancelled'), 0) AS total
             FROM appointments WHERE doctor_id = :d AND scheduled_at >= :s AND scheduled_at < :u",
            [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since), ':u' => $until]
        );
        $pending = $this->db->getRow(
            "SELECT
               (SELECT COUNT(*) FROM lab_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 WHERE o.doctor_id = :d1 AND o.deleted_at IS NULL AND o.status IN ('ordered','sample_collected','processing'))
             + (SELECT COUNT(*) FROM radiology_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 WHERE o.doctor_id = :d2 AND o.deleted_at IS NULL AND o.status = 'ordered') AS n",
            [':d1' => $d, ':d2' => $d]
        );
        $drafts = $this->db->getRow("SELECT COUNT(*) AS n FROM prescriptions WHERE doctor_id = :d AND deleted_at IS NULL AND status = 'draft'", [':d' => $d]);
        $coll = $this->db->getRow('SELECT COALESCE(SUM(amount), 0) AS n FROM bill_payments WHERE doctor_id = :d AND paid_at >= :s AND paid_at < :u', [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since), ':u' => $until]);
        Response::success([
            'collected_today'     => (float) $coll['n'],
            'patients_total'      => (int) $row['total'],
            'patients_this_week'  => (int) $row['this_week'],
            'prescriptions_total' => (int) $rx['total'],
            'prescriptions_today' => (int) $rx['today'],
            'drafts'              => (int) $drafts['n'],
            'orders_pending'      => (int) $pending['n'],
            'queue_waiting'       => (int) $ap['waiting'],
            'queue_upcoming'      => (int) $ap['upcoming'],
            'queue_done'          => (int) $ap['done'],
            'appointments_today'  => (int) $ap['total'],
            'attention'           => FollowupHelper::compute($d, (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->request->get('today', '')) ? (string) $this->request->get('today') : gmdate('Y-m-d')))['attention'],
        ], 'Summary fetched');
    }

    // ── Clinic (printed on every prescription) ───────────────────────────────
    public function clinic(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        Response::success(['clinic' => self::publicClinic($this->primaryClinic($d))], 'Clinic fetched');
    }

    public function updateClinic(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $this->validate([
            'name'          => 'required|string|min:2|max:200',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'pincode'       => 'nullable|string|max:10',
            'phone'         => 'nullable|string|max:20',
            'timings'       => 'nullable|string|max:500',
        ]);
        $fee = $this->request->get('consultation_fee');
        if ($fee !== null && $fee !== '' && (!is_numeric($fee) || (float) $fee < 0 || (float) $fee > 1000000)) {
            Response::validationError(['consultation_fee' => ['Enter a fee between 0 and 10,00,000.']]);
        }
        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;
        $name = self::clean((string) $this->request->get('name'));
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter the clinic name.']]);
        }
        $data = ['name' => $name] + array_combine(
            ['address_line1', 'address_line2', 'city', 'state', 'pincode', 'phone', 'timings'],
            array_map($opt, ['address_line1', 'address_line2', 'city', 'state', 'pincode', 'phone', 'timings'])
        );

        $data['consultation_fee'] = ($fee === null || $fee === '') ? null : round((float) $fee, 2);

        $existing = $this->primaryClinic($d);
        if ($existing) {
            $this->db->update('clinics', $data, 'id = :id', [':id' => $existing['id']]);
        } else {
            $this->db->insert('clinics', $data + ['doctor_id' => $d, 'is_primary' => 1]);
        }
        Response::success(['clinic' => self::publicClinic($this->primaryClinic($d))], 'Clinic saved');
    }

    private function primaryClinic(int $doctorId): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC LIMIT 1',
            [':d' => $doctorId]
        );
    }

    private static function publicClinic(?array $c): ?array
    {
        if (!$c) return null;
        return [
            'name' => $c['name'], 'address_line1' => $c['address_line1'], 'address_line2' => $c['address_line2'],
            'city' => $c['city'], 'state' => $c['state'], 'pincode' => $c['pincode'], 'phone' => $c['phone'], 'timings' => $c['timings'],
            'consultation_fee' => $c['consultation_fee'] === null ? null : (float) $c['consultation_fee'],
        ];
    }

    public function profile(): void
    {
        Response::success(['user' => self::publicDoctor($this->requireDoctor())], 'Profile fetched');
    }

    public function updateProfile(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'name'            => 'required|string|min:2|max:100',
            'email'           => 'nullable|email|max:191',
            'degree'          => 'nullable|string|max:255',
            'specialization'  => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'experience_yrs'  => 'nullable|integer',
        ]);

        $text = fn (string $key) => ($v = self::clean((string) ($data[$key] ?? ''))) === '' ? null : $v;

        $name = self::clean((string) $data['name']);
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter your full name.']]);
        }

        $email = $text('email');
        $email = $email === null ? null : strtolower($email);
        $regNo = $text('registration_no');

        $years = $data['experience_yrs'] ?? null;
        if ($years !== null && $years !== '') {
            if ((int) $years < 0 || (int) $years > 70) {
                Response::validationError(['experience_yrs' => ['Experience must be between 0 and 70 years.']]);
            }
            $years = (int) $years;
        } else {
            $years = null;
        }

        foreach (['email' => $email, 'registration_no' => $regNo] as $column => $value) {
            if ($value === null) continue;
            $clash = $this->db->getRow("SELECT id FROM doctors WHERE {$column} = :v AND id <> :id", [':v' => $value, ':id' => $doctor['id']]);
            if ($clash) {
                $label = $column === 'email' ? 'e-mail address' : 'registration number';
                Response::validationError([$column => ["This {$label} is already used by another account."]]);
            }
        }

        $this->db->update('doctors', [
            'name'            => $name,
            'email'           => $email,
            'degree'          => $text('degree'),
            'specialization'  => $text('specialization'),
            'registration_no' => $regNo,
            'experience_yrs'  => $years,
        ], 'id = :id', [':id' => $doctor['id']]);

        $fresh = $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => $doctor['id']]);
        Response::success(['user' => self::publicDoctor($fresh)], 'Profile updated');
    }

    public function changePassword(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:64',
        ]);

        if (!CommonHelper::verifyPassword($data['current_password'], $doctor['password_hash'])) {
            Response::validationError(['current_password' => ['Your current password is incorrect.']]);
        }
        if (!preg_match('/[A-Za-z]/', $data['password']) || !preg_match('/\d/', $data['password'])) {
            Response::validationError(['password' => ['Use at least one letter and one number.']]);
        }
        if ($data['password'] === $data['current_password']) {
            Response::validationError(['password' => ['Choose a password different from the current one.']]);
        }

        $this->db->update('doctors', ['password_hash' => CommonHelper::hashPassword($data['password'])], 'id = :id', [':id' => $doctor['id']]);

        // Sign out everywhere else, but keep this device signed in with a fresh session.
        $this->auth->revokeAllForDoctor((int) $doctor['id']);

        Response::success([
            'access_token'  => $this->auth->generateToken(['doctor_id' => (int) $doctor['id'], 'role' => 'doctor']),
            'refresh_token' => $this->auth->generateRefreshToken((int) $doctor['id']),
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ], 'Password changed');
    }
}
