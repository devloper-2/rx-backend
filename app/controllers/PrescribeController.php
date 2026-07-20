<?php
//prescribeController.php
declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

class PrescribeController extends BaseController
{
    public function __construct($request, $authUser)
    {
        parent::__construct($request, $authUser);
    }

    
    // ─────────────────────────────────────────
    // CREATE PRESCRIPTION
    // ─────────────────────────────────────────
    public function create()
    {
        $input = json_decode(file_get_contents("php://input"), true);

        $doctorId = $this->authUser['doctor_id'];

        if (empty($input['patient_id']) && empty($input['patient'])) {
          echo json_encode([
            "success" => false,
            "message" => "patient_id or patient object required"
          ]);
          return;
        }

        try {

            // ─────────────────────────
            // 1. PATIENT HANDLING
            // ─────────────────────────

            if (!empty($input['patient_id'])) {

                // EXISTING PATIENT
                $patientId = $input['patient_id'];

                // OPTIONAL: verify belongs to doctor
                $exists = $this->db->getRow(
                    "SELECT id FROM patients WHERE id = ? AND doctor_id = ? AND deleted_at IS NULL",
                    [$patientId, $doctorId]
                );

                if (!$exists) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Invalid patient_id"
                    ]);
                    return;
                }

            }    else if (!empty($input['patient'])) {

                // NEW PATIENT CREATE
                $p = $input['patient'];

                if (empty($p['name'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Patient name required"
                    ]);
                    return;
                }

                // Generate UHID
                $uhid = 'UHID-' . $doctorId . '-' . strtoupper(substr(uniqid(), -6));
                //creates 26 or 32 unique character long string based on time and takes last six characters and turn them in upper case 

                $ageYears = null;

                if (!empty($p['date_of_birth'])) {
                    $dob = new DateTime($p['date_of_birth']);
                    $today = new DateTime();
                    $ageYears = $today->diff($dob)->y;
                }

                $patientId = $this->db->insert('patients', [
                    'doctor_id' => $doctorId,
                    'uhid' => $uhid,
                    'name' => $p['name'],
                    'mobile' => $p['mobile'] ?? null,
                    'email' => $p['email'] ?? null,
                    'date_of_birth' => $p['date_of_birth'] ?? null,
                    'age_years' => $ageYears,
                    'sex' => $p['sex'] ?? 'male',
                    'address' => $p['address'] ?? null,
                    'occupation' => $p['occupation'] ?? null,
                    'blood_group' => $p['blood_group'] ?? null,
                    'known_allergies' => $p['known_allergies'] ?? null,
                    'chronic_conditions' => $p['chronic_conditions'] ?? null,
                    'whatsapp_number' => $p['whatsapp_number'] ?? null,
                ]);

            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "patient_id or patient object required"
                ]);
                return;
            }

            // ─────────────────────────
            // 2. INSERT PRESCRIPTION
            // ─────────────────────────

            // ALWAYS CALCULATE BMI
            $bmi = null;

            if (!empty($input['weight_kg']) && !empty($input['height_cm'])) {
                $heightM = $input['height_cm'] / 100;
                $bmi = $input['weight_kg'] / ($heightM * $heightM);
                $bmi = round($bmi, 2);
            }
            
            $prescriptionId = $this->db->insert('prescriptions', [
                'clinic_id' => $input['clinic_id'] ?? null,
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,

                'weight_kg' => $input['weight_kg'] ?? null,
                'height_cm' => $input['height_cm'] ?? null,
                'bmi' => $bmi,

                'bp_systolic' => $input['bp_systolic'] ?? null,
                'bp_diastolic' => $input['bp_diastolic'] ?? null,
                'pulse_bpm' => $input['pulse_bpm'] ?? null,
                'temperature_f' => $input['temperature_f'] ?? null,
                'spo2_pct' => $input['spo2_pct'] ?? null,
                'rbs_mg_dl' => $input['rbs_mg_dl'] ?? null,
                'respiratory_rate' => $input['respiratory_rate'] ?? null,

                'chief_complaint' => $input['chief_complaint'] ?? null,
                'diagnosis' => $input['diagnosis'] ?? null,
                'clinical_notes' => $input['clinical_notes'] ?? null,

                'followup_date' => !empty($input['followup_date'])
                ? date('Y-m-d', strtotime($input['followup_date']))
                : null,

                'followup_note' => $input['followup_note'] ?? null,

                'status' => 'final'
            ]);

            // ─────────────────────────
            // MEDICINES
            // ─────────────────────────
            if (!empty($input['medicines'])) {
                foreach ($input['medicines'] as $med) {

                    if (empty($med['medicine_name'])) continue;

                    $this->db->insert('prescription_medicines', [
                        'prescription_id' => $prescriptionId,
                        'medicine_id' => $med['medicine_id'] ?? null,
                        'medicine_name' => $med['medicine_name'],
                        'category_id' => $med['category_id'] ?? null,
                        'total_qty' => $med['total_qty'] ?? 0,
                        'qty_unit' => $med['qty_unit'] ?? 'tablet',
                        'duration_days' => $med['duration_days'] ?? null,
                        'duration_note' => $med['duration_note'] ?? null,
                        'dose_schedule' => json_encode($med['dose_schedule'] ?? []),
                        'freq_display' => $med['freq_display'] ?? null,
                        'instructions' => $med['instructions'] ?? null
                    ]);
                }
            }

            // ─────────────────────────
            // SYMPTOMS
            // ─────────────────────────
            if (!empty($input['symptoms'])) {
                foreach ($input['symptoms'] as $sym) {
                    $this->db->insert('prescription_symptoms', [
                        'prescription_id' => $prescriptionId,
                        'symptom_id' => $sym['symptom_id'] ?? null,
                        'custom_symptom' => $sym['custom_symptom'] ?? null,
                        'severity' => $sym['severity'] ?? 'mild',
                        'duration' => $sym['duration'] ?? null,
                        'notes' => $sym['notes'] ?? null
                    ]);
                }
            }

            // ─────────────────────────
            // ADVICE
            // ─────────────────────────
            if (!empty($input['advice'])) {
                foreach ($input['advice'] as $adv) {

                    if (empty($adv['text'])) continue;

                    $this->db->insert('prescription_advice', [
                        'prescription_id' => $prescriptionId,
                        'template_id' => $adv['template_id'] ?? null,
                        'custom_text' => $adv['text'],
                        'category' => $adv['category'] ?? 'other'
                    ]);
                }
            }

            echo json_encode([
                "success" => true,
                "message" => "Prescription created",
                "data" => [
                    "prescription_id" => $prescriptionId
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
        }
    }


    //FOR UPDATE
    public function update($params)
    {
        $id = $params['id'];
        $input = json_decode(file_get_contents("php://input"), true);
        $doctorId = $this->authUser['doctor_id'];

        try {

        $exists = $this->db->getRow(
            "SELECT * FROM prescriptions
             WHERE id=?
             AND doctor_id=?
             AND deleted_at IS NULL",
            [$id,$doctorId]
        );

        if(!$exists){
            echo json_encode([
                "success"=>false,
                "message"=>"Prescription not found"
            ]);
            return;
        }

        //----------------------------------------
        // BMI
        //----------------------------------------

        $bmi=null;

        if(!empty($input['weight_kg']) && !empty($input['height_cm'])){

            $heightM=$input['height_cm']/100;

            $bmi=$input['weight_kg']/($heightM*$heightM);

            $bmi=round($bmi,2);
        }

        //----------------------------------------
        // UPDATE PRESCRIPTION
        //----------------------------------------

        $this->db->update(
            "prescriptions",
            [
                "clinic_id"=>$input["clinic_id"] ?? null,
                "weight_kg"=>$input["weight_kg"] ?? null,
                "height_cm"=>$input["height_cm"] ?? null,
                "bmi"=>$bmi,
                "bp_systolic"=>$input["bp_systolic"] ?? null,
                "bp_diastolic"=>$input["bp_diastolic"] ?? null,
                "pulse_bpm"=>$input["pulse_bpm"] ?? null,
                "temperature_f"=>$input["temperature_f"] ?? null,
                "spo2_pct"=>$input["spo2_pct"] ?? null,
                "rbs_mg_dl"=>$input["rbs_mg_dl"] ?? null,
                "respiratory_rate"=>$input["respiratory_rate"] ?? null,
                "diagnosis"=>$input["diagnosis"] ?? null,
                "chief_complaint" => $input["chief_complaint"] ?? null,
                "clinical_notes"=>$input["clinical_notes"] ?? null,
                //"followup_date"=>$input["followup_date"] ?? null,
                "followup_date" => !empty($input["followup_date"])
                    ? date("Y-m-d", strtotime($input["followup_date"]))
                    : null,
                "followup_note"=>$input["followup_note"] ?? null
            ],
            "id=:id",
            [
                ":id"=>$id
            ]
        );

        $this->db->update(
            "prescription_medicines",
            [
                "deleted_at"=>date("Y-m-d H:i:s")
            ],
            "prescription_id=:id",
            [
                ":id"=>$id
            ]
        );

        if(!empty($input["medicines"])){

            foreach($input["medicines"] as $med){

                $this->db->insert(
                    "prescription_medicines",
                    [
                        "prescription_id"=>$id,
                        "medicine_id"=>$med["medicine_id"] ?? null,
                        "medicine_name"=>$med["medicine_name"],
                        "category_id"=>$med["category_id"] ?? null,
                        "total_qty"=>$med["total_qty"] ?? 0,
                        "qty_unit"=>$med["qty_unit"] ?? "piece",
                        "duration_days"=>$med["duration_days"] ?? null,
                        "duration_note"=>$med["duration_note"] ?? null,
                        "dose_schedule"=>json_encode($med["dose_schedule"] ?? []),
                        "freq_display"=>$med["freq_display"] ?? null,
                        "instructions"=>$med["instructions"] ?? null
                    ]
                );
            }
        }

        $this->db->update(
            "prescription_symptoms",
            [
                "deleted_at"=>date("Y-m-d H:i:s")
            ],
            "prescription_id=:id",
            [
                ":id"=>$id
            ]
        );

        if(!empty($input["symptoms"])){

            foreach($input["symptoms"] as $sym){

                $this->db->insert(
                    "prescription_symptoms",
                    [
                        "prescription_id"=>$id,
                        "symptom_id"=>$sym["symptom_id"] ?? null,
                        "custom_symptom"=>$sym["custom_symptom"] ?? null,
                        "severity"=>$sym["severity"] ?? "mild",
                        "duration"=>$sym["duration"] ?? null,
                        "notes"=>$sym["notes"] ?? null
                    ]
                );
            }
        }

        $this->db->update(
            "prescription_advice",
            [
                "deleted_at"=>date("Y-m-d H:i:s")
            ],
            "prescription_id=:id",
            [
                ":id"=>$id
            ]
        );

        if (!empty($input["advice"])) {

            foreach ($input["advice"] as $adv) {

                if (empty($adv["text"])) {
                    continue;
                }

                $this->db->insert(
                    "prescription_advice",
                    [
                        "prescription_id" => $id,
                        "template_id" => $adv["template_id"] ?? null,
                        "custom_text" => $adv["text"],
                        "category" => $adv["category"] ?? "other"
                    ]
                );
            }
        }

        echo json_encode([
            "success"=>true,
            "message"=>"Prescription updated"
        ]);

    }
    catch(Exception $e){

        echo json_encode([
            "success"=>false,
            "message"=>$e->getMessage()
        ]);

    }


    }


    // ─────────────────────────────────────────
    // GET FULL PRESCRIPTION
    // ─────────────────────────────────────────
    
    public function get($params)
    {
      $id = $params['id'];

      try {

        // =========================
        //PRESCRIPTION
        // =========================
        $prescription = $this->db->getRow(
            "SELECT * FROM prescriptions WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$prescription) {
            echo json_encode([
                "success" => false,
                "message" => "Prescription not found"
            ]);
            return;
        }

        // =========================
        // PATIENT
        // =========================
        $patient = $this->db->getRow(
            "SELECT * FROM patients WHERE id = ? AND deleted_at IS NULL",
            //"SELECT * FROM patients WHERE id = ?",
            [$prescription['patient_id']]
        );

        $prescription['patient'] = $patient;

        // =========================
        // MEDICINES
        // =========================
        $medicines = $this->db->getRows(
            "SELECT * FROM prescription_medicines WHERE prescription_id = ? AND deleted_at IS NULL",
            [$id]
        );

        foreach ($medicines as &$med) {
            $med['dose_schedule'] = json_decode($med['dose_schedule'], true);
        }

        $prescription['medicines'] = $medicines;

        // =========================
        // SYMPTOMS
        // =========================
        $symptoms = $this->db->getRows(
            "SELECT ps.*, s.name as symptom_name
             FROM prescription_symptoms ps
             LEFT JOIN symptoms s ON s.id = ps.symptom_id
             WHERE ps.prescription_id = ? AND ps.deleted_at IS NULL",
             //WHERE ps.prescription_id = ? AND deleted_at IS NULL",
            [$id]
        );

        $prescription['symptoms'] = $symptoms;

        // =========================
        //ADVICE
        // =========================
        $advice = $this->db->getRows(
            "SELECT * FROM prescription_advice WHERE prescription_id = ? AND deleted_at IS NULL",
            [$id]
        );

        $prescription['advice'] = $advice;

        // =========================
        // FINAL RESPONSE
        // =========================
        echo json_encode([
            "success" => true,
            "data" => $prescription
        ]);

      } catch (Exception $e) {
        echo json_encode([
          "success" => false,
          "message" => $e->getMessage()
        ]);
      }
    } 
     
        
     
    // ─────────────────────────────────────────
    // DELETE (SOFT)
    // ─────────────────────────────────────────
    public function delete($params)
    {
      $id = is_array($params['id']) ? $params['id'][0] : $params['id'];

      try {

        //  DELETE MAIN PRESCRIPTION
        $this->db->update(
            'prescriptions',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'id = :id',
            [':id' => $id]
        );

        // DELETE MEDICINES
        $this->db->update(
            'prescription_medicines',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'prescription_id = :id',
            [':id' => $id]
        );

        // DELETE SYMPTOMS
        $this->db->update(
            'prescription_symptoms',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'prescription_id = :id',
            [':id' => $id]
        );

        //  DELETE ADVICE
        $this->db->update(
            'prescription_advice',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'prescription_id = :id',
            [':id' => $id]
        );

        echo json_encode([
            "success" => true,
            "message" => "Prescription and related data soft deleted"
        ]);

      } catch (Exception $e) {
        echo json_encode([
          "success" => false,
          "message" => $e->getMessage()
        ]);
      }
    }


    // ─────────────────────────────────────────
    // SEARCH PATIENT
    // ─────────────────────────────────────────
public function searchPatients()
{
    $input = json_decode(file_get_contents("php://input"), true);
    $keyword = trim($input['keyword'] ?? '');
    $doctorId = $this->authUser['doctor_id'];

    $patients = $this->db->getRows(
        "SELECT
            id,
            name,
            mobile,
            email,
            sex,
            blood_group,
            date_of_birth,
            occupation,
            address
        FROM patients
        WHERE doctor_id=?
        AND deleted_at IS NULL
        AND (
            name LIKE ?
            OR mobile LIKE ?
        )
        ORDER BY name
        LIMIT 20",
        [
            $doctorId,
            "%$keyword%",
            "%$keyword%"
        ]
    );

    echo json_encode([
        "success"=>true,
        "data"=>$patients
    ]);
}

// ─────────────────────────────────────────
// SEARCH MEDICINES
// ─────────────────────────────────────────
public function searchMedicines()
{
    $input=json_decode(file_get_contents("php://input"),true);
    $keyword=$input['keyword'] ?? '';
    $doctorId=$this->authUser['doctor_id'];

    $rows=$this->db->getRows(
        "SELECT
            id,
            name,
            strength,
            default_unit,
            category_id
        FROM medicines
        WHERE
        is_active=1
        AND (
            is_system=1
            OR doctor_id=?
        )
        AND name LIKE ?
        ORDER BY is_system DESC,name
        LIMIT 20",
        [
            $doctorId,
            "%$keyword%"
        ]
    );

    echo json_encode([
        "success"=>true,
        "data"=>$rows
    ]);
}


// ─────────────────────────────────────────
// SEARCH SYMPTOMS
// POST : /symptoms/search
// ─────────────────────────────────────────
public function searchSymptoms()
{
    $input=json_decode(file_get_contents("php://input"),true);

    $keyword=$input['keyword'] ?? '';

    $doctorId=$this->authUser['doctor_id'];

    $rows=$this->db->getRows(
        "SELECT
            id,
            name
        FROM symptoms
        WHERE
        is_active=1
        AND (
            is_system=1
            OR doctor_id=?
        )
        AND name LIKE ?
        ORDER BY is_system DESC,name
        LIMIT 20",
        [
            $doctorId,
            "%$keyword%"
        ]
    );

    echo json_encode([
        "success"=>true,
        "data"=>$rows
    ]);
}


// ─────────────────────────────────────────
// SEARCH ADVICE TEMPLATES
// POST : /advice/search
// ─────────────────────────────────────────
public function searchAdvice()
{
    $input=json_decode(file_get_contents("php://input"),true);
    $keyword=$input['keyword'] ?? '';
    $doctorId=$this->authUser['doctor_id'];

    $rows=$this->db->getRows(
        "SELECT
            id,
            text,
            category
        FROM advice_templates
        WHERE
        is_active=1
        AND (
            is_system=1
            OR doctor_id=?
        )
        AND text LIKE ?
        ORDER BY is_system DESC,text
        LIMIT 20",
        [
            $doctorId,
            "%$keyword%"
        ]
    );

    echo json_encode([
        "success"=>true,
        "data"=>$rows
    ]);
}

// ─────────────────────────────────────────
// GET CLINICS
// GET : /clinic/list
// ─────────────────────────────────────────
public function getClinics()
{
    $doctorId=$this->authUser['doctor_id'];

    $rows=$this->db->getRows(
        "SELECT
            id,
            name
        FROM clinics
        WHERE
        doctor_id=?
        AND deleted_at IS NULL
        ORDER BY is_primary DESC,name",
        [
            $doctorId
        ]
    );

    echo json_encode([
        "success"=>true,
        "data"=>$rows
    ]);
}
    



  }

