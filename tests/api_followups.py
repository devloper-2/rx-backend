#!/usr/bin/env python3
"""Follow-ups & reminders: who needs attention, snoozing, logged reminders, isolation."""
from _client import *
import subprocess, datetime as dt

MYSQL = "/Applications/XAMPP/xamppfiles/bin/mysql"
def sql(q): subprocess.run([MYSQL, "-uroot", "php_rest_api", "-e", q], check=True)
def day(n): return (dt.datetime.utcnow().date() + dt.timedelta(days=n)).isoformat()

tok, did = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
mk = lambda name, extra=None: call("POST", "patients", {"name": name, "sex": "male", "age_years": 50, "mobile": "9" + str(random.randint(10**8, 10**9 - 1)), **(extra or {})}, tok)[1]["data"]["patient"]["id"]
def rx(pid, body):
    """Final prescription; follow-up dates in the past are rejected by the API, so back-date them directly."""
    body = dict(body); fd = body.pop("followup_date", None)
    r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Fever", **({"followup_date": day(1)} if fd else {}), **body}, tok)
    assert r[0] == 201, r
    i = r[1]["data"]["prescription"]["id"]
    if fd: sql(f"update prescriptions set followup_date = '{fd}' where id = {i}")
    return i
med = {"medicine_name": "Metformin 500", "duration_days": 30, "dose_schedule": [{"slot": "morning", "qty": 1}, {"slot": "night", "qty": 1}]}

s, r = call("GET", "followups", token=tok)
check("empty to begin with", s == 200 and r["data"]["attention"] == 0 and r["data"]["followups"] == [], r)
check("needs sign-in", call("GET", "followups")[0] == 401)

# ── follow-ups ──
p1, p2, p3, p4 = mk("Overdue Olga"), mk("Today Tom"), mk("Future Fay"), mk("Seen Sam")
rx(p1, {"followup_date": day(-5)}); rx(p2, {"followup_date": day(0)}); rx(p3, {"followup_date": day(20)})
old = rx(p4, {"followup_date": day(-5)}); sql(f"update prescriptions set visit_date = utc_timestamp() - interval 6 day where id = {old}"); rx(p4, {})
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
names = {i["patient"]["name"]: i for i in r["followups"]}
check("overdue and due-today listed; far future and already-seen are not", set(names) == {"Overdue Olga", "Today Tom"}, list(names))
check("buckets and days", names["Overdue Olga"]["bucket"] == "overdue" and names["Overdue Olga"]["days"] == 5 and names["Today Tom"]["bucket"] == "today", names)
check("most overdue first", r["followups"][0]["patient"]["name"] == "Overdue Olga")
check("attention counts them", r["attention"] == 2, r["attention"])
dd = call("POST", "prescriptions", {"patient_id": mk("Draft Dan"), "status": "draft", "followup_date": day(1)}, tok)[1]["data"]["prescription"]["id"]; sql(f"update prescriptions set followup_date = '{day(-3)}' where id = {dd}")
check("draft prescriptions do not count",  "Draft Dan" not in [i["patient"]["name"] for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["followups"]])

# booked again → shown with booked_at, not counted
call("POST", "appointments", {"patient_id": p1, "scheduled_at": (dt.datetime.utcnow() + dt.timedelta(days=2)).strftime("%Y-%m-%d %H:%M:%S")}, tok)
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
olga = [i for i in r["followups"] if i["patient"]["name"] == "Overdue Olga"][0]
check("booked patient carries booked_at and drops out of the count", olga["booked_at"] and r["attention"] == 1, r["attention"])

# ── reminders ──
s, r = call("POST", "reminders", {"kind": "followup", "ref_id": names["Today Tom"]["ref_id"], "message": "Hi Tom, time for your check-up."}, tok)
check("reminder logged", s == 200, r)
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
check("reminded_at is returned", [i for i in r["followups"] if i["patient"]["name"] == "Today Tom"][0]["reminded_at"], r["followups"])
check("empty message rejected", call("POST", "reminders", {"kind": "followup", "ref_id": 1, "message": " "}, tok)[0] == 422)
check("unknown kind rejected", call("POST", "reminders", {"kind": "lab_pending", "ref_id": 1, "message": "x"}, tok)[0] == 422)
check("someone else's item is 404", call("POST", "reminders", {"kind": "followup", "ref_id": names["Today Tom"]["ref_id"], "message": "x"}, tok2)[0] == 404)
nomob = rx(call("POST", "patients", {"name": "No Phone", "sex": "male", "age_years": 30}, tok)[1]["data"]["patient"]["id"], {"followup_date": day(-1)})
check("no mobile number → 422", call("POST", "reminders", {"kind": "followup", "ref_id": nomob, "message": "hello"}, tok)[0] == 422)

# ── snooze / dismiss ──
s, r = call("POST", "followups/dismiss", {"kind": "followup", "ref_id": names["Today Tom"]["ref_id"], "days": 3}, tok)
check("snooze", s == 200 and r["data"]["until"], r)
check("snoozed item is hidden", "Today Tom" not in [i["patient"]["name"] for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["followups"]])
sql(f"update followup_actions set until = utc_timestamp() - interval 1 minute where doctor_id = {did}")
check("snooze expires", "Today Tom" in [i["patient"]["name"] for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["followups"]])
check("dismiss for good", call("POST", "followups/dismiss", {"kind": "followup", "ref_id": names["Today Tom"]["ref_id"]}, tok)[0] == 200)
check("dismissed is hidden", "Today Tom" not in [i["patient"]["name"] for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["followups"]])
check("dismiss validates", call("POST", "followups/dismiss", {"kind": "bogus", "ref_id": 1}, tok)[0] == 422 and call("POST", "followups/dismiss", {"kind": "followup", "ref_id": 1, "days": 0}, tok)[0] == 422)
check("cannot dismiss another doctor's item", call("POST", "followups/dismiss", {"kind": "followup", "ref_id": names["Overdue Olga"]["ref_id"]}, tok2)[0] == 404)

# ── refills ──
c = mk("Chronic Chandra", {"condition_ids": [call("GET", "catalog/conditions", token=tok)[1]["data"]["items"][0]["id"]]})
rid = rx(c, {"medicines": [med]})
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
check("fresh 30-day supply is not flagged", "Chronic Chandra" not in [i["patient"]["name"] for i in r["refills"]], r["refills"])
sql(f"update prescriptions set visit_date = utc_timestamp() - interval 33 day where id = {rid}")
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
ch = [i for i in r["refills"] if i["patient"]["name"] == "Chronic Chandra"]
check("chronic patient out of medicines is flagged", len(ch) == 1 and ch[0]["bucket"] == "overdue" and ch[0]["days"] == 3 and "Metformin" in ch[0]["detail"], r["refills"])
check("non-chronic patients never appear as refills", all(i["patient"]["name"] == "Chronic Chandra" for i in r["refills"]))
sql(f"update prescriptions set visit_date = utc_timestamp() - interval 28 day where id = {rid}")
ch = [i for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["refills"] if i["patient"]["name"] == "Chronic Chandra"]
check("running out within 3 days shows as 'soon'", len(ch) == 1 and ch[0]["bucket"] == "soon", ch)

# ── results to review / pending ──
T = {i["short_code"]: i["id"] for i in call("GET", "catalog/lab-tests?codes=HB,FBS", token=tok)[1]["data"]["items"]}
pi = mk("Lab Lata")
orx = rx(pi, {"investigations": {"lab": {"tests": [{"test_id": T["HB"]}, {"test_id": T["FBS"]}]}, "imaging": [{"modality": "x-ray", "body_part": "Chest"}]}})
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
check("fresh orders are not 'pending too long'", r["pending_results"] == [] and r["results_to_review"] == [])
sql(f"update lab_orders set order_date = utc_timestamp() - interval 5 day where doctor_id = {did}; update radiology_orders set created_at = utc_timestamp() - interval 5 day where doctor_id = {did}")
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
check("lab and imaging pending after 5 days", sorted(i["kind"] for i in r["pending_results"]) == ["imaging_pending", "lab_pending"] and all(i["days"] == 5 for i in r["pending_results"]), r["pending_results"])
lab = [i for i in r["pending_results"] if i["kind"] == "lab_pending"][0]
check("snooze a pending lab", call("POST", "followups/dismiss", {"kind": "lab_pending", "ref_id": lab["ref_id"], "days": 2}, tok)[0] == 200
      and [i["kind"] for i in call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["pending_results"]] == ["imaging_pending"])
# results arrive: Hb low
oid = lab["ref_id"]; tests = call("GET", f"lab-orders/{oid}", token=tok)[1]["data"]["order"]["tests"]
call("PUT", f"lab-orders/{oid}/results", {"tests": [{"id": tests[0]["id"], "result_value": "7.5"}, {"id": tests[1]["id"], "result_value": "90"}]}, tok)
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
rv = r["results_to_review"]
check("result in with abnormal value is listed for review", len(rv) == 1 and rv[0]["kind"] == "lab_review" and rv[0]["abnormal_count"] == 1 and "abnormal" in rv[0]["title"], rv)
call("POST", f"lab-orders/{oid}/status", {"status": "delivered"}, tok)
check("reviewed (delivered) result leaves the list", call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["results_to_review"] == [])
check("cancelled prescription's orders are ignored", call("POST", f"prescriptions/{orx}/cancel", {}, tok)[0] == 200
      and call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["pending_results"] == [])

# ── missed appointments ──
pm = mk("Missed Mona")
a = call("POST", "appointments", {"patient_id": pm, "scheduled_at": (dt.datetime.utcnow() + dt.timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S")}, tok)[1]["data"]["appointment"]["id"]
call("POST", f"appointments/{a}/status", {"status": "no_show"}, tok)
r = call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]
check("no-show listed", [i["patient"]["name"] for i in r["missed"]] == ["Missed Mona"], r["missed"])
check("missed visit can be reminded", call("POST", "reminders", {"kind": "missed", "ref_id": a, "message": "We missed you today."}, tok)[0] == 200)
call("POST", "appointments", {"patient_id": pm, "scheduled_at": (dt.datetime.utcnow() + dt.timedelta(days=3)).strftime("%Y-%m-%d %H:%M:%S")}, tok)
check("rebooked patient leaves the missed list", call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["missed"] == [])

# ── isolation + summary ──
check("another doctor sees nothing", call("GET", f"followups?today={day(0)}", token=tok2)[1]["data"]["attention"] == 0)
s, r = call("GET", f"dashboard/summary?today={day(0)}", token=tok)
check("summary carries the attention count", s == 200 and r["data"]["attention"] == call("GET", f"followups?today={day(0)}", token=tok)[1]["data"]["attention"], r)
finish()
