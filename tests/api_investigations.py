#!/usr/bin/env python3
"""Lab & imaging orders: ordering inside a prescription, results, flags, status flow, private report photos."""
from _client import *
import urllib.request, urllib.error, uuid, base64, os

PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==")

def upload(path, token, content, filename="report.png"):
    b = uuid.uuid4().hex
    body = (f"--{b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n").encode() + content + f"\r\n--{b}--\r\n".encode()
    req = urllib.request.Request(f"{BASE}/{path}", data=body, method="POST", headers={"Content-Type": f"multipart/form-data; boundary={b}", "Authorization": "Bearer " + token})
    try:
        with urllib.request.urlopen(req) as r: return r.status, json.load(r)
    except urllib.error.HTTPError as e: return e.code, json.load(e)

def raw_get(path, token=None):
    req = urllib.request.Request(f"{BASE}/{path}", headers={"Authorization": "Bearer " + token} if token else {})
    try:
        with urllib.request.urlopen(req) as r: return r.status, r.headers.get("Content-Type"), r.read()
    except urllib.error.HTTPError as e: return e.code, e.headers.get("Content-Type"), e.read()

tok, _ = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
pid = call("POST", "patients", {"name": "Ravi Kumar", "sex": "male", "age_years": 42}, tok)[1]["data"]["patient"]["id"]

# ── catalog ──
s, r = call("GET", "catalog/lab-tests", token=tok)
codes = [i["short_code"] for i in r["data"]["items"]]
check("blank query offers the everyday basics", s == 200 and r["data"]["kind"] == "frequent" and "CBC" in codes and "FBS" in codes, codes)
s, r = call("GET", "catalog/lab-tests?q=sugar", token=tok)
check("search by name", {"FBS", "PPBS", "RBS"} <= {i["short_code"] for i in r["data"]["items"]}, r)
s, r = call("GET", "catalog/lab-tests?q=hba1c", token=tok)
check("search by short code", r["data"]["items"][0]["short_code"] == "HBA1C", r)
s, r = call("GET", "catalog/lab-tests?codes=CBC,MALARIA,NOPE", token=tok)
check("resolve panel shortcuts by code (unknown ignored)", sorted(i["short_code"] for i in r["data"]["items"]) == ["CBC", "MALARIA"], r)
s, r = call("GET", "catalog/lab-tests?q=zz%25", token=tok)
check("wildcards are literal", r["data"]["items"] == [], r)
T = {i["short_code"]: i["id"] for i in call("GET", "catalog/lab-tests?codes=CBC,FBS,HDL,HB,HBA1C,WIDAL,TSH", token=tok)[1]["data"]["items"]}

# ── ordering inside a prescription ──
inv = {"lab": {"tests": [{"test_id": T["FBS"]}, {"test_id": T["HDL"]}, {"test_id": T["HB"]}, {"test_id": T["WIDAL"]}, {"test_id": T["FBS"]}, {"custom_test": "Special enzyme panel"}, {"test_id": 99999999}],
               "urgency": "urgent", "notes": "Fasting sample"},
       "imaging": [{"modality": "x-ray", "body_part": "Chest", "view_position": "PA", "clinical_indication": "Cough 3 weeks"}, {"modality": "ecg"}]}
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft", "diagnosis": "Work-up", "investigations": inv}, tok)
check("draft with investigations", s == 201, r); draft = r["data"]["prescription"]
lab = draft["investigations"]["lab"]
check("duplicates and unknown tests are dropped; custom test kept", [t["name"] for t in lab["tests"]] == ["Fasting Blood Sugar", "HDL Cholesterol", "Haemoglobin", "Widal Test", "Special enzyme panel"], [t["name"] for t in lab["tests"]])
check("urgency and notes stored", lab["urgency"] == "urgent" and lab["clinical_notes"] == "Fasting sample" and lab["status"] == "ordered", lab)
check("imaging orders stored", [(i["modality"], i["body_part"]) for i in draft["investigations"]["imaging"]] == [("x-ray", "Chest"), ("ecg", None)], draft["investigations"])

s, r = call("GET", "orders", token=tok)
check("draft prescriptions do not create pending work", r["data"]["items"] == [], r)
s, r = call("PUT", f"prescriptions/{draft['id']}", {"diagnosis": "Work-up", "investigations": {"lab": {"tests": [{"test_id": T["CBC"]}]}}}, tok)
check("updating a draft replaces its orders (no duplicates, imaging gone)", [t["name"] for t in r["data"]["prescription"]["investigations"]["lab"]["tests"]] == ["Complete Blood Count"] and r["data"]["prescription"]["investigations"]["imaging"] == [], r)
s, r = call("PUT", f"prescriptions/{draft['id']}", {"status": "final", "diagnosis": "Work-up", "investigations": inv}, tok)
check("finalise with the full set", s == 200 and r["data"]["prescription"]["status"] == "final", r); rx = r["data"]["prescription"]
lab_id = rx["investigations"]["lab"]["id"]; xray_id, ecg_id = [i["id"] for i in rx["investigations"]["imaging"]]

s, r = call("POST", "prescriptions", {"patient_id": pid, "investigations": {"lab": {"tests": [{"test_id": T["TSH"]}]}}}, tok)
check("a prescription may be investigations only", s == 201, r); tsh_rx = r["data"]["prescription"]
s, r = call("POST", "prescriptions", {"patient_id": pid, "investigations": {"lab": {"tests": [{"test_id": 99999999}]}}}, tok)
check("...but not an empty order", s == 422, r)
for why, body in [("41 tests", {"lab": {"tests": [{"custom_test": f"T{i}"} for i in range(41)]}}),
                  ("unknown imaging type", {"imaging": [{"modality": "teleportation"}]}),
                  ("11 imaging studies", {"imaging": [{"modality": "x-ray"} for _ in range(11)]}),
                  ("investigations not an object", "CBC please")]:
    s, r = call("POST", "prescriptions", {"patient_id": pid, "diagnosis": "x", "investigations": body}, tok)
    check(f"rejects: {why}", s == 422, (s, r))

# ── pending list ──
s, r = call("GET", "orders", token=tok)
items = r["data"]["items"]
check("pending list: urgent first, then oldest", items[0]["kind"] == "lab" and items[0]["urgency"] == "urgent" and len(items) == 4, [(i["kind"], i["urgency"]) for i in items])
check("lab row title lists tests", items[0]["title"].startswith("Fasting Blood Sugar, HDL Cholesterol, Haemoglobin +2"), items[0]["title"])
check("imaging row title", any(i["kind"] == "imaging" and i["title"] == "X-ray Chest (PA)" for i in items), [i["title"] for i in items])
check("filter by kind", all(i["kind"] == "imaging" for i in call("GET", "orders?kind=imaging", token=tok)[1]["data"]["items"]), "")
s, r = call("GET", f"orders?patient_id={pid}", token=tok); check("filter by patient", len(r["data"]["items"]) == 4, r)
check("summary counts the pending orders", call("GET", "dashboard/summary", token=tok)[1]["data"]["orders_pending"] == 4, "")

# ── lab results ──
s, r = call("GET", f"lab-orders/{lab_id}", token=tok)
o = r["data"]["order"]; tests = {t["name"]: t for t in o["tests"]}
check("detail has tests with reference ranges", tests["Haemoglobin"]["normal_range"] == "12–17 g/dL" and o["patient"]["name"] == "Ravi Kumar", o)
res = [{"id": tests["Fasting Blood Sugar"]["id"], "result_value": "126"}, {"id": tests["HDL Cholesterol"]["id"], "result_value": "35"},
       {"id": tests["Haemoglobin"]["id"], "result_value": "14.2"}, {"id": tests["Widal Test"]["id"], "result_value": "Positive 1:160", "result_flag": "high"}]
s, r = call("PUT", f"lab-orders/{lab_id}/results", {"tests": res}, tok)
o = r["data"]["order"]; tests = {t["name"]: t for t in o["tests"]}
check("flags are worked out from the reference range", (tests["Fasting Blood Sugar"]["result_flag"], tests["HDL Cholesterol"]["result_flag"], tests["Haemoglobin"]["result_flag"]) == ("high", "low", "normal"), tests)
check("the doctor's own flag wins; an un-resulted test has no flag", tests["Widal Test"]["result_flag"] == "high" and tests["Special enzyme panel"]["result_flag"] is None, tests)
check("units default from the catalog", tests["Haemoglobin"]["unit"] == "g/dL", tests["Haemoglobin"])
check("partial results -> processing, counts add up", o["status"] == "processing" and o["results_in"] == 4 and o["abnormal_count"] == 3, o)
s, r = call("PUT", f"lab-orders/{lab_id}/results", {"result_summary": "Mild hyperglycaemia", "tests": [{"id": tests["Special enzyme panel"]["id"], "result_value": "see report", "result_flag": "normal", "notes": "reviewed"}]}, tok)
o = r["data"]["order"]
check("all results in -> report_ready with a date and summary", o["status"] == "report_ready" and o["result_date"] and o["result_summary"] == "Mild hyperglycaemia", o)
for why, t in [("bad flag", {"id": tests["Haemoglobin"]["id"], "result_value": "1", "result_flag": "scary"}), ("test of another order", {"id": 99999999, "result_value": "1"}),
               ("value over 200 chars", {"id": tests["Haemoglobin"]["id"], "result_value": "x" * 201})]:
    s, r = call("PUT", f"lab-orders/{lab_id}/results", {"tests": [t]}, tok)
    check(f"rejects results: {why}", s == 422, (s, r))
s, r = call("PUT", f"lab-orders/{lab_id}/results", {"tests": [{"id": tests["Haemoglobin"]["id"], "result_value": "9.8"}]}, tok)
check("correcting a value re-flags it", [t for t in r["data"]["order"]["tests"] if t["name"] == "Haemoglobin"][0]["result_flag"] == "low", r)

# previous value
s, r = call("POST", "prescriptions", {"patient_id": pid, "investigations": {"lab": {"tests": [{"test_id": T["HB"]}]}}}, tok)
lab2 = r["data"]["prescription"]["investigations"]["lab"]["id"]
s, r = call("GET", f"lab-orders/{lab2}", token=tok)
hb = r["data"]["order"]["tests"][0]
check("previous result of the same test is shown", hb["previous"] and hb["previous"]["value"] == "9.8" and hb["previous"]["flag"] == "low", hb)

# status flow
s, r = call("POST", f"lab-orders/{lab_id}/status", {"status": "delivered"}, tok)
check("report_ready -> delivered", s == 200 and r["data"]["order"]["status"] == "delivered", r)
s, r = call("POST", f"lab-orders/{lab_id}/status", {"status": "processing"}, tok)
check("delivered is final", s == 409, r)
s, r = call("POST", f"lab-orders/{lab2}/status", {"status": "sample_collected"}, tok)
check("ordered -> sample_collected", s == 200, r)
s, r = call("POST", f"lab-orders/{lab2}/status", {"status": "bogus"}, tok)
check("unknown status -> 422", s == 422, r)
s, r = call("POST", f"lab-orders/{lab2}/status", {"status": "cancelled"}, tok)
s, r = call("PUT", f"lab-orders/{lab2}/results", {"tests": []}, tok)
check("a cancelled order can't take results", s == 409, r)

# ── imaging ──
s, r = call("GET", f"radiology-orders/{xray_id}", token=tok)
check("imaging detail", s == 200 and r["data"]["order"]["modality_label"] == "X-ray" and r["data"]["order"]["clinical_indication"] == "Cough 3 weeks", r)
s, r = call("PUT", f"radiology-orders/{xray_id}/report", {"impression": "Clear lung fields", "radiologist_report": "No consolidation."}, tok)
check("report -> report_ready", s == 200 and r["data"]["order"]["status"] == "report_ready" and r["data"]["order"]["impression"] == "Clear lung fields" and r["data"]["order"]["result_date"], r)
s, r = call("PUT", f"radiology-orders/{xray_id}/report", {"impression": "x" * 2001}, tok)
check("over-long impression -> 422", s == 422, r)
s, r = call("POST", f"radiology-orders/{ecg_id}/status", {"status": "delivered"}, tok)
check("ordered can't jump to delivered", s == 409, r)
s, r = call("POST", f"radiology-orders/{ecg_id}/status", {"status": "cancelled"}, tok)
check("imaging can be cancelled", s == 200, r)

# ── private report photos ──
s, r = upload(f"lab-orders/{lab_id}/file", tok, PNG)
check("attach a report photo", s == 200 and r["data"]["order"]["has_file"], r)
s, ctype, body = raw_get(f"lab-orders/{lab_id}/file", tok)
check("the owner can read it back (image bytes)", s == 200 and ctype == "image/png" and body == PNG, (s, ctype, len(body)))
s, ctype, body = raw_get(f"lab-orders/{lab_id}/file", tok2)
check("another doctor cannot", s == 404, s)
s, ctype, body = raw_get(f"lab-orders/{lab_id}/file")
check("no token, no file", s == 401, s)
stored = [f for f in os.listdir(os.path.join(os.path.dirname(__file__), "..", "storage", "reports")) ]
s, r = upload(f"lab-orders/{lab_id}/file", tok, b"just some text", "notes.txt")
check("a non-image is refused", s == 422, r)
s, r = upload(f"lab-orders/{lab_id}/file", tok, PNG + b"\x00" * 10)
check("replacing a photo works", s == 200, r)
s, r = upload(f"radiology-orders/{xray_id}/file", tok, PNG)
check("imaging report photo", s == 200 and r["data"]["order"]["has_file"], r)
s, r = call("POST", f"lab-orders/{lab_id}/file", {}, tok)
check("upload without a file -> 422", s == 422, r)
req = urllib.request.Request(f"{BASE}/storage/reports/")
try: code = urllib.request.urlopen(req).status
except urllib.error.HTTPError as e: code = e.code
check("storage/ is not reachable from the web", code in (403, 404), code)

# ── cancelling the prescription withdraws open orders ──
s, r = call("POST", f"prescriptions/{tsh_rx['id']}/cancel", token=tok)
s, r = call("GET", f"lab-orders/{tsh_rx['investigations']['lab']['id']}", token=tok)
check("cancelling a prescription cancels its pending orders", r["data"]["order"]["status"] == "cancelled", r)

# ── isolation + summary ──
for method, path, body in [("GET", f"lab-orders/{lab_id}", None), ("PUT", f"lab-orders/{lab_id}/results", {"tests": []}), ("POST", f"lab-orders/{lab_id}/status", {"status": "delivered"}),
                           ("GET", f"radiology-orders/{xray_id}", None), ("PUT", f"radiology-orders/{xray_id}/report", {"impression": "x"})]:
    s, r = call(method, path, body, tok2)
    check(f"other doctor cannot {method} {path.split('/')[0]}", s == 404, (s, r))
check("other doctor has no orders", call("GET", "orders?status=all", token=tok2)[1]["data"]["items"] == [], "")
s, r = call("GET", "dashboard/summary", token=tok)
check("summary drops finished and cancelled orders", r["data"]["orders_pending"] == 0, r["data"])
s, r = call("GET", "orders?status=done", token=tok)
check("done list holds finished orders", {i["kind"] for i in r["data"]["items"]} == {"lab", "imaging"} and len(r["data"]["items"]) == 2, [(i["kind"], i["status"]) for i in r["data"]["items"]])
finish()
