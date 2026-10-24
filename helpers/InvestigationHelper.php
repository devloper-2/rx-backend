<?php

declare(strict_types=1);

/**
 * InvestigationHelper — lab and imaging orders that hang off a prescription.
 *
 * A prescription carries `investigations`:
 *   { "lab": { "tests": [{"test_id": 3} | {"custom_test": "..."}], "urgency": "routine", "notes": "..." },
 *     "imaging": [{ "modality": "x-ray", "body_part": "Chest", "view_position": "PA", "contrast": false, ... }] }
 * At most one lab order per prescription (all tests together) and one radiology
 * order per imaging study. Orders are rewritten while the prescription is a
 * draft and become the doctor's "awaiting results" list once it is final.
 */
class InvestigationHelper
{
    public const URGENCY    = ['routine', 'urgent', 'stat'];
    public const MODALITIES = ['x-ray', 'ultrasound', 'ct_scan', 'mri', 'ecg', 'echo', 'endoscopy', 'pet_scan', 'mammography', 'dexa', 'other'];
    public const LAB_PENDING = ['ordered', 'sample_collected', 'processing'];
    public const MODALITY_LABELS = [
        'x-ray' => 'X-ray', 'ultrasound' => 'Ultrasound', 'ct_scan' => 'CT scan', 'mri' => 'MRI', 'ecg' => 'ECG', 'echo' => 'Echo',
        'endoscopy' => 'Endoscopy', 'pet_scan' => 'PET scan', 'mammography' => 'Mammography', 'dexa' => 'DEXA', 'other' => 'Imaging',
    ];

    // ── Parsing ──────────────────────────────────────────────────────────────
    /** @return array{lab: ?array, imaging: array}  (aborts nothing: problems are added to $err) */
    public static function parse(mixed $raw, array &$err): array
    {
        $empty = ['lab' => null, 'imaging' => []];
        if ($raw === null || $raw === '' || $raw === []) return $empty;
        if (!is_array($raw)) { $err['investigations'] = ['Investigations must be an object.']; return $empty; }

        $db = Database::getInstance();
        $clean = fn (mixed $v, int $max) => mb_substr(trim(strip_tags((string) ($v ?? ''))), 0, $max);

        // ── lab ──
        $lab = null;
        $rl  = $raw['lab'] ?? null;
        if (is_array($rl) && !empty($rl['tests'])) {
            if (!is_array($rl['tests']) || count($rl['tests']) > 40) {
                $err['investigations.lab'] = ['Choose up to 40 tests per order.'];
            } else {
                $tests = [];
                $seen  = [];
                foreach (array_values($rl['tests']) as $t) {
                    if (!is_array($t)) continue;
                    $tid = isset($t['test_id']) && (int) $t['test_id'] > 0 ? (int) $t['test_id'] : null;
                    if ($tid !== null) {
                        if (isset($seen[$tid])) continue;
                        if (!$db->getRow('SELECT id FROM lab_tests_catalog WHERE id = :i AND is_active = 1', [':i' => $tid])) continue;
                        $seen[$tid] = true;
                        $tests[] = ['test_id' => $tid, 'custom_test' => null];
                    } else {
                        $name = $clean($t['custom_test'] ?? '', 200);
                        if ($name !== '' && !isset($seen[mb_strtolower($name)])) {
                            $seen[mb_strtolower($name)] = true;
                            $tests[] = ['test_id' => null, 'custom_test' => $name];
                        }
                    }
                }
                if ($tests) {
                    $urg = $rl['urgency'] ?? 'routine';
                    $lab = [
                        'tests'    => $tests,
                        'urgency'  => in_array($urg, self::URGENCY, true) ? $urg : 'routine',
                        'notes'    => ($n = $clean($rl['notes'] ?? '', 1000)) === '' ? null : $n,
                        'lab_name' => ($n = $clean($rl['lab_name'] ?? '', 200)) === '' ? null : $n,
                    ];
                }
            }
        }

        // ── imaging ──
        $imaging = [];
        $ri = $raw['imaging'] ?? [];
        if (!empty($ri)) {
            if (!is_array($ri) || count($ri) > 10) {
                $err['investigations.imaging'] = ['Choose up to 10 imaging studies.'];
            } else {
                foreach (array_values($ri) as $i) {
                    if (!is_array($i)) continue;
                    $mod = $i['modality'] ?? null;
                    if (!in_array($mod, self::MODALITIES, true)) { $err['investigations.imaging'] = ['Choose a valid imaging type.']; continue; }
                    $urg = $i['urgency'] ?? 'routine';
                    $imaging[] = [
                        'modality'            => $mod,
                        'body_part'           => ($n = $clean($i['body_part'] ?? '', 200)) === '' ? null : $n,
                        'view_position'       => ($n = $clean($i['view_position'] ?? '', 100)) === '' ? null : $n,
                        'contrast'            => !empty($i['contrast']) ? 1 : 0,
                        'clinical_indication' => ($n = $clean($i['clinical_indication'] ?? '', 1000)) === '' ? null : $n,
                        'urgency'             => in_array($urg, self::URGENCY, true) ? $urg : 'routine',
                    ];
                }
            }
        }

        return ['lab' => $lab, 'imaging' => $imaging];
    }

    public static function isEmpty(array $inv): bool
    {
        return empty($inv['lab']) && empty($inv['imaging']);
    }

    // ── Writing ──────────────────────────────────────────────────────────────
    /** Replace every order of this prescription with the given set (drafts and new prescriptions only). */
    public static function save(int $rxId, int $doctorId, int $patientId, array $inv): void
    {
        $db = Database::getInstance();
        self::wipe($rxId);
        $now = CommonHelper::now();

        if (!empty($inv['lab'])) {
            $lab   = $inv['lab'];
            $orderId = $db->insert('lab_orders', [
                'prescription_id' => $rxId, 'doctor_id' => $doctorId, 'patient_id' => $patientId,
                'lab_name' => $lab['lab_name'], 'order_date' => $now, 'urgency' => $lab['urgency'],
                'clinical_notes' => $lab['notes'], 'status' => 'ordered',
            ]);
            foreach ($lab['tests'] as $t) {
                $db->insert('lab_order_tests', ['lab_order_id' => $orderId] + $t);
            }
        }
        foreach ($inv['imaging'] as $i) {
            $db->insert('radiology_orders', [
                'prescription_id' => $rxId, 'doctor_id' => $doctorId, 'patient_id' => $patientId, 'status' => 'ordered',
            ] + $i);
        }
    }

    private static function wipe(int $rxId): void
    {
        $db  = Database::getInstance();
        $ids = array_column($db->getRows('SELECT id FROM lab_orders WHERE prescription_id = :r', [':r' => $rxId]), 'id');
        foreach ($ids as $id) $db->delete('lab_order_tests', 'lab_order_id = :o', [':o' => $id]);
        $db->delete('lab_orders', 'prescription_id = :r', [':r' => $rxId]);
        $db->delete('radiology_orders', 'prescription_id = :r', [':r' => $rxId]);
    }

    /** A cancelled prescription withdraws orders that have not produced anything yet. */
    public static function cancelFor(int $rxId): void
    {
        $db = Database::getInstance();
        $db->update('lab_orders', ['status' => 'cancelled'], "prescription_id = :r AND status IN ('ordered','sample_collected','processing')", [':r' => $rxId]);
        $db->update('radiology_orders', ['status' => 'cancelled'], "prescription_id = :r AND status = 'ordered'", [':r' => $rxId]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────
    /** Investigations of one prescription, shaped for the app (view, PDF and editor). */
    public static function forPrescription(int $rxId): array
    {
        $db  = Database::getInstance();
        $lab = $db->getRow('SELECT * FROM lab_orders WHERE prescription_id = :r AND deleted_at IS NULL LIMIT 1', [':r' => $rxId]);
        $rad = $db->getRows('SELECT * FROM radiology_orders WHERE prescription_id = :r AND deleted_at IS NULL ORDER BY id', [':r' => $rxId]);
        return [
            'lab'     => $lab ? self::shapeLab($lab, true) : null,
            'imaging' => array_map([self::class, 'shapeRadiology'], $rad),
        ];
    }

    public static function shapeLab(array $o, bool $withTests = false, ?array $previous = null): array
    {
        $db   = Database::getInstance();
        $rows = $db->getRows(
            'SELECT t.*, c.name AS cat_name, c.short_code, c.normal_range, c.unit AS cat_unit, c.category
             FROM lab_order_tests t LEFT JOIN lab_tests_catalog c ON c.id = t.test_id
             WHERE t.lab_order_id = :o ORDER BY t.id', [':o' => $o['id']]
        );
        $tests = array_map(fn ($t) => [
            'id'           => (int) $t['id'],
            'test_id'      => $t['test_id'] === null ? null : (int) $t['test_id'],
            'name'         => $t['cat_name'] ?? $t['custom_test'],
            'short_code'   => $t['short_code'],
            'category'     => $t['category'],
            'custom'       => $t['test_id'] === null,
            'normal_range' => $t['normal_range'],
            'unit'         => $t['result_unit'] ?? $t['cat_unit'],
            'result_value' => $t['result_value'],
            // The column defaults to 'normal'; a flag only means something once there is a result.
            'result_flag'  => ($t['result_value'] === null || $t['result_value'] === '') ? null : $t['result_flag'],
            'notes'        => $t['notes'],
            'previous'     => $previous[(int) $t['id']] ?? null,
        ], $rows);

        $withResults = count(array_filter($tests, fn ($t) => $t['result_value'] !== null && $t['result_value'] !== ''));
        $out = [
            'id'              => (int) $o['id'],
            'prescription_id' => (int) $o['prescription_id'],
            'status'          => $o['status'],
            'urgency'         => $o['urgency'],
            'order_date'      => $o['order_date'],
            'clinical_notes'  => $o['clinical_notes'],
            'lab_name'        => $o['lab_name'],
            'result_date'     => $o['result_date'],
            'result_summary'  => $o['result_summary'],
            'has_file'        => !empty($o['result_file_url']),
            'tests_count'     => count($tests),
            'results_in'      => $withResults,
            'abnormal_count'  => count(array_filter($tests, fn ($t) => in_array($t['result_flag'], ['low', 'high', 'critical'], true))),
        ];
        if ($withTests) $out['tests'] = $tests;
        return $out;
    }

    public static function shapeRadiology(array $o): array
    {
        return [
            'id'                  => (int) $o['id'],
            'prescription_id'     => (int) $o['prescription_id'],
            'status'              => $o['status'],
            'urgency'             => $o['urgency'],
            'modality'            => $o['modality'],
            'modality_label'      => self::MODALITY_LABELS[$o['modality']] ?? 'Imaging',
            'body_part'           => $o['body_part'],
            'view_position'       => $o['view_position'],
            'contrast'            => (bool) $o['contrast'],
            'clinical_indication' => $o['clinical_indication'],
            'result_date'         => $o['result_date'],
            'impression'          => $o['impression'],
            'radiologist_report'  => $o['radiologist_report'],
            'has_file'            => !empty($o['report_file_url']),
            'created_at'          => $o['created_at'],
        ];
    }

    // ── Flags ────────────────────────────────────────────────────────────────
    /**
     * low / high / normal from a numeric value and a range such as "12–17 g/dL",
     * "<140 mg/dL" or ">40 mg/dL". Returns null when either side can't be read
     * (qualitative tests), leaving the flag for the doctor to set.
     */
    public static function autoFlag(?string $value, ?string $range): ?string
    {
        if ($value === null || $range === null) return null;
        $v = str_replace(',', '', trim($value));
        if (!is_numeric($v)) return null;
        $v = (float) $v;
        $range = str_replace(['–', '—', '−'], '-', $range);

        if (preg_match('/^\s*<\s*=?\s*([\d.,]+)/', $range, $m)) {
            return $v > (float) str_replace(',', '', $m[1]) ? 'high' : 'normal';
        }
        if (preg_match('/^\s*>\s*=?\s*([\d.,]+)/', $range, $m)) {
            return $v < (float) str_replace(',', '', $m[1]) ? 'low' : 'normal';
        }
        if (preg_match('/^\s*([\d.,]+)\s*-\s*([\d.,]+)/', $range, $m)) {
            $lo = (float) str_replace(',', '', $m[1]);
            $hi = (float) str_replace(',', '', $m[2]);
            return $v < $lo ? 'low' : ($v > $hi ? 'high' : 'normal');
        }
        return null;
    }
}
