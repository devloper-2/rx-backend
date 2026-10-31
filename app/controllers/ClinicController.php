<?php

declare(strict_types=1);

/** Weekly opening hours (up to two sessions a day) and holidays. Drives the booking slots in the app. */
class ClinicController extends BaseController
{
    private const SHIFTS = ['morning', 'evening'];

    /** GET doctor/hours */
    public function hours(): void
    {
        $d = (int) $this->requireClinic()['id'];
        Response::success(['hours' => $this->payload($d)], 'Hours fetched');
    }

    /** PUT doctor/hours {days:[{day:0-6 (0=Sun), sessions:[{open:"09:00",close:"13:00"}]}]} — replaces the week; a day without sessions is closed. */
    public function saveHours(): void
    {
        $d      = (int) $this->requireDoctor()['id'];
        $clinic = $this->db->getRow('SELECT id FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $d]);
        if (!$clinic) Response::error('Save your clinic details first.', 422);

        $days = $this->request->input('days');
        if (!is_array($days) || count($days) > 7) Response::validationError(['days' => ['Send up to 7 days.']]);

        $rows = [];
        $seen = [];
        foreach ($days as $i => $day) {
            $n = is_array($day) ? ($day['day'] ?? null) : null;
            if (!is_int($n) && !(is_string($n) && ctype_digit($n))) Response::validationError(["days.$i" => ['Each entry needs a day (0 = Sunday … 6 = Saturday).']]);
            $n = (int) $n;
            if ($n < 0 || $n > 6 || isset($seen[$n])) Response::validationError(["days.$i" => ['Day must be 0–6 and appear once.']]);
            $seen[$n] = true;
            $sessions = $day['sessions'] ?? [];
            if (!is_array($sessions) || count($sessions) > 2) Response::validationError(["days.$i" => ['At most two sessions a day.']]);
            $prevEnd = '';
            foreach (array_values($sessions) as $k => $s) {
                $o = is_array($s) ? (string) ($s['open'] ?? '') : '';
                $c = is_array($s) ? (string) ($s['close'] ?? '') : '';
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $o) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c)) Response::validationError(["days.$i" => ['Times must look like 09:30.']]);
                if ($c <= $o) Response::validationError(["days.$i" => ['Closing time must be after opening time.']]);
                if ($prevEnd !== '' && $o < $prevEnd) Response::validationError(["days.$i" => ['Sessions must not overlap.']]);
                $prevEnd = $c;
                $rows[] = [$n, self::SHIFTS[$k], $o . ':00', $c . ':00'];
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->delete('clinic_timings', 'clinic_id = :c', [':c' => $clinic['id']]);
            foreach ($rows as [$n, $shift, $o, $c]) {
                $this->db->insert('clinic_timings', ['clinic_id' => $clinic['id'], 'day_of_week' => $n, 'shift' => $shift, 'open_time' => $o, 'close_time' => $c, 'is_open' => 1]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        Response::success(['hours' => $this->payload($d)], 'Hours saved');
    }

    /** POST doctor/holidays {date, reason?} */
    public function addHoliday(): void
    {
        $d    = (int) $this->requireDoctor()['id'];
        $date = (string) $this->request->input('date', '');
        $why  = self::clean((string) $this->request->input('reason', ''));
        $ts   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? strtotime($date . ' UTC') : false;
        if ($ts === false || gmdate('Y-m-d', $ts) !== $date) Response::validationError(['date' => ['Choose a valid date.']]);
        if ($ts > time() + 800 * 86400) Response::validationError(['date' => ['That is too far ahead.']]);
        if (mb_strlen($why) > 120) Response::validationError(['reason' => ['Keep the reason under 120 characters.']]);
        $this->db->executeQuery(
            'INSERT INTO clinic_holidays (doctor_id, holiday_date, reason) VALUES (:d, :t, :r) ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [':d' => $d, ':t' => $date, ':r' => $why === '' ? null : $why]
        );
        Response::success(['hours' => $this->payload($d)], 'Holiday added');
    }

    /** DELETE doctor/holidays/{id} */
    public function deleteHoliday(array $params): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $n = $this->db->delete('clinic_holidays', 'id = :i AND doctor_id = :d', [':i' => (int) $params['id'], ':d' => $d]);
        if ($n === 0) Response::error('Not found', 404);
        Response::success(['hours' => $this->payload($d)], 'Holiday removed');
    }

    private function payload(int $d): array
    {
        $rows = $this->db->getRows(
            "SELECT t.day_of_week, t.shift, t.open_time, t.close_time FROM clinic_timings t
             JOIN clinics c ON c.id = t.clinic_id AND c.doctor_id = :d AND c.deleted_at IS NULL AND c.id = (
                 SELECT id FROM clinics WHERE doctor_id = :d2 AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1)
             WHERE t.is_open = 1 ORDER BY t.day_of_week, t.open_time",
            [':d' => $d, ':d2' => $d]
        );
        $days = [];
        for ($i = 0; $i < 7; $i++) $days[$i] = ['day' => $i, 'sessions' => []];
        foreach ($rows as $r) {
            $days[(int) $r['day_of_week']]['sessions'][] = ['open' => substr($r['open_time'], 0, 5), 'close' => substr($r['close_time'], 0, 5)];
        }
        $holidays = $this->db->getRows(
            'SELECT id, holiday_date, reason FROM clinic_holidays WHERE doctor_id = :d AND holiday_date >= :f ORDER BY holiday_date LIMIT 100',
            [':d' => $d, ':f' => gmdate('Y-m-d', time() - 86400)]
        );
        return [
            'configured' => (bool) $rows,
            'days'       => array_values($days),
            'holidays'   => array_map(fn ($h) => ['id' => (int) $h['id'], 'date' => $h['holiday_date'], 'reason' => $h['reason']], $holidays),
        ];
    }
}
