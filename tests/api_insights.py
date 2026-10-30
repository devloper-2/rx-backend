#!/usr/bin/env python3
"""Insights: period totals and comparison, weekly trend, top diagnoses/medicines, weekdays, quiet chronic patients, isolation."""
from _client import *
import subprocess, datetime as dt

MYSQL = "/Applications/XAMPP/xamppfiles/bin/mysql"
def sql(q): subprocess.run([MYSQL, "-uroot", "php_rest_api", "-e", q], check=True)
today = dt.datetime.utcnow().date()
day = lambda n: (today + dt.timedelta(days=n)).isoformat()
q = lambda days=30: f"insights?days={days}&today={day(0)}&tz_offset=0"

tok, did = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
check("needs sign-in", call("GET", "insights")[0] == 401)
s, r = call("GET", q(), token=tok)
d = r["data"]
check("empty practice: zeros, 8 weeks, no quiet patients", s == 200 and d["current"]["visits"] == 0 and d["current"]["no_show_rate"] is None and len(d["weeks"]) == 8 and d["quiet"]["count"] == 0 and d["diagnoses"] == [], r)
check("weeks are consecutive Mondays ending this week", all(dt.date.fromisoformat(w["week_start"]).weekday() == 0 for w in d["weeks"]) and d["weeks"][-1]["week_start"] <= day(0) < (dt.date.fromisoformat(d["weeks"][-1]["week_start"]) + dt.timedelta(days=7)).isoformat(), d["weeks"])
check("bad period falls back to 30", call("GET", f"insights?days=5&today={day(0)}", token=tok)[1]["data"]["days"] == 30)

mk = lambda name, extra=None: call("POST", "patients", {"name": name, "sex": "female" if name.startswith("F") else "male", "age_years": 50, **(extra or {})}, tok)[1]["data"]["patient"]["id"]
med = lambda n: {"medicine_name": n, "duration_days": 5, "dose_schedule": [{"slot": "morning", "qty": 1}]}
def rx(pid, diag, meds, ago=0):
    r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": diag, "medicines": [med(m) for m in meds]}, tok)
    assert r[0] == 201, r
    i = r[1]["data"]["prescription"]["id"]
    if ago: sql(f"update prescriptions set visit_date = utc_timestamp() - interval {ago} day where id = {i}")
    return i

a, b, c, f = mk("Asha A"), mk("Bala B"), mk("Chen C"), mk("Fara F")
rx(a, "Viral fever", ["Paracetamol"]); rx(b, "viral fever ", ["Paracetamol", "Cetirizine"]); rx(c, "Hypertension", ["Amlodipine"])
rx(a, "Viral fever", ["Paracetamol"], ago=3)
rx(b, "Hypertension", ["Amlodipine"], ago=45)          # previous 30-day window
rx(c, "Old", ["Zinc"], ago=200)                        # outside both
call("POST", "prescriptions", {"patient_id": a, "status": "draft", "diagnosis": "Draft only"}, tok)
r = call("GET", q(30), token=tok)[1]["data"]
check("30-day visits count final prescriptions only", r["current"]["visits"] == 4 and r["current"]["patients"] == 3, r["current"])
check("previous period is the 30 days before", r["previous"]["visits"] == 1, r["previous"])
check("new patients in period", r["current"]["new_patients"] == 4, r["current"])
check("7-day window is narrower than 30", call("GET", q(7), token=tok)[1]["data"]["current"]["visits"] == 4)
check("top diagnoses fold case and spacing", r["diagnoses"][0] == {"name": "Viral fever", "count": 3} and r["diagnoses"][1]["count"] == 1 and all(x["name"] != "Draft only" for x in r["diagnoses"]), r["diagnoses"])
check("top medicines count prescriptions, not rows", r["medicines"][0] == {"name": "Paracetamol", "count": 3} and "Zinc" not in [m["name"] for m in r["medicines"]], r["medicines"])
check("weekday counts add up to the visits", sum(r["weekdays"]) == 4 and len(r["weekdays"]) == 7, r["weekdays"])
check("sex split of the register", {x["sex"]: x["count"] for x in r["sex"]} == {"male": 3, "female": 1}, r["sex"])
wk = r["weeks"]
check("this week's bar holds today's visits and new patients", wk[-1]["visits"] >= 3 and wk[-1]["new_patients"] == 4 and sum(w["visits"] for w in wk) >= 4, wk)

# timezone: a visit at 23:30 UTC belongs to the next local day for +5:30
rid = rx(a, "Tz check", ["Zinc"])
sql(f"update prescriptions set visit_date = '{day(-1)} 23:30:00' where id = {rid}")
base = call("GET", f"insights?days=7&today={day(0)}&tz_offset=0", token=tok)[1]["data"]["weekdays"]
ist = call("GET", f"insights?days=7&today={day(0)}&tz_offset=330", token=tok)[1]["data"]["weekdays"]
wd_utc, wd_ist = int((today - dt.timedelta(days=1)).strftime("%w")), int(today.strftime("%w"))
check("visit near midnight lands on the doctor's local weekday", base[wd_utc] > 0 and ist[wd_ist] > 0 and ist != base, (base, ist))

# money
bill = call("POST", "bills", {"patient_id": a, "items": [{"description": "Consultation", "amount": 300}], "paid": 300, "method": "upi"}, tok)[1]["data"]["bill"]
call("POST", "bills", {"patient_id": b, "items": [{"description": "Consultation", "amount": 500}], "paid": 200}, tok)
r = call("GET", q(), token=tok)[1]["data"]
check("collected counts payments in the period", r["current"]["collected"] == 500.0, r["current"])
check("weekly bar carries the collection", r["weeks"][-1]["collected"] == 500.0, r["weeks"][-1])

# appointments → no-show rate
for pid, status in [(a, "completed"), (b, "no_show"), (c, "completed"), (f, "completed")]:
    ap = call("POST", "appointments", {"patient_id": pid, "walk_in": True}, tok)[1]["data"]["appointment"]["id"]
    sql(f"update appointments set status = '{status}' where id = {ap}")
check("no-show rate = missed / (seen + missed)", call("GET", q(), token=tok)[1]["data"]["current"]["no_show_rate"] == 25.0)

# quiet chronic patients
cond = call("GET", "catalog/conditions", token=tok)[1]["data"]["items"][0]["id"]
q1, q2, q3 = mk("Quiet One", {"condition_ids": [cond]}), mk("Quiet Two", {"condition_ids": [cond]}), mk("Active Three", {"condition_ids": [cond]})
rx(q1, "Diabetes", ["Metformin"], ago=120); rx(q3, "Diabetes", ["Metformin"], ago=10)
r = call("GET", q(), token=tok)[1]["data"]["quiet"]
names = [x["name"] for x in r["items"]]
check("chronic patients unseen for 90+ days are listed, oldest first, never-seen last", names == ["Quiet One", "Quiet Two"] and r["count"] == 2 and r["items"][0]["days_since"] >= 120 and r["items"][1]["days_since"] is None, r)
check("non-chronic patients never appear as quiet", "Chen C" not in names)

# isolation
r2 = call("GET", q(), token=tok2)[1]["data"]
check("another doctor sees nothing of this", r2["current"]["visits"] == 0 and r2["current"]["collected"] == 0.0 and r2["quiet"]["count"] == 0 and r2["diagnoses"] == [])
finish()
