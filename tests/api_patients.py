#!/usr/bin/env python3
"""Patients API: CRUD, search, duplicates, validation, per-doctor isolation."""
from _client import *

tok, _ = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")

s, r = call("GET", "patients")
check("patients need auth", s == 401, r)
s, r = call("GET", "patients", token=tok)
check("empty register lists nothing", s == 200 and r["data"]["items"] == [], r)

base = {"name": "Ravi Kumar", "sex": "male", "age_years": 42, "mobile": "98765 43210"}
s, r = call("POST", "patients", base, tok)
check("create 201", s == 201, r); p1 = r["data"]["patient"]
check("UHID starts at P0001", p1["uhid"] == "P0001", p1)
check("mobile normalised to digits", p1["mobile"] == "9876543210", p1)
check("age label", p1["age_label"] == "42 y", p1)

s, r = call("POST", "patients", {"name": "Baby Joshi", "sex": "female", "date_of_birth": "2026-07-20", "mobile": "9876543210",
                                 "blood_group": "O+", "known_allergies": "Penicillin", "condition_ids": [1, 7, 99999]}, tok)
check("family member on same mobile allowed", s == 201, r); p2 = r["data"]["patient"]
check("UHID increments", p2["uhid"] == "P0002", p2)
check("infant age shown in months", p2["age_label"].endswith(" m"), p2)
check("unknown condition ids ignored, valid kept", [c["id"] for c in p2["conditions"]] == sorted([1, 7]) or len(p2["conditions"]) == 2, p2)
check("summary text kept in sync", "Hypertension" in (p2["chronic_summary"] or ""), p2)

s, r = call("POST", "patients", base, tok)
check("same name + mobile -> 409 with duplicate", s == 409 and r["data"]["duplicate"]["id"] == p1["id"], r)
s, r = call("POST", "patients", dict(base, force=True), tok)
check("force=true adds anyway", s == 201 and r["data"]["patient"]["uhid"] == "P0003", r)

for bad, why in [({"sex": "male", "age_years": 5}, "name missing"),
                 ({"name": "A", "sex": "male", "age_years": 5}, "name too short"),
                 ({"name": "Test", "sex": "robot", "age_years": 5}, "bad sex"),
                 ({"name": "Test", "sex": "male"}, "no age or dob"),
                 ({"name": "Test", "sex": "male", "age_years": 200}, "age 200"),
                 ({"name": "Test", "sex": "male", "date_of_birth": "2999-01-01"}, "future dob"),
                 ({"name": "Test", "sex": "male", "age_years": 5, "mobile": "12"}, "short mobile"),
                 ({"name": "Test", "sex": "male", "age_years": 5, "email": "nope"}, "bad email"),
                 ({"name": "Test", "sex": "male", "age_years": 5, "blood_group": "Z+"}, "bad blood group")]:
    s, r = call("POST", "patients", bad, tok)
    check(f"rejects: {why}", s == 422, (s, r))

s, r = call("GET", "patients?q=baby", token=tok)
check("search by name", s == 200 and [i["name"] for i in r["data"]["items"]] == ["Baby Joshi"], r)
s, r = call("GET", "patients?q=P0001", token=tok)
check("search by UHID", s == 200 and r["data"]["items"][0]["id"] == p1["id"], r)
s, r = call("GET", "patients?q=98765", token=tok)
check("search by mobile", s == 200 and len(r["data"]["items"]) == 3, r)
s, r = call("GET", "patients?q=%25", token=tok)
check("wildcard characters are literal", s == 200 and r["data"]["items"] == [], r)
s, r = call("GET", "patients?limit=2&page=2", token=tok)
check("pagination", s == 200 and r["data"]["pagination"]["total"] == 3 and len(r["data"]["items"]) == 1, r)

s, r = call("PUT", f"patients/{p1['id']}", dict(base, name="Ravi K. Kumar", known_allergies="<b>Sulfa</b>", condition_ids=[1]), tok)
check("update", s == 200 and r["data"]["patient"]["name"] == "Ravi K. Kumar" and r["data"]["patient"]["known_allergies"] == "Sulfa", r)
check("update replaces conditions", [c["id"] for c in r["data"]["patient"]["conditions"]] == [1], r)

# isolation between doctors
for method, path, body in [("GET", f"patients/{p1['id']}", None), ("PUT", f"patients/{p1['id']}", base), ("DELETE", f"patients/{p1['id']}", None)]:
    s, r = call(method, path, body, tok2)
    check(f"other doctor cannot {method} my patient", s == 404, (s, r))
s, r = call("GET", "patients", token=tok2)
check("other doctor's register is empty", r["data"]["items"] == [], r)
s, r = call("POST", "patients", dict(base, name="Same Name"), tok2)
check("UHID numbering is per doctor", s == 201 and r["data"]["patient"]["uhid"] == "P0001", r)

s, r = call("DELETE", f"patients/{p1['id']}", token=tok)
check("archive", s == 200, r)
s, r = call("GET", f"patients/{p1['id']}", token=tok)
check("archived patient is gone from the register", s == 404, r)
s, r = call("GET", "patients/abc", token=tok)
check("non-numeric id -> 404", s == 404, r)
s, r = call("GET", "catalog/conditions", token=tok)
check("condition catalog", s == 200 and len(r["data"]["items"]) > 10, r)
finish()
