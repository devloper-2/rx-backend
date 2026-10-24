<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * CatalogController — lookup data for the prescription editor.
 *
 * GET  /catalog/medicines?q=   → medicines()      search, or "my frequently used" when q is blank
 * POST /catalog/medicines      → addMedicine()    doctor-private medicine
 * GET  /catalog/lab-tests?q=&codes= → labTests()   search / frequently ordered / by short codes
 * GET  /catalog/symptoms       → symptoms()
 * GET  /catalog/advice         → advice()
 * GET  /catalog/diagnoses      → diagnoses()      the doctor's own recent diagnoses
 *
 * Each medicine carries `last_used`: how *this* doctor last prescribed it
 * (schedule, duration, instructions) so the editor can pre-fill it.
 */
class CatalogController extends BaseController
{
    private const UNITS = ['piece', 'ml', 'mg', 'gm', 'kg', 'IU', 'mcg', 'drop', 'puff', 'sachet', 'patch', 'suppository', 'unit', 'other'];

    // ── GET /catalog/medicines ───────────────────────────────────────────────
    public function medicines(): void
    {
        $doctor = $this->requireDoctor();
        $d      = (int) $doctor['id'];
        $q      = trim((string) $this->request->get('q', ''));
        $limit  = $this->getLimit(20, 40);

        if ($q === '') {
            $items = $this->frequentlyUsed($d, 12);
            Response::success(['items' => $items, 'kind' => 'frequent'], 'Medicines fetched');
        }

        $like   = '%' . addcslashes($q, '%_\\') . '%';
        $prefix = addcslashes($q, '%_\\') . '%';
        $rows   = $this->db->getRows(
            'SELECT m.id, m.name, m.generic_name, m.strength, m.default_unit, m.category_id, c.name AS category_name
             FROM medicines m JOIN medicine_categories c ON c.id = m.category_id
             WHERE m.is_active = 1 AND (m.doctor_id IS NULL OR m.doctor_id = :d)
               AND (m.name LIKE :q1 OR m.generic_name LIKE :q2)
             ORDER BY (m.doctor_id IS NOT NULL) DESC, (m.name LIKE :p) DESC, m.name
             LIMIT ' . (int) $limit,
            [':d' => $d, ':q1' => $like, ':q2' => $like, ':p' => $prefix]
        );

        $last  = $this->lastUsed($d, array_column($rows, 'name'));
        $items = array_map(fn ($m) => [
            'medicine_id'   => (int) $m['id'],
            'name'          => $m['name'],
            'generic_name'  => $m['generic_name'],
            'strength'      => $m['strength'],
            'unit'          => $m['default_unit'],
            'category_id'   => (int) $m['category_id'],
            'category_name' => $m['category_name'],
            'last_used'     => $last[mb_strtolower($m['name'])] ?? null,
        ], $rows);

        Response::success(['items' => $items, 'kind' => 'search'], 'Medicines fetched');
    }

    // ── POST /catalog/medicines ──────────────────────────────────────────────
    public function addMedicine(): void
    {
        $doctor = $this->requireDoctor();
        $this->validate([
            'name'         => 'required|string|min:2|max:200',
            'generic_name' => 'nullable|string|max:200',
            'strength'     => 'nullable|string|max:100',
            'category_id'  => 'required|integer',
            'default_unit' => 'nullable|in:' . implode(',', self::UNITS),
        ]);

        $name = self::clean((string) $this->request->get('name'));
        $cat  = $this->db->getRow('SELECT id, name, default_unit FROM medicine_categories WHERE id = :i AND is_active = 1', [':i' => (int) $this->request->get('category_id')]);
        if (!$cat) {
            Response::validationError(['category_id' => ['Choose a valid medicine type.']]);
        }

        $exists = $this->db->getRow(
            'SELECT id FROM medicines WHERE LOWER(name) = LOWER(:n) AND (doctor_id IS NULL OR doctor_id = :d) AND is_active = 1 LIMIT 1',
            [':n' => $name, ':d' => $doctor['id']]
        );
        if ($exists) {
            Response::error('A medicine with this name already exists.', 409);
        }

        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;
        $id  = $this->db->insert('medicines', [
            'category_id'  => $cat['id'],
            'name'         => $name,
            'generic_name' => $opt('generic_name'),
            'strength'     => $opt('strength'),
            'default_unit' => $this->request->get('default_unit') ?: $cat['default_unit'],
            'is_system'    => 0,
            'doctor_id'    => $doctor['id'],
            'is_active'    => 1,
        ]);

        Response::created(['medicine' => [
            'medicine_id' => $id, 'name' => $name, 'generic_name' => $opt('generic_name'), 'strength' => $opt('strength'),
            'unit' => $this->request->get('default_unit') ?: $cat['default_unit'], 'category_id' => (int) $cat['id'],
            'category_name' => $cat['name'], 'last_used' => null,
        ]], 'Medicine added');
    }

    // ── GET /catalog/lab-tests ───────────────────────────────────────────────
    public function labTests(): void
    {
        $d     = (int) $this->requireDoctor()['id'];
        $q     = trim((string) $this->request->get('q', ''));
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->get('codes', '')))));
        $cols  = 'id, name, short_code, category, normal_range, unit';

        if ($codes) {                                   // panel shortcuts resolve their tests by code
            $in   = implode(',', array_fill(0, count($codes), '?'));
            $rows = $this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND short_code IN ($in)", $codes);
            Response::success(['items' => array_map([self::class, 'labTest'], $rows), 'kind' => 'codes'], 'Tests fetched');
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $rows = $this->db->getRows(
                "SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND (name LIKE :a OR short_code LIKE :b OR category LIKE :c)
                 ORDER BY (short_code = :exact) DESC, (name LIKE :p) DESC, name LIMIT 40",
                [':a' => $like, ':b' => $like, ':c' => $like, ':exact' => $q, ':p' => addcslashes($q, '%_\\') . '%']
            );
            Response::success(['items' => array_map([self::class, 'labTest'], $rows), 'kind' => 'search'], 'Tests fetched');
        }

        // Blank: what this doctor orders most, padded with the everyday basics.
        $top  = $this->db->getRows(
            "SELECT t.test_id, COUNT(*) c FROM lab_order_tests t JOIN lab_orders o ON o.id = t.lab_order_id
             WHERE o.doctor_id = :d AND t.test_id IS NOT NULL GROUP BY t.test_id ORDER BY c DESC LIMIT 12", [':d' => $d]);
        $ids  = array_map('intval', array_column($top, 'test_id'));
        $basic = ['CBC', 'FBS', 'PPBS', 'HBA1C', 'LIPID', 'LFT', 'CR', 'TSH', 'URINE_RE', 'MALARIA', 'DENGUE_NS1', 'CRP'];
        $in   = implode(',', array_fill(0, count($basic), '?'));
        $b    = $this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND short_code IN ($in)", $basic);
        $byId = [];
        if ($ids) {
            $in2 = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE id IN ($in2)", $ids) as $r) $byId[(int) $r['id']] = $r;
        }
        $items = [];
        foreach ($ids as $id) if (isset($byId[$id])) $items[(int) $id] = $byId[$id];
        foreach ($b as $r) $items[(int) $r['id']] ??= $r;
        Response::success(['items' => array_map([self::class, 'labTest'], array_values($items)), 'kind' => 'frequent'], 'Tests fetched');
    }

    private static function labTest(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'short_code' => $r['short_code'], 'category' => $r['category'], 'normal_range' => $r['normal_range'], 'unit' => $r['unit']];
    }

    // ── GET /catalog/symptoms | advice | diagnoses ───────────────────────────
    public function symptoms(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            'SELECT id, name, category FROM symptoms WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = :d) ORDER BY name',
            [':d' => $d]
        );
        // Most used by this doctor first, so their own top complaints sit at the front.
        $used = $this->db->getRows(
            'SELECT ps.symptom_id, COUNT(*) c FROM prescription_symptoms ps JOIN prescriptions p ON p.id = ps.prescription_id
             WHERE p.doctor_id = :d AND ps.symptom_id IS NOT NULL AND ps.deleted_at IS NULL GROUP BY ps.symptom_id',
            [':d' => $d]
        );
        $count = array_column($used, 'c', 'symptom_id');
        usort($rows, fn ($a, $b) => ((int) ($count[$b['id']] ?? 0) <=> (int) ($count[$a['id']] ?? 0)) ?: strcmp($a['name'], $b['name']));
        Response::success(['items' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category']], $rows)], 'Symptoms fetched');
    }

    public function advice(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            'SELECT id, category, text FROM advice_templates WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = :d) ORDER BY category, id',
            [':d' => $d]
        );
        Response::success(['items' => array_map(fn ($r) => ['id' => (int) $r['id'], 'category' => $r['category'], 'text' => $r['text']], $rows)], 'Advice fetched');
    }

    public function diagnoses(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            "SELECT diagnosis, COUNT(*) c FROM prescriptions
             WHERE doctor_id = :d AND deleted_at IS NULL AND status <> 'cancelled' AND diagnosis IS NOT NULL AND diagnosis <> ''
             GROUP BY diagnosis ORDER BY c DESC, MAX(visit_date) DESC LIMIT 15",
            [':d' => $d]
        );
        Response::success(['items' => array_column($rows, 'diagnosis')], 'Diagnoses fetched');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    /** The doctor's most prescribed medicines, each with how they last prescribed it. */
    private function frequentlyUsed(int $doctorId, int $limit): array
    {
        $top = $this->db->getRows(
            "SELECT LOWER(pm.medicine_name) AS k, COUNT(*) c, MAX(p.visit_date) last_at
             FROM prescription_medicines pm JOIN prescriptions p ON p.id = pm.prescription_id
             WHERE p.doctor_id = :d AND p.deleted_at IS NULL AND p.status = 'final' AND pm.deleted_at IS NULL
             GROUP BY LOWER(pm.medicine_name) ORDER BY c DESC, last_at DESC LIMIT " . (int) $limit,
            [':d' => $doctorId]
        );
        if (!$top) return [];

        $last = $this->lastUsed($doctorId, array_column($top, 'k'));
        $out  = [];
        foreach ($top as $t) {
            $lu = $last[$t['k']] ?? null;
            if (!$lu) continue;
            $m = $lu['medicine_id'] ? $this->db->getRow(
                'SELECT m.*, c.name AS category_name FROM medicines m JOIN medicine_categories c ON c.id = m.category_id WHERE m.id = :i',
                [':i' => $lu['medicine_id']]
            ) : null;
            $out[] = [
                'medicine_id'   => $m ? (int) $m['id'] : null,
                'name'          => $lu['medicine_name'],
                'generic_name'  => $m['generic_name'] ?? null,
                'strength'      => $m['strength'] ?? null,
                'unit'          => $lu['qty_unit'] ?: ($m['default_unit'] ?? 'piece'),
                'category_id'   => $m ? (int) $m['category_id'] : null,
                'category_name' => $m['category_name'] ?? null,
                'times_used'    => (int) $t['c'],
                'last_used'     => $lu,
            ];
        }
        return $out;
    }

    /** Latest final-prescription settings per medicine name (lower-cased key). */
    private function lastUsed(int $doctorId, array $names): array
    {
        $names = array_values(array_unique(array_map('mb_strtolower', array_filter($names))));
        if (!$names) return [];
        $in   = implode(',', array_fill(0, count($names), '?'));
        $rows = $this->db->getRows(
            "SELECT pm.* FROM prescription_medicines pm
             JOIN prescriptions p ON p.id = pm.prescription_id
             WHERE p.doctor_id = ? AND p.deleted_at IS NULL AND p.status = 'final' AND pm.deleted_at IS NULL
               AND LOWER(pm.medicine_name) IN ($in)
             ORDER BY p.visit_date DESC, pm.id DESC",
            array_merge([$doctorId], $names)
        );
        $out = [];
        foreach ($rows as $r) {
            $k = mb_strtolower($r['medicine_name']);
            if (isset($out[$k])) continue;
            $out[$k] = [
                'medicine_id'   => $r['medicine_id'] === null ? null : (int) $r['medicine_id'],
                'medicine_name' => $r['medicine_name'],
                'qty_unit'      => $r['qty_unit'],
                'dose_schedule' => json_decode((string) $r['dose_schedule'], true) ?: [],
                'freq_display'  => $r['freq_display'],
                'duration_days' => $r['duration_days'] === null ? null : (int) $r['duration_days'],
                'duration_note' => $r['duration_note'],
                'instructions'  => $r['instructions'],
            ];
        }
        return $out;
    }
}
