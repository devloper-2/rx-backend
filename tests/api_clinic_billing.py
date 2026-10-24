#!/usr/bin/env python3
"""Clinic hours/holidays/fee and billing: validation, part-payments, collection, isolation."""
from _client import *
import datetime as dt

tok, _ = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
pid = call("POST", "patients", {"name": "Ravi Kumar", "sex": "male", "age_years": 42}, tok)[1]["data"]["patient"]["id"]
pid2 = call("POST", "patients", {"name": "Other Doc Patient", "sex": "male", "age_years": 42}, tok2)[1]["data"]["patient"]["id"]
day = lambda n: (dt.datetime.utcnow() + dt.timedelta(days=n)).date().isoformat()

# ── hours ──
s, r = call("GET", "doctor/hours", token=tok)
check("fresh doctor: not configured, 7 closed days", s == 200 and r["data"]["hours"]["configured"] is False and len(r["data"]["hours"]["days"]) == 7, r)
mon = {"day": 1, "sessions": [{"open": "09:00", "close": "13:00"}, {"open": "17:00", "close": "20:30"}]}
check("hours need a saved clinic first", call("PUT", "doctor/hours", {"days": [mon]}, tok)[0] == 422)
s, r = call("PUT", "doctor/clinic", {"name": "City Clinic", "consultation_fee": 300}, tok)
check("clinic fee saved and returned", s == 200 and r["data"]["clinic"]["consultation_fee"] == 300.0, r)
check("negative fee rejected", call("PUT", "doctor/clinic", {"name": "City Clinic", "consultation_fee": -5}, tok)[0] == 422)
check("fee can be cleared", call("PUT", "doctor/clinic", {"name": "City Clinic", "consultation_fee": ""}, tok)[1]["data"]["clinic"]["consultation_fee"] is None)
call("PUT", "doctor/clinic", {"name": "City Clinic", "consultation_fee": 300}, tok)
s, r = call("PUT", "doctor/hours", {"days": [mon, {"day": 2, "sessions": [{"open": "10:00", "close": "14:00"}]}]}, tok)
h = r["data"]["hours"]
check("two sessions on Monday, one on Tuesday, rest closed", s == 200 and h["configured"] and h["days"][1]["sessions"] == mon["sessions"] and len(h["days"][2]["sessions"]) == 1 and h["days"][0]["sessions"] == [], r)
for name, body in {"close before open": {"day": 3, "sessions": [{"open": "13:00", "close": "09:00"}]},
                   "overlap": {"day": 3, "sessions": [{"open": "09:00", "close": "13:00"}, {"open": "12:00", "close": "15:00"}]},
                   "three sessions": {"day": 3, "sessions": [{"open": "06:00", "close": "07:00"}, {"open": "09:00", "close": "10:00"}, {"open": "12:00", "close": "13:00"}]},
                   "bad time": {"day": 3, "sessions": [{"open": "9am", "close": "13:00"}]}, "bad day": {"day": 9, "sessions": []}}.items():
    check(f"hours rejected: {name}", call("PUT", "doctor/hours", {"days": [body]}, tok)[0] == 422)
check("duplicate day rejected", call("PUT", "doctor/hours", {"days": [mon, mon]}, tok)[0] == 422)
check("saving again replaces the week", call("PUT", "doctor/hours", {"days": [{"day": 5, "sessions": [{"open": "08:00", "close": "12:00"}]}]}, tok)[1]["data"]["hours"]["days"][1]["sessions"] == [])
check("another doctor's hours are separate", call("GET", "doctor/hours", token=tok2)[1]["data"]["hours"]["configured"] is False)

# ── holidays ──
s, r = call("POST", "doctor/holidays", {"date": day(5), "reason": "Diwali"}, tok)
check("add holiday", s == 200 and r["data"]["hours"]["holidays"][0]["date"] == day(5) and r["data"]["hours"]["holidays"][0]["reason"] == "Diwali", r)
call("POST", "doctor/holidays", {"date": day(5), "reason": "Diwali break"}, tok)
hs = call("GET", "doctor/hours", token=tok)[1]["data"]["hours"]["holidays"]
check("same date twice updates the reason", len(hs) == 1 and hs[0]["reason"] == "Diwali break", hs)
check("bad dates rejected", call("POST", "doctor/holidays", {"date": "2026-02-31"}, tok)[0] == 422 and call("POST", "doctor/holidays", {"date": "soon"}, tok)[0] == 422)
check("someone else's holiday is 404", call("DELETE", f"doctor/holidays/{hs[0]['id']}", token=tok2)[0] == 404)
check("remove holiday", call("DELETE", f"doctor/holidays/{hs[0]['id']}", token=tok)[0] == 200 and call("GET", "doctor/hours", token=tok)[1]["data"]["hours"]["holidays"] == [])

# ── bills ──
rx = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Fever"}, tok)[1]["data"]["prescription"]["id"]
check("needs sign-in", call("GET", "bills")[0] == 401)
s, r = call("POST", "bills", {"patient_id": pid, "prescription_id": rx, "items": [{"description": "Consultation", "amount": 300}, {"description": "Dressing", "amount": 150.5}], "discount": 50.5, "paid": 100, "method": "upi"}, tok)
b = r["data"]["bill"]
check("bill created: totals, number, part-paid", s == 201 and b["bill_no"] == "R-0001" and b["subtotal"] == 450.5 and b["total"] == 400.0 and b["paid"] == 100.0 and b["due"] == 300.0 and b["status"] == "partial", r)
check("items and payments listed", [i["description"] for i in b["items"]] == ["Consultation", "Dressing"] and b["payments"][0]["method"] == "upi", b)
check("same prescription billed twice → 409", call("POST", "bills", {"patient_id": pid, "prescription_id": rx, "items": [{"description": "x", "amount": 1}]}, tok)[0] == 409)
s, r = call("POST", "bills", {"patient_id": pid, "items": [{"description": "Consultation", "amount": 300}], "paid": 300}, tok)
check("fully paid walk-in bill, numbers increment", s == 201 and r["data"]["bill"]["status"] == "paid" and r["data"]["bill"]["bill_no"] == "R-0002", r)
paid_id = r["data"]["bill"]["id"]
s, r = call("POST", "bills", {"patient_id": pid, "items": [{"description": "Visit", "amount": 200}]}, tok)
check("unpaid bill", r["data"]["bill"]["status"] == "unpaid" and r["data"]["bill"]["due"] == 200.0, r); unpaid = r["data"]["bill"]["id"]
check("free visit counts as paid", call("POST", "bills", {"patient_id": pid, "items": [{"description": "Review", "amount": 0}]}, tok)[1]["data"]["bill"]["status"] == "paid")
for name, body in {"no items": {"patient_id": pid, "items": []}, "blank description": {"patient_id": pid, "items": [{"description": " ", "amount": 5}]},
                   "missing amount": {"patient_id": pid, "items": [{"description": "x"}]}, "negative amount": {"patient_id": pid, "items": [{"description": "x", "amount": -1}]},
                   "discount > total": {"patient_id": pid, "items": [{"description": "x", "amount": 5}], "discount": 6},
                   "paid > total": {"patient_id": pid, "items": [{"description": "x", "amount": 5}], "paid": 6},
                   "bad method": {"patient_id": pid, "items": [{"description": "x", "amount": 5}], "paid": 1, "method": "bitcoin"},
                   "other doctor's patient": {"patient_id": pid2, "items": [{"description": "x", "amount": 5}]}, "no patient": {"items": [{"description": "x", "amount": 5}]},
                   "foreign prescription": {"patient_id": pid, "prescription_id": 999999, "items": [{"description": "x", "amount": 5}]}}.items():
    check(f"bill rejected: {name}", call("POST", "bills", body, tok)[0] == 422, name)

# ── payments ──
s, r = call("POST", f"bills/{b['id']}/payment", {"amount": 100, "method": "cash"}, tok)
check("second part-payment", s == 200 and r["data"]["bill"]["paid"] == 200.0 and r["data"]["bill"]["status"] == "partial" and len(r["data"]["bill"]["payments"]) == 2, r)
check("overpaying is rejected", call("POST", f"bills/{b['id']}/payment", {"amount": 500, "method": "cash"}, tok)[0] == 422)
check("zero payment rejected", call("POST", f"bills/{b['id']}/payment", {"amount": 0}, tok)[0] == 422)
s, r = call("POST", f"bills/{b['id']}/payment", {"amount": 200, "method": "card"}, tok)
check("final payment settles the bill", r["data"]["bill"]["status"] == "paid" and r["data"]["bill"]["due"] == 0.0, r)
check("paid bill takes no more", call("POST", f"bills/{b['id']}/payment", {"amount": 1}, tok)[0] == 422)
check("another doctor cannot read or pay it", call("GET", f"bills/{b['id']}", token=tok2)[0] == 404 and call("POST", f"bills/{unpaid}/payment", {"amount": 5}, tok2)[0] == 404)

# ── lists + collection ──
s, r = call("GET", "bills?status=unpaid", token=tok)
check("unpaid list holds only the open bill", [x["id"] for x in r["data"]["items"]] == [unpaid], r)
check("patient filter", len(call("GET", f"bills?patient_id={pid}", token=tok)[1]["data"]["items"]) == 4)
check("other doctor sees none", call("GET", "bills", token=tok2)[1]["data"]["items"] == [])
f, t = day(0) + "T00:00:00Z", day(1) + "T00:00:00Z"
s, r = call("GET", f"bills/summary?from={f}&to={t}", token=tok); sm = r["data"]
check("collection = 100 upi + 100 cash + 200 card + 300 cash", sm["collected"] == 700.0 and sm["by_method"] == {"cash": 400.0, "upi": 100.0, "card": 200.0, "other": 0.0}, sm)
check("billed and outstanding", sm["billed"] == 900.0 and sm["bills"] == 4 and sm["outstanding"] == 200.0 and sm["outstanding_count"] == 1, sm)
check("home summary shows collected_today", call("GET", f"dashboard/summary?since={f}", token=tok)[1]["data"]["collected_today"] == 700.0)

# ── cancel ──
s, r = call("POST", f"bills/{paid_id}/cancel", token=tok)
check("cancel a bill", s == 200 and r["data"]["bill"]["status"] == "cancelled" and r["data"]["bill"]["due"] == 0.0, r)
check("cancelled twice → 409", call("POST", f"bills/{paid_id}/cancel", token=tok)[0] == 409)
check("cancelled bill takes no payment", call("POST", f"bills/{paid_id}/payment", {"amount": 1}, tok)[0] == 409)
check("cancelling removes its money from collection", call("GET", f"bills/summary?from={f}&to={t}", token=tok)[1]["data"]["collected"] == 400.0)
check("cancelled bills leave the list", paid_id not in [x["id"] for x in call("GET", "bills", token=tok)[1]["data"]["items"]])
check("a cancelled bill frees its prescription for re-billing", call("POST", f"bills/{b['id']}/cancel", token=tok)[0] == 200 and call("POST", "bills", {"patient_id": pid, "prescription_id": rx, "items": [{"description": "Redo", "amount": 10}]}, tok)[0] == 201)
finish()
