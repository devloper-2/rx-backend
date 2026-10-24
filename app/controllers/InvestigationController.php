<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';
require_once ROOT_PATH . '/app/controllers/PatientController.php';

/**
 * InvestigationController — follow lab and imaging orders after they are written.
 *
 * GET  /orders?status=pending|done|all&kind=all|lab|imaging&patient_id=   → list()
 * GET  /lab-orders/{id}                 → labDetail()     tests, results, previous values
 * PUT  /lab-orders/{id}/results         → saveLabResults()
 * POST /lab-orders/{id}/status          → labStatus()
 * POST|GET /lab-orders/{id}/file        → uploadLabFile() / labFile()
 * GET  /radiology-orders/{id}           → radDetail()
 * PUT  /radiology-orders/{id}/report    → saveRadReport()
 * POST /radiology-orders/{id}/status    → radStatus()
 * POST|GET /radiology-orders/{id}/file  → uploadRadFile() / radFile()
 *
 * Orders only appear here once their prescription is final.
 * Report photos live in storage/reports (outside the web root) and are
 * streamed back only to the signed-in doctor who owns the order.
 */
class InvestigationController extends BaseController
{
    private const LAB_FLOW = [
        'ordered'          => ['sample_collected', 'processing', 'report_ready', 'cancelled'],
        'sample_collected' => ['processing', 'report_ready', 'cancelled'],
        'processing'       => ['report_ready', 'cancelled'],
        'report_ready'     => ['delivered', 'processing'],
        'delivered'        => [],
        'cancelled'        => [],
    ];
    private const RAD_FLOW = [
        'ordered'      => ['report_ready', 'cancelled'],
        'report_ready' => ['delivered', 'ordered'],
        'delivered'    => [],
        'cancelled'    => [],
    ];
    private const FLAGS = ['normal', 'low', 'high', 'critical'];
    private const FILE_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    // ── GET /orders ──────────────────────────────────────────────────────────
    public function list(): void
    {
        $d      = (int) $this->requireDoctor()['id'];
        $status = (string) $this->request->get('status', 'pending');
        $kind   = (string) $this->request->get('kind', 'all');
        $pid    = (int) $this->request->get('patient_id', 0);

        $labSt = match ($status) {
            'pending' => "o.status IN ('ordered','sample_collected','processing')",
            'done'    => "o.status IN ('report_ready','delivered')",
            default   => '1=1',
        };
        $radSt = match ($status) {
            'pending' => "o.status = 'ordered'",
            'done'    => "o.status IN ('report_ready','delivered')",
            default   => '1=1',
        };
        $pf = $pid > 0 ? ' AND o.patient_id = ' . $pid : '';

        $items = [];
        if ($kind !== 'imaging') {
            $rows = $this->db->getRows(
                "SELECT o.*, pt.name AS patient_name, pt.sex AS patient_sex, pt.uhid, pt.age_years, pt.date_of_birth
                 FROM lab_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 JOIN patients pt ON pt.id = o.patient_id
                 WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND $labSt $pf ORDER BY o.order_date DESC LIMIT 100", [':d' => $d]);
            foreach ($rows as $o) {
                $s = InvestigationHelper::shapeLab($o, true);
                $names = array_column($s['tests'], 'name');
                $items[] = [
                    'kind' => 'lab', 'id' => $s['id'], 'status' => $s['status'], 'urgency' => $s['urgency'], 'order_date' => $s['order_date'],
                    'title' => implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : ''),
                    'tests_count' => $s['tests_count'], 'results_in' => $s['results_in'], 'abnormal_count' => $s['abnormal_count'],
                    'has_file' => $s['has_file'], 'prescription_id' => $s['prescription_id'], 'patient' => self::patientOf($o),
                ];
            }
        }
        if ($kind !== 'lab') {
            $rows = $this->db->getRows(
                "SELECT o.*, pt.name AS patient_name, pt.sex AS patient_sex, pt.uhid, pt.age_years, pt.date_of_birth
                 FROM radiology_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 JOIN patients pt ON pt.id = o.patient_id
                 WHERE o.doctor_id = :d AND o.deleted_at IS NULL AND $radSt $pf ORDER BY o.created_at DESC LIMIT 100", [':d' => $d]);
            foreach ($rows as $o) {
                $s = InvestigationHelper::shapeRadiology($o);
                $items[] = [
                    'kind' => 'imaging', 'id' => $s['id'], 'status' => $s['status'], 'urgency' => $s['urgency'], 'order_date' => $s['created_at'],
                    'title' => trim($s['modality_label'] . ' ' . ($s['body_part'] ?? '') . ($s['view_position'] ? ' (' . $s['view_position'] . ')' : '')),
                    'tests_count' => 1, 'results_in' => $s['impression'] ? 1 : 0, 'abnormal_count' => 0,
                    'has_file' => $s['has_file'], 'prescription_id' => $s['prescription_id'], 'patient' => self::patientOf($o),
                ];
            }
        }
        // Urgent first, then oldest first for pending work / newest first for finished work.
        $rank = ['stat' => 0, 'urgent' => 1, 'routine' => 2];
        usort($items, function ($a, $b) use ($status, $rank) {
            if ($status === 'pending' && $rank[$a['urgency']] !== $rank[$b['urgency']]) return $rank[$a['urgency']] <=> $rank[$b['urgency']];
            return $status === 'pending' ? strcmp($a['order_date'], $b['order_date']) : strcmp($b['order_date'], $a['order_date']);
        });

        $page  = $this->getPage();
        $limit = $this->getLimit(30, 50);
        $total = count($items);
        Response::success([
            'items'      => array_slice($items, ($page - 1) * $limit, $limit),
            'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'has_next' => $page * $limit < $total],
        ], 'Orders fetched');
    }

    // ── Lab ──────────────────────────────────────────────────────────────────
    public function labDetail(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->lab((int) $p['id'], $d);
        Response::success(['order' => $this->labPayload($o)], 'Order fetched');
    }

    public function saveLabResults(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->lab((int) $p['id'], $d);
        if ($o['status'] === 'cancelled') Response::error('A cancelled order can’t take results.', 409);

        $raw = $this->request->get('tests', []);
        if (!is_array($raw)) Response::validationError(['tests' => ['Results must be a list.']]);

        $own = [];
        foreach ($this->db->getRows('SELECT t.*, c.normal_range, c.unit AS cat_unit FROM lab_order_tests t LEFT JOIN lab_tests_catalog c ON c.id = t.test_id WHERE t.lab_order_id = :o', [':o' => $o['id']]) as $t) {
            $own[(int) $t['id']] = $t;
        }

        $err = [];
        $this->db->beginTransaction();
        try {
            foreach ($raw as $i => $r) {
                $tid = (int) ($r['id'] ?? 0);
                if (!isset($own[$tid])) { $err["tests.$i"] = ['Unknown test in this order.']; continue; }
                $val = trim(strip_tags((string) ($r['result_value'] ?? '')));
                if (mb_strlen($val) > 200) { $err["tests.$i"] = ['A result can be at most 200 characters.']; continue; }
                $unit = trim(strip_tags((string) ($r['result_unit'] ?? ''))) ?: ($own[$tid]['cat_unit'] ?? null);
                $flag = $r['result_flag'] ?? null;
                if ($flag !== null && $flag !== '' && !in_array($flag, self::FLAGS, true)) { $err["tests.$i"] = ['Flag must be normal, low, high or critical.']; continue; }
                // The doctor's own flag wins; otherwise compare with the reference range.
                $flag = ($flag === null || $flag === '') ? InvestigationHelper::autoFlag($val === '' ? null : $val, $own[$tid]['normal_range']) : $flag;
                $note = mb_substr(trim(strip_tags((string) ($r['notes'] ?? ''))), 0, 300);
                $this->db->update('lab_order_tests', [
                    'result_value' => $val === '' ? null : $val,
                    'result_unit'  => $val === '' ? null : ($unit !== null ? mb_substr($unit, 0, 50) : null),
                    'result_flag'  => $val === '' ? null : $flag,
                    'notes'        => $note === '' ? null : $note,
                ], 'id = :id', [':id' => $tid]);
            }
            if ($err) { $this->db->rollback(); Response::validationError($err); }

            $summary = mb_substr(trim(strip_tags((string) ($this->request->get('result_summary') ?? ''))), 0, 2000);
            $total   = count($own);
            $in      = (int) $this->db->getRow('SELECT COUNT(*) n FROM lab_order_tests WHERE lab_order_id = :o AND result_value IS NOT NULL', [':o' => $o['id']])['n'];

            $data = ['result_summary' => $summary === '' ? null : $summary];
            $cost = $this->request->get('actual_cost');
            if ($cost !== null && $cost !== '') {
                if (!is_numeric($cost) || (float) $cost < 0 || (float) $cost > 999999) { $this->db->rollback(); Response::validationError(['actual_cost' => ['Enter a valid amount.']]); }
                $data['actual_cost'] = round((float) $cost, 2);
            }
            // All results in → report ready; some → processing; none → leave as it was.
            if ($in > 0 && in_array($o['status'], ['ordered', 'sample_collected', 'processing'], true)) {
                $data['status'] = $in >= $total ? 'report_ready' : 'processing';
            }
            if ($in >= $total && $total > 0 && empty($o['result_date'])) $data['result_date'] = CommonHelper::now();
            $this->db->update('lab_orders', $data, 'id = :id', [':id' => $o['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            try { $this->db->rollback(); } catch (Throwable) { /* already rolled back */ }
            throw $e;
        }
        Response::success(['order' => $this->labPayload($this->lab((int) $p['id'], $d))], 'Results saved');
    }

    public function labStatus(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->lab((int) $p['id'], $d);
        $next = $this->nextStatus($o['status'], self::LAB_FLOW, 'sample_collected,processing,report_ready,delivered,cancelled');
        $data = ['status' => $next];
        if ($next === 'report_ready' && empty($o['result_date'])) $data['result_date'] = CommonHelper::now();
        $this->db->update('lab_orders', $data, 'id = :id', [':id' => $o['id']]);
        Response::success(['order' => $this->labPayload($this->lab((int) $p['id'], $d))], 'Order updated');
    }

    public function uploadLabFile(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->lab((int) $p['id'], $d);
        $path = $this->storeFile($d, $o['result_file_url']);
        $this->db->update('lab_orders', ['result_file_url' => $path], 'id = :id', [':id' => $o['id']]);
        Response::success(['order' => $this->labPayload($this->lab((int) $p['id'], $d))], 'Report attached');
    }

    public function labFile(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $this->stream($this->lab((int) $p['id'], $d)['result_file_url']);
    }

    // ── Imaging ──────────────────────────────────────────────────────────────
    public function radDetail(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        Response::success(['order' => $this->radPayload($this->rad((int) $p['id'], $d))], 'Order fetched');
    }

    public function saveRadReport(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->rad((int) $p['id'], $d);
        if ($o['status'] === 'cancelled') Response::error('A cancelled order can’t take a report.', 409);

        $this->validate(['impression' => 'nullable|string|max:2000', 'radiologist_report' => 'nullable|string|max:8000']);
        $clean = fn (string $k, int $max) => ($v = mb_substr(trim(strip_tags((string) ($this->request->get($k) ?? ''))), 0, $max)) === '' ? null : $v;
        $imp = $clean('impression', 2000);
        $rep = $clean('radiologist_report', 8000);

        $data = ['impression' => $imp, 'radiologist_report' => $rep];
        if (($imp !== null || $rep !== null) && $o['status'] === 'ordered') {
            $data['status'] = 'report_ready';
            $data['result_date'] = CommonHelper::now();
        }
        $this->db->update('radiology_orders', $data, 'id = :id', [':id' => $o['id']]);
        Response::success(['order' => $this->radPayload($this->rad((int) $p['id'], $d))], 'Report saved');
    }

    public function radStatus(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->rad((int) $p['id'], $d);
        $next = $this->nextStatus($o['status'], self::RAD_FLOW, 'ordered,report_ready,delivered,cancelled');
        $data = ['status' => $next];
        if ($next === 'report_ready' && empty($o['result_date'])) $data['result_date'] = CommonHelper::now();
        $this->db->update('radiology_orders', $data, 'id = :id', [':id' => $o['id']]);
        Response::success(['order' => $this->radPayload($this->rad((int) $p['id'], $d))], 'Order updated');
    }

    public function uploadRadFile(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $o = $this->rad((int) $p['id'], $d);
        $path = $this->storeFile($d, $o['report_file_url']);
        $this->db->update('radiology_orders', ['report_file_url' => $path], 'id = :id', [':id' => $o['id']]);
        Response::success(['order' => $this->radPayload($this->rad((int) $p['id'], $d))], 'Report attached');
    }

    public function radFile(array $p): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $this->stream($this->rad((int) $p['id'], $d)['report_file_url']);
    }

    // ── Internals ────────────────────────────────────────────────────────────
    private function lab(int $id, int $doctorId): array
    {
        $o = $this->db->getRow(
            "SELECT o.* FROM lab_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.deleted_at IS NULL
             WHERE o.id = :i AND o.doctor_id = :d AND o.deleted_at IS NULL", [':i' => $id, ':d' => $doctorId]);
        if (!$o) Response::notFound('Order not found.');
        return $o;
    }

    private function rad(int $id, int $doctorId): array
    {
        $o = $this->db->getRow(
            "SELECT o.* FROM radiology_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.deleted_at IS NULL
             WHERE o.id = :i AND o.doctor_id = :d AND o.deleted_at IS NULL", [':i' => $id, ':d' => $doctorId]);
        if (!$o) Response::notFound('Order not found.');
        return $o;
    }

    private function patientRow(int $patientId): array
    {
        return $this->db->getRow('SELECT id, uhid, name, sex, age_years, date_of_birth, mobile, known_allergies FROM patients WHERE id = :i', [':i' => $patientId]) ?? [];
    }

    private function labPayload(array $o): array
    {
        // Previous value of each test for this patient: is it going up, down, steady?
        $prev = [];
        foreach ($this->db->getRows('SELECT id, test_id FROM lab_order_tests WHERE lab_order_id = :o AND test_id IS NOT NULL', [':o' => $o['id']]) as $t) {
            $r = $this->db->getRow(
                "SELECT t.result_value, t.result_unit, t.result_flag, COALESCE(lo.result_date, lo.order_date) AS at
                 FROM lab_order_tests t JOIN lab_orders lo ON lo.id = t.lab_order_id
                 WHERE lo.patient_id = :p AND lo.doctor_id = :d AND t.test_id = :t AND lo.id <> :o AND t.result_value IS NOT NULL
                 ORDER BY COALESCE(lo.result_date, lo.order_date) DESC, lo.id DESC LIMIT 1",
                [':p' => $o['patient_id'], ':d' => $o['doctor_id'], ':t' => $t['test_id'], ':o' => $o['id']]
            );
            if ($r) $prev[(int) $t['id']] = ['value' => $r['result_value'], 'unit' => $r['result_unit'], 'flag' => $r['result_flag'], 'date' => $r['at']];
        }
        return InvestigationHelper::shapeLab($o, true, $prev) + ['patient' => self::patientOf($this->patientRow((int) $o['patient_id']) + [], true)];
    }

    private function radPayload(array $o): array
    {
        return InvestigationHelper::shapeRadiology($o) + ['patient' => self::patientOf($this->patientRow((int) $o['patient_id']), true)];
    }

    private static function patientOf(array $r, bool $plain = false): array
    {
        $name = $plain ? $r['name'] : $r['patient_name'];
        $sex  = $plain ? $r['sex'] : $r['patient_sex'];
        return [
            'id' => (int) ($plain ? $r['id'] : $r['patient_id']), 'name' => $name, 'sex' => $sex, 'uhid' => $r['uhid'],
            'mobile' => $r['mobile'] ?? null, 'known_allergies' => $r['known_allergies'] ?? null,
            'age_label' => PatientController::ageLabel(['date_of_birth' => $r['date_of_birth'], 'age_years' => $r['age_years']]),
        ];
    }

    private function nextStatus(string $current, array $flow, string $allowedList): string
    {
        $this->validate(['status' => 'required|in:' . $allowedList]);
        $next = (string) $this->request->get('status');
        if (!in_array($next, $flow[$current] ?? [], true)) {
            Response::error("An order that is “{$current}” can’t be marked “{$next}”.", 409);
        }
        return $next;
    }

    /** Validate and store an uploaded report photo; returns its path relative to storage/. */
    private function storeFile(int $doctorId, ?string $old): string
    {
        $f = $this->request->file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) Response::validationError(['file' => ['Choose a photo of the report.']]);
        if ($f['error'] !== UPLOAD_ERR_OK) Response::validationError(['file' => ['The upload did not complete. Please try again.']]);
        if ($f['size'] > 5 * 1024 * 1024) Response::validationError(['file' => ['The photo is too large (max 5 MB).']]);

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::FILE_MIME[$mime])) Response::validationError(['file' => ['Only JPG, PNG or WebP photos are accepted.']]);

        $dir = ROOT_PATH . '/storage/reports/' . $doctorId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create the reports folder.');
        $name = bin2hex(random_bytes(16)) . '.' . self::FILE_MIME[$mime];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not save the uploaded file.');

        if ($old) $this->removeFile($old);
        return "reports/$doctorId/$name";
    }

    private function removeFile(string $rel): void
    {
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/reports');
        if ($full && $base && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) @unlink($full);
    }

    private function stream(?string $rel): never
    {
        if (!$rel) Response::notFound('No report is attached.');
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/reports');
        if (!$full || !$base || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) Response::notFound('The attached file is missing.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=3600');
        readfile($full);
        exit;
    }
}
