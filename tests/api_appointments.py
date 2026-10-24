#!/usr/bin/env python3
"""Appointments / queue API: walk-ins, booking, status flow, link with prescriptions, isolation."""
from _client import *
import datetime as dt

UTC = dt.timezone.utc
now = dt.datetime.now(UTC)
iso = lambda d: d.strftime("%Y-%m-%dT%H:%M:%SZ")
day_start = now.replace(hour=0, minute=0, second=0, microsecond=0)

tok, _ = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
mk = lambda t, name, **kw: call("POST", "patients", dict({"name": name, "sex": "male", "age_years": 40}, **kw), t)[1]["data"]["patient"]["id"]
p1, p2, p3 = mk(tok, "Ravi Kumar", known_allergies="Penicillin"), mk(tok, "Meena Sharma"), mk(tok, "Aarav Joshi")
other = mk(tok2, "Beta Patient")

s, r = call("GET", "appointments")
check("appointments need auth", s == 401, r)

# walk-in
s, r = call("POST", "appointments", {"patient_id": p1, "walk_in": True, "reason": "Fever"}, tok)
check("walk-in joins the queue as 'arrived'", s == 201 and r["data"]["appointment"]["status"] == "arrived" and r["data"]["appointment"]["arrived_at"], r)
w1 = r["data"]["appointment"]
check("appointment carries the patient summary", w1["patient"]["name"] == "Ravi Kumar" and w1["patient"]["known_allergies"] == "Penicillin", w1)
s, r = call("POST", "appointments", {"patient_id": p1, "walk_in": True}, tok)
check("same patient twice in the queue -> 409 with existing", s == 409 and r["data"]["existing"]["id"] == w1["id"] and "already waiting" in r["message"], r)
s, r = call("POST", "appointments", {"patient_id": p1, "walk_in": True, "force": True}, tok)
check("force=true adds anyway", s == 201, r); extra = r["data"]["appointment"]["id"]

# booking
soon = now + dt.timedelta(hours=3)
s, r = call("POST", "appointments", {"patient_id": p2, "scheduled_at": iso(soon), "duration_min": 20, "reason": "BP check"}, tok)
check("book an appointment", s == 201 and r["data"]["appointment"]["status"] == "scheduled" and r["data"]["appointment"]["duration_min"] == 20, r)
a2 = r["data"]["appointment"]
s, r = call("POST", "appointments", {"patient_id": p2, "scheduled_at": iso(soon + dt.timedelta(hours=1))}, tok)
check("a second booking for the same patient the same day -> 409", s == 409 and "already has an appointment" in r["message"], r)
tomorrow = now + dt.timedelta(days=1)
s, r = call("POST", "appointments", {"patient_id": p3, "scheduled_at": iso(tomorrow)}, tok)
check("booking tomorrow", s == 201, r); a3 = r["data"]["appointment"]

def bad(why, body, code=422):
    s, r = call("POST", "appointments", body, tok)
    check(f"rejects: {why}", s == code, (s, r))
bad("no time", {"patient_id": p3})
bad("time in the past", {"patient_id": p3, "scheduled_at": iso(now - dt.timedelta(hours=2))})
bad("garbage time", {"patient_id": p3, "scheduled_at": "tomorrow-ish"})
bad("far future", {"patient_id": p3, "scheduled_at": iso(now + dt.timedelta(days=900))})
bad("duration 2 min", {"patient_id": p3, "scheduled_at": iso(now + dt.timedelta(days=3)), "duration_min": 2})
bad("unknown patient", {"patient_id": 99999999, "walk_in": True})
bad("other doctor's patient", {"patient_id": other, "walk_in": True})
bad("reason too long", {"patient_id": p3, "scheduled_at": iso(now + dt.timedelta(days=3)), "reason": "x" * 501})

# listing
rng = f"from={iso(day_start)}&to={iso(day_start + dt.timedelta(days=1))}"
s, r = call("GET", f"appointments?{rng}", token=tok)
ids = [i["id"] for i in r["data"]["items"]]
check("today's list has the queue and the booking, not tomorrow's", w1["id"] in ids and a2["id"] in ids and a3["id"] not in ids, ids)
s, r = call("GET", "appointments?upcoming=1", token=tok)
check("upcoming = future, not yet seen", all(i["status"] in ("scheduled", "confirmed") for i in r["data"]["items"]) and a3["id"] in [i["id"] for i in r["data"]["items"]], r)
s, r = call("GET", f"appointments?patient_id={p2}", token=tok)
check("filter by patient", [i["id"] for i in r["data"]["items"]] == [a2["id"]], r)

# status flow
def st(i, status, tk=tok, **kw): return call("POST", f"appointments/{i}/status", dict({"status": status}, **kw), tk)
s, r = st(a2["id"], "completed")
check("scheduled can't jump to completed", s == 409, r)
s, r = st(a2["id"], "bogus")
check("unknown status -> 422", s == 422, r)
s, r = st(a2["id"], "confirmed")
check("scheduled -> confirmed", s == 200 and r["data"]["appointment"]["status"] == "confirmed", r)
s, r = st(a2["id"], "arrived")
check("confirmed -> arrived (check-in) sets arrived_at", s == 200 and r["data"]["appointment"]["arrived_at"], r)
s, r = st(a3["id"], "cancelled", cancel_reason="Patient travelling")
check("cancel with a reason", s == 200 and r["data"]["appointment"]["cancel_reason"] == "Patient travelling", r)
s, r = st(a3["id"], "scheduled")
check("cancelled is final", s == 409, r)
s, r = st(extra, "cancelled")
s, r = st(extra, "arrived")
check("cancelled can't be revived", s == 409, r)

# reschedule
s, r = call("POST", "appointments", {"patient_id": p3, "scheduled_at": iso(now + dt.timedelta(days=5)), "reason": "Review"}, tok); a4 = r["data"]["appointment"]
s, r = st(a4["id"], "confirmed")
new_time = now + dt.timedelta(days=6)
s, r = call("PUT", f"appointments/{a4['id']}", {"scheduled_at": iso(new_time), "duration_min": 30, "reason": "Review + reports"}, tok)
check("reschedule moves it and asks for confirmation again", s == 200 and r["data"]["appointment"]["status"] == "scheduled" and r["data"]["appointment"]["duration_min"] == 30 and r["data"]["appointment"]["reason"] == "Review + reports", r)
s, r = call("PUT", f"appointments/{a2['id']}", {"scheduled_at": iso(new_time)}, tok)
check("an arrived patient can't be rescheduled", s == 409, r)
s, r = call("PUT", f"appointments/{a4['id']}", {"scheduled_at": iso(now - dt.timedelta(days=1))}, tok)
check("can't reschedule into the past", s == 422, r)

# link with prescriptions
s, r = call("POST", "prescriptions", {"patient_id": p1, "appointment_id": w1["id"], "status": "draft", "diagnosis": "Viral fever"}, tok)
rx_draft = r["data"]["prescription"]["id"]
s, r = call("GET", f"appointments?patient_id={p1}&{rng}", token=tok)
a = [i for i in r["data"]["items"] if i["id"] == w1["id"]][0]
check("a draft links the prescription but does not complete the visit", a["status"] == "arrived" and a["prescription_id"] == rx_draft, a)
s, r = call("POST", f"prescriptions/{rx_draft}/finalize", token=tok)
s, r = call("GET", f"appointments?patient_id={p1}&{rng}", token=tok)
a = [i for i in r["data"]["items"] if i["id"] == w1["id"]][0]
check("finalising the prescription completes the appointment", a["status"] == "completed", a)

s, r = call("POST", "prescriptions", {"patient_id": p2, "appointment_id": a2["id"], "diagnosis": "Hypertension"}, tok)
check("a final prescription created with the appointment completes it at once", s == 201, r)
s, r = st(a2["id"], "completed")
check("completed is final", s == 409, r)
s, r = call("POST", "prescriptions", {"patient_id": p1, "appointment_id": a2["id"], "diagnosis": "x"}, tok)
check("prescription rejects an appointment of another patient / closed one", s == 422, r)
s, r = call("POST", "prescriptions", {"patient_id": p2, "appointment_id": 99999999, "diagnosis": "x"}, tok)
check("prescription rejects an unknown appointment", s == 422, r)

# summary
s, r = call("GET", f"dashboard/summary?since={iso(day_start)}", token=tok)
d = r["data"]
check("summary counts the queue", d["queue_done"] == 2 and d["queue_waiting"] == 0 and d["appointments_today"] == 2, d)

# isolation
for method, path, body in [("PUT", f"appointments/{a4['id']}", {"scheduled_at": iso(new_time)}), ("POST", f"appointments/{a4['id']}/status", {"status": "cancelled"})]:
    s, r = call(method, path, body, tok2)
    check(f"other doctor cannot {method} my appointment", s == 404, (s, r))
s, r = call("GET", "appointments?upcoming=1", token=tok2)
check("other doctor's diary is empty", r["data"]["items"] == [], r)
finish()
