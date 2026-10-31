#!/usr/bin/env python3
"""Receptionist access: creation, sign-in, what they may and may not do, switching off, isolation."""
from _client import *

tok, did = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
full = "+91" + phone
PW = "Desk12345"

# ── creating ──
check("needs sign-in", call("GET", "staff")[0] == 401)
s, r = call("POST", "staff", {"name": "Rita Desk", "countrycode": "+91", "phone": phone, "password": PW}, tok)
check("doctor adds a receptionist", s == 201 and r["data"]["staff"]["name"] == "Rita Desk" and r["data"]["staff"]["is_active"] is True and "password" not in json.dumps(r), r)
sid = r["data"]["staff"]["id"]
check("same mobile twice → 409", call("POST", "staff", {"name": "Dup", "countrycode": "+91", "phone": phone, "password": PW}, tok2)[0] == 409)
check("a doctor's mobile cannot be reused", call("POST", "staff", {"name": "Dup", "countrycode": "+91", "phone": "9876501234", "password": PW}, tok)[0] == 409)
for name, body in {"short name": {"name": "R", "countrycode": "+91", "phone": "9" + "1" * 9, "password": PW},
                   "weak password": {"name": "Ok Name", "countrycode": "+91", "phone": "9" + "2" * 9, "password": "abcdefgh"},
                   "short password": {"name": "Ok Name", "countrycode": "+91", "phone": "9" + "3" * 9, "password": "a1"},
                   "bad phone": {"name": "Ok Name", "countrycode": "+91", "phone": "12345", "password": PW}}.items():
    check(f"rejected: {name}", call("POST", "staff", body, tok)[0] == 422, name)
check("list shows only own staff", [x["id"] for x in call("GET", "staff", token=tok)[1]["data"]["items"]] == [sid] and call("GET", "staff", token=tok2)[1]["data"]["items"] == [])

# ── signing in ──
s, r = call("POST", "auth/login-new", {"identifier": phone, "password": PW})
check("receptionist signs in with the mobile", s == 200 and r["data"]["user"]["role"] == "receptionist" and r["data"]["user"]["doctor_name"] == "Dr Alpha", r)
rt, rtok, rref = r["data"]["user"], r["data"]["access_token"], r["data"]["refresh_token"]
check("wrong password rejected", call("POST", "auth/login-new", {"identifier": phone, "password": "Wrong12345"})[0] == 401)
check("me returns the receptionist", call("GET", "auth/me", token=rtok)[1]["data"]["user"]["role"] == "receptionist")
s, r = call("POST", "auth/refresh", {"refresh_token": rref})
check("refresh works for receptionists", s == 200 and r["data"]["access_token"], r)
rtok = r["data"]["access_token"]

# ── what they can do ──
s, r = call("POST", "patients", {"name": "Walk In", "sex": "female", "age_years": 33, "mobile": "9" + "5" * 9}, rtok)
check("create a patient", s == 201, r); pid = r["data"]["patient"]["id"]
check("list and open patients", call("GET", "patients", token=rtok)[0] == 200 and call("GET", f"patients/{pid}", token=rtok)[0] == 200)
check("edit a patient", call("PUT", f"patients/{pid}", {"name": "Walk In", "sex": "female", "age_years": 34}, rtok)[0] == 200)
check("condition list for the patient form", call("GET", "catalog/conditions", token=rtok)[0] == 200)
s, r = call("POST", "appointments", {"patient_id": pid, "walk_in": True}, rtok)
check("add a walk-in to the queue", s == 201, r); ap = r["data"]["appointment"]["id"]
check("today's list", call("GET", "appointments?upcoming=1", token=rtok)[0] == 200)
check("check-in / cancel statuses allowed", call("POST", f"appointments/{ap}/status", {"status": "cancelled", "cancel_reason": "left"}, rtok)[0] == 200)
ap2 = call("POST", "appointments", {"patient_id": pid, "walk_in": True, "force": True}, rtok)[1]["data"]["appointment"]["id"]
check("cannot mark a visit completed", call("POST", f"appointments/{ap2}/status", {"status": "completed"}, rtok)[0] == 403)
s, r = call("POST", "bills", {"patient_id": pid, "items": [{"description": "Consultation", "amount": 300}], "paid": 100, "method": "upi"}, rtok)
check("make a bill and take a part-payment", s == 201, r); bid = r["data"]["bill"]["id"]
check("collect the rest", call("POST", f"bills/{bid}/payment", {"amount": 200, "method": "cash"}, rtok)[1]["data"]["bill"]["status"] == "paid")
check("see bills and the day's collection", call("GET", "bills", token=rtok)[0] == 200 and call("GET", "bills/summary", token=rtok)[1]["data"]["collected"] == 300.0)
check("read clinic details and hours", call("GET", "doctor/clinic", token=rtok)[0] == 200 and call("GET", "doctor/hours", token=rtok)[0] == 200)
s, r = call("GET", "dashboard/summary", token=rtok)
check("limited summary: queue and money, no clinical numbers", s == 200 and "queue_waiting" in r["data"] and "collected_today" in r["data"] and "prescriptions_total" not in r["data"] and "attention" not in r["data"], r)

# ── what they cannot do ──
forbidden = [("GET", "prescriptions"), ("POST", "prescriptions"), ("GET", f"patients/{pid}/prescriptions"), ("GET", "orders"), ("GET", "lab-orders/1"),
             ("GET", "followups"), ("POST", "followups/dismiss"), ("POST", "reminders"), ("GET", "insights"), ("GET", "doctor/profile"), ("PUT", "doctor/profile"),
             ("PUT", "doctor/clinic"), ("PUT", "doctor/hours"), ("POST", "doctor/holidays"), ("PUT", "doctor/change-password"), ("GET", "catalog/medicines"),
             ("GET", "staff"), ("POST", "staff"), ("PUT", f"staff/{sid}"), ("DELETE", f"staff/{sid}"), ("DELETE", f"patients/{pid}"), ("POST", f"bills/{bid}/cancel")]
for m, p in forbidden:
    s, _ = call(m, p, {} if m != "GET" else None, rtok)
    check(f"receptionist blocked: {m} {p}", s == 403, s)

# ── isolation ──
pid2 = call("POST", "patients", {"name": "Beta Patient", "sex": "male", "age_years": 40}, tok2)[1]["data"]["patient"]["id"]
check("cannot see another doctor's patient", call("GET", f"patients/{pid2}", token=rtok)[0] == 404)
check("cannot bill another doctor's patient", call("POST", "bills", {"patient_id": pid2, "items": [{"description": "x", "amount": 1}]}, rtok)[0] == 422)

# ── own password ──
check("wrong current password", call("PUT", "staff/password", {"current_password": "Nope12345", "password": "Newpass123"}, rtok)[0] == 422)
s, r = call("PUT", "staff/password", {"current_password": PW, "password": "Newpass123"}, rtok)
check("change own password; other sessions end", s == 200 and call("POST", "auth/refresh", {"refresh_token": rref})[0] == 401, r)
rtok, rref = r["data"]["access_token"], r["data"]["refresh_token"]; PW = "Newpass123"
check("doctor cannot use the receptionist password route", call("PUT", "staff/password", {"current_password": "x", "password": "Newpass123"}, tok)[0] == 403)

# ── doctor manages ──
check("rename", call("PUT", f"staff/{sid}", {"name": "Rita D"}, tok)[1]["data"]["staff"]["name"] == "Rita D")
check("doctor sets a new password → receptionist signed out", call("PUT", f"staff/{sid}", {"password": "Reset12345"}, tok)[0] == 200 and call("POST", "auth/refresh", {"refresh_token": rref})[0] == 401)
check("old password no longer works, new one does", call("POST", "auth/login-new", {"identifier": phone, "password": PW})[0] == 401 and call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[0] == 200)
rtok = call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[1]["data"]["access_token"]
check("another doctor cannot edit or delete them", call("PUT", f"staff/{sid}", {"name": "X"}, tok2)[0] == 404 and call("DELETE", f"staff/{sid}", token=tok2)[0] == 404)

s, r = call("PUT", f"staff/{sid}", {"is_active": False}, tok)
check("switch off", s == 200 and r["data"]["staff"]["is_active"] is False)
check("their live access token stops working at once", call("GET", "patients", token=rtok)[0] == 401)
check("they cannot sign in while switched off", call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[0] == 403)
call("PUT", f"staff/{sid}", {"is_active": True}, tok)
check("switch back on", call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[0] == 200)
rtok = call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[1]["data"]
check("logout revokes the refresh token", call("POST", "auth/logout", {"refresh_token": rtok["refresh_token"]})[0] == 200 and call("POST", "auth/refresh", {"refresh_token": rtok["refresh_token"]})[0] == 401)
rtok = call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[1]["data"]["access_token"]
check("remove", call("DELETE", f"staff/{sid}", token=tok)[0] == 200 and call("GET", "staff", token=tok)[1]["data"]["items"] == [])
check("removed receptionist: token dead, login refused", call("GET", "patients", token=rtok)[0] == 401 and call("POST", "auth/login-new", {"identifier": full, "password": "Reset12345"})[0] == 401)
check("the mobile is free to reuse", call("POST", "staff", {"name": "New Desk", "countrycode": "+91", "phone": phone, "password": PW}, tok)[0] == 201)

# a doctor who is switched off takes the desk down with them
sdr = call("POST", "auth/login-new", {"identifier": phone, "password": PW})[1]["data"]["access_token"]
check("fresh receptionist works", call("GET", "patients", token=sdr)[0] == 200)
import subprocess
subprocess.run(["/Applications/XAMPP/xamppfiles/bin/mysql", "-uroot", "php_rest_api", "-e", f"update doctors set is_active = 0 where id = {did}"], check=True)
check("deactivating the doctor locks the receptionist out", call("GET", "patients", token=sdr)[0] == 401)
finish()
