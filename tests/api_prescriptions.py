#!/usr/bin/env python3
"""Prescriptions API: create/draft/finalize/cancel, validation, catalog, clinic, isolation."""
from _client import *
import datetime
SOON = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()

tok, _ = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")

s, r = call("POST", "patients", {"name": "Ravi Kumar", "sex": "male", "age_years": 42, "known_allergies": "Penicillin"}, tok)
pid = r["data"]["patient"]["id"]
s, r = call("POST", "patients", {"name": "Other Person", "sex": "female", "age_years": 30}, tok)
pid_b = r["data"]["patient"]["id"]
s, r = call("POST", "patients", {"name": "Beta Patient", "sex": "male", "age_years": 20}, tok2)
pid_other = r["data"]["patient"]["id"]

s, r = call("GET", "catalog/medicines?q=dolo", token=tok)
dolo = r["data"]["items"][0]
check("medicine search finds Dolo 650", s == 200 and dolo["name"] == "Dolo 650" and dolo["generic_name"] == "Paracetamol", r)
s, r = call("GET", "catalog/medicines?q=paracetamol", token=tok)
check("search matches generic names too", len(r["data"]["items"]) >= 3, r)
s, r = call("GET", "catalog/medicines?q=", token=tok)
check("new doctor has no 'frequently used' yet", s == 200 and r["data"]["items"] == [] and r["data"]["kind"] == "frequent", r)
s, r = call("GET", "catalog/medicines?q=zz%25", token=tok)
check("wildcards are literal in medicine search", r["data"]["items"] == [], r)

s, r = call("GET", "catalog/symptoms", token=tok); symp = r["data"]["items"][0]
check("symptom catalog", s == 200 and len(r["data"]["items"]) >= 50, r)
s, r = call("GET", "catalog/advice", token=tok); adv = r["data"]["items"][0]
check("advice catalog", s == 200 and len(r["data"]["items"]) >= 30, r)

rx = {
    "patient_id": pid, "status": "final",
    "weight_kg": 70, "height_cm": 175, "bp_systolic": 150, "bp_diastolic": 95, "pulse_bpm": 88, "temperature_f": 100.4, "spo2_pct": 97, "rbs_mg_dl": 140,
    "chief_complaint": "Fever and body ache for 3 days", "symptoms": [{"symptom_id": symp["id"], "severity": "moderate", "duration": "3 days"}, {"custom_symptom": "Chills"}],
    "diagnosis": "Viral fever",
    "medicines": [
        {"medicine_id": dolo["medicine_id"], "medicine_name": "Dolo 650", "qty_unit": "piece", "duration_days": 5, "instructions": "After food",
         "dose_schedule": [{"slot": "night", "qty": 1, "food_relation": "after"}, {"slot": "morning", "qty": 1, "food_relation": "after"}]},
        {"medicine_name": "My own syrup", "qty_unit": "ml", "duration_days": 3, "dose_schedule": [{"slot": "morning", "qty": 5}, {"slot": "afternoon", "qty": 5}, {"slot": "night", "qty": 5}]},
        {"medicine_name": "Paracetamol SOS", "dose_schedule": [], "freq_display": "SOS", "duration_note": "if fever > 100F"},
    ],
    "advice": [{"template_id": adv["id"]}, {"custom_text": "Review if not better in 3 days", "category": "other"}],
    "followup_date": SOON, "followup_note": "Recheck temperature",
}
import time; time.sleep(1.2)  # so "updated_at" differs from the patients' creation second
s, r = call("POST", "prescriptions", rx, tok)
check("create final 201", s == 201, r); p1 = r["data"]["prescription"]
check("BMI computed", p1["vitals"]["bmi"] == 22.86, p1["vitals"])
m = p1["medicines"]
check("slots sorted, freq label 1-0-1", m[0]["freq_display"] == "1-0-1" and [x["slot"] for x in m[0]["dose_schedule"]] == ["morning", "night"], m[0])
check("total qty = per-day x days", m[0]["total_qty"] == 10 and m[1]["total_qty"] == 45, (m[0], m[1]))
check("SOS medicine kept with its note", m[2]["freq_display"] == "SOS" and m[2]["times_per_day"] == 0 and m[2]["duration_note"] == "if fever > 100F", m[2])
check("symptoms + advice returned with names", len(p1["symptoms"]) == 2 and p1["symptoms"][0]["name"] == symp["name"] and p1["advice"][0]["text"] == adv["text"], p1)
check("patient snapshot with allergy", p1["patient"]["known_allergies"] == "Penicillin" and p1["patient"]["age_label"] == "42 y", p1["patient"])
check("free-text medicine has no catalog id", m[1]["medicine_id"] is None, m[1])

s, r = call("GET", f"prescriptions/{p1['id']}", token=tok)
check("detail round-trips", s == 200 and r["data"]["prescription"]["diagnosis"] == "Viral fever", r)
s, r = call("GET", f"patients/{pid}/prescriptions", token=tok)
check("patient history", s == 200 and r["data"]["items"][0]["medicines_count"] == 3 and r["data"]["items"][0]["medicine_names"][0] == "Dolo 650", r)
s, r = call("GET", "prescriptions", token=tok)
check("recent list has patient name", s == 200 and r["data"]["items"][0]["patient"]["name"] == "Ravi Kumar", r)
s, r = call("GET", "prescriptions?since=2099-01-01", token=tok)
check("'since' filter", r["data"]["items"] == [], r)
s, r = call("GET", "patients", token=tok)
check("patient floats to the top after a visit", r["data"]["items"][0]["id"] == pid, [i["name"] for i in r["data"]["items"]])

s, r = call("GET", "catalog/medicines?q=", token=tok)
check("'frequently used' now lists Dolo 650 with last_used", s == 200 and r["data"]["items"][0]["name"] == "Dolo 650"
      and r["data"]["items"][0]["last_used"]["freq_display"] == "1-0-1" and r["data"]["items"][0]["last_used"]["instructions"] == "After food", r)
s, r = call("GET", "catalog/medicines?q=dolo", token=tok)
check("search results carry last_used too", r["data"]["items"][0]["last_used"]["duration_days"] == 5, r)
s, r = call("GET", "catalog/diagnoses", token=tok)
check("recent diagnoses", r["data"]["items"] == ["Viral fever"], r)

# immutability
s, r = call("PUT", f"prescriptions/{p1['id']}", rx, tok)
check("final prescription cannot be edited (409)", s == 409, r)
s, r = call("DELETE", f"prescriptions/{p1['id']}", token=tok)
check("final prescription cannot be deleted (409)", s == 409, r)
s, r = call("POST", f"prescriptions/{p1['id']}/cancel", token=tok)
check("final can be cancelled", s == 200 and r["data"]["prescription"]["status"] == "cancelled", r)
s, r = call("POST", f"prescriptions/{p1['id']}/cancel", token=tok)
check("cancelled can't be cancelled again", s == 409, r)

# drafts
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft"}, tok)
check("empty draft is allowed", s == 201 and r["data"]["prescription"]["status"] == "draft", r); d1 = r["data"]["prescription"]
s, r = call("POST", f"prescriptions/{d1['id']}/finalize", token=tok)
check("empty draft cannot be finalised", s == 422, r)
s, r = call("PUT", f"prescriptions/{d1['id']}", {"diagnosis": "Acute gastritis", "medicines": [{"medicine_name": "Pan 40", "duration_days": 7, "dose_schedule": [{"slot": "morning", "qty": 1, "food_relation": "before"}]}]}, tok)
check("draft update replaces content", s == 200 and r["data"]["prescription"]["diagnosis"] == "Acute gastritis" and len(r["data"]["prescription"]["medicines"]) == 1, r)
s, r = call("PUT", f"prescriptions/{d1['id']}", {"diagnosis": "Acute gastritis", "medicines": [{"medicine_name": "Pan 40", "dose_schedule": [{"slot": "morning", "qty": 1}]}, {"medicine_name": "Domstal 10", "dose_schedule": [{"slot": "night", "qty": 1}]}]}, tok)
check("second update replaces again (no duplicates)", len(r["data"]["prescription"]["medicines"]) == 2, r)
s, r = call("POST", f"prescriptions/{d1['id']}/finalize", token=tok)
check("finalise draft", s == 200 and r["data"]["prescription"]["status"] == "final", r)
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft", "diagnosis": "Cold"}, tok); d3 = r["data"]["prescription"]
s, r = call("PUT", f"prescriptions/{d3['id']}", {"status": "final", "diagnosis": "Common cold", "medicines": [{"medicine_name": "Cetirizine 10", "duration_days": 3, "dose_schedule": [{"slot": "night", "qty": 1}]}]}, tok)
check("PUT with status=final saves and finalises a draft in one step", s == 200 and r["data"]["prescription"]["status"] == "final" and r["data"]["prescription"]["diagnosis"] == "Common cold", r)
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft"}, tok); d4 = r["data"]["prescription"]
s, r = call("PUT", f"prescriptions/{d4['id']}", {"status": "final"}, tok)
check("PUT status=final still requires content", s == 422, r)
s, r = call("PUT", f"prescriptions/{d4['id']}", {"status": "archived", "diagnosis": "x"}, tok)
check("PUT with a bad status -> 422", s == 422, r)
call("DELETE", f"prescriptions/{d4['id']}", token=tok)
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft", "diagnosis": "x"}, tok); d2 = r["data"]["prescription"]
s, r = call("DELETE", f"prescriptions/{d2['id']}", token=tok)
check("draft can be deleted", s == 200, r)
s, r = call("GET", f"prescriptions/{d2['id']}", token=tok)
check("deleted draft is gone", s == 404, r)

# validation
def bad(why, **over):
    body = dict({"patient_id": pid, "diagnosis": "Test"}, **over)
    s, r = call("POST", "prescriptions", body, tok)
    check(f"rejects: {why}", s == 422, (s, r))
bad("no content on a final prescription", diagnosis="")
bad("unknown patient", patient_id=99999999)
bad("other doctor's patient", patient_id=pid_other)
bad("temperature 150F", temperature_f=150)
bad("SpO2 120", spo2_pct=120)
bad("diastolic >= systolic", bp_systolic=100, bp_diastolic=120)
bad("non-numeric weight", weight_kg="heavy")
bad("future visit date", visit_date="2999-01-01")
bad("past follow-up date", followup_date="2001-01-01")
bad("bad status", status="archived")
bad("medicine without a name", medicines=[{"medicine_name": "", "dose_schedule": []}])
bad("unknown slot", medicines=[{"medicine_name": "X", "dose_schedule": [{"slot": "noon", "qty": 1}]}])
bad("same slot twice", medicines=[{"medicine_name": "X", "dose_schedule": [{"slot": "morning", "qty": 1}, {"slot": "morning", "qty": 1}]}])
bad("zero dose", medicines=[{"medicine_name": "X", "dose_schedule": [{"slot": "morning", "qty": 0}]}])
bad("duration 0 days", medicines=[{"medicine_name": "X", "duration_days": 0, "dose_schedule": [{"slot": "morning", "qty": 1}]}])
bad("31 medicines", medicines=[{"medicine_name": f"M{i}", "dose_schedule": []} for i in range(31)])
bad("diagnosis too long", diagnosis="x" * 2001)
bad("medicines not a list", medicines="paracetamol")

s, r = call("POST", "prescriptions", {"patient_id": pid, "diagnosis": "<b>Cold</b>", "medicines": [{"medicine_name": "<script>x</script>Syrup", "dose_schedule": []}]}, tok)
check("html is stripped from text", s == 201 and r["data"]["prescription"]["diagnosis"] == "Cold" and r["data"]["prescription"]["medicines"][0]["medicine_name"] == "xSyrup", r)

# isolation
for method, path, body in [("GET", f"prescriptions/{d1['id']}", None), ("PUT", f"prescriptions/{d1['id']}", rx),
                           ("POST", f"prescriptions/{d1['id']}/cancel", None), ("GET", f"patients/{pid}/prescriptions", None)]:
    s, r = call(method, path, body, tok2)
    check(f"other doctor cannot {method} {path.split('/')[0]}", s == 404 or s == 422, (s, r))
s, r = call("GET", "prescriptions", token=tok2)
check("other doctor's list is empty", r["data"]["items"] == [], r)

# custom medicine
s, r = call("POST", "catalog/medicines", {"name": "ClinicSpecial 10", "category_id": 1, "strength": "10mg"}, tok)
check("add custom medicine", s == 201 and r["data"]["medicine"]["unit"] == "piece", r)
s, r = call("POST", "catalog/medicines", {"name": "clinicspecial 10", "category_id": 1}, tok)
check("duplicate custom medicine -> 409", s == 409, r)
s, r = call("POST", "catalog/medicines", {"name": "Dolo 650", "category_id": 1}, tok)
check("cannot duplicate a system medicine -> 409", s == 409, r)
s, r = call("POST", "catalog/medicines", {"name": "Zed", "category_id": 9999}, tok)
check("unknown category -> 422", s == 422, r)
s, r = call("GET", "catalog/medicines?q=clinicspecial", token=tok2)
check("custom medicines are private to the doctor", r["data"]["items"] == [], r)
s, r = call("GET", "catalog/medicines?q=clinicspecial", token=tok)
check("...but visible to their owner, first", r["data"]["items"][0]["name"] == "ClinicSpecial 10", r)

# clinic + summary
s, r = call("GET", "doctor/clinic", token=tok2)
check("no clinic yet", s == 200 and r["data"]["clinic"] is None, r)
s, r = call("PUT", "doctor/clinic", {"name": ""}, tok2)
check("clinic name required", s == 422, r)
s, r = call("PUT", "doctor/clinic", {"name": "Sunrise Clinic", "address_line1": "12 MG Road", "city": "Pune", "phone": "02012345678", "timings": "Mon-Sat 9-1, 5-8"}, tok2)
check("save clinic", s == 200 and r["data"]["clinic"]["city"] == "Pune", r)
s, r = call("PUT", "doctor/clinic", {"name": "Sunrise Clinic 2"}, tok2)
s, r = call("GET", "doctor/clinic", token=tok2)
check("update keeps a single clinic", r["data"]["clinic"]["name"] == "Sunrise Clinic 2" and r["data"]["clinic"]["city"] is None, r)
s, r = call("POST", "prescriptions", {"patient_id": pid_other, "diagnosis": "Cold"}, tok2)
check("prescription carries the clinic letterhead", r["data"]["prescription"]["clinic"]["name"] == "Sunrise Clinic 2", r)

s, r = call("GET", "dashboard/summary?since=2000-01-01", token=tok)
check("summary counts finals only (cancelled excluded)", r["data"]["prescriptions_total"] == 3 and r["data"]["drafts"] == 0 and r["data"]["prescriptions_today"] == 3, r)
s, r = call("GET", "dashboard/summary?since=2099-01-01", token=tok)
check("'today' follows the since parameter", r["data"]["prescriptions_today"] == 0, r)
finish()
