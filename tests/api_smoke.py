#!/usr/bin/env python3
"""End-to-end smoke test for the doctor auth/profile API.

Usage: python3 tests/api_smoke.py [base_url]
Needs Config app.expose_dev_otp = true (OTP comes back as `dev_otp`).
Creates a throw-away doctor with a random mobile number each run.
"""
import json, random, sys, time, urllib.request, urllib.error

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost/prescription-rx/rx-backend").rstrip("/")
fails = 0


def call(method, path, body=None, token=None, headers=None):
    h = {"Content-Type": "application/json"}
    if token: h["Authorization"] = "Bearer " + token
    if headers: h.update(headers)
    req = urllib.request.Request(f"{BASE}/{path}", method=method, headers=h,
                                 data=json.dumps(body).encode() if body is not None else None)
    try:
        with urllib.request.urlopen(req) as r: return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)


def check(name, cond, extra=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {extra}"))
    if not cond: fails += 1


phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
mobile = "+91" + phone
pw = "Secret123"

s, r = call("POST", "auth/register-new", {"name": "Dr O'Brien", "password": pw, "phone": phone, "countrycode": "+91"})
check("register 201", s == 201, r); otp = r["data"]["dev_otp"]
s, r = call("POST", "auth/register-new", {"name": "Dr O'Brien", "password": pw, "phone": phone, "countrycode": "+91"})
check("re-register while unverified allowed", s == 201, r)
otp = r["data"]["dev_otp"]
s, r = call("POST", "auth/register-new", {"name": "X", "password": "abc", "phone": "12", "countrycode": "+91"})
check("register validation 422", s == 422, r)
s, r = call("POST", "auth/register-new", {"name": "Dr Y", "password": "onlyletters", "phone": phone[:-1] + "1", "countrycode": "+91"})
check("password needs a digit", s == 422, r)
s, r = call("POST", "auth/register-new", {"name": "Dr Y", "password": pw, "phone": "5012345678", "countrycode": "+91"})
check("+91 number must start 6-9", s == 422, r)
s, r = call("POST", "auth/login-new", {"identifier": phone, "password": pw})
check("login before verify -> 403 needs_verification", s == 403 and r["data"]["needs_verification"], r)
wrong = "000000" if otp != "000000" else "111111"
s, r = call("POST", "auth/verify-otp", {"mobile": mobile, "otp": wrong})
check("wrong otp 400 w/ attempts left", s == 400 and "attempt" in r["message"], r)
s, r = call("POST", "auth/resend-otp", {"mobile": mobile, "purpose": "register"})
check("resend inside cooldown 429", s == 429, r)
s, r = call("POST", "auth/verify-otp", {"mobile": mobile, "otp": otp})
check("verify otp 200 + tokens", s == 200 and r["data"]["access_token"], r)
acc, ref = r["data"]["access_token"], r["data"]["refresh_token"]
check("name with apostrophe stored raw", r["data"]["user"]["name"] == "Dr O'Brien", r["data"]["user"])
s, r = call("POST", "auth/register-new", {"name": "Dr Z", "password": pw, "phone": phone, "countrycode": "+91"})
check("register verified number 409", s == 409, r)
s, r = call("GET", "auth/me", token=acc)
check("me 200", s == 200 and r["data"]["user"]["mobile"] == mobile, r)
check("me hides password_hash", "password_hash" not in json.dumps(r))
s, r = call("GET", "auth/me")
check("me without token 401", s == 401, r)
s, r = call("GET", "auth/me", token=acc + "x")
check("me bad token 401", s == 401, r)

s, r = call("PUT", "doctor/profile", {"name": "Dr O'Brien", "email": "Dr.OBrien" + phone + "@Example.com", "degree": "MBBS, MD",
                                       "specialization": "Cardiology", "registration_no": "REG" + phone, "experience_yrs": 12}, token=acc)
check("profile update 200", s == 200 and r["data"]["user"]["profile_percent"] == 100, r)
check("email lower-cased", r["data"]["user"]["email"] == ("dr.obrien" + phone + "@example.com"), r)
s, r = call("PUT", "doctor/profile", {"name": "Dr O'Brien", "experience_yrs": 99}, token=acc)
check("experience out of range 422", s == 422, r)
s, r = call("PUT", "doctor/profile", {"name": "Dr O'Brien", "email": "not-an-email"}, token=acc)
check("bad email 422", s == 422, r)
s, r = call("PUT", "doctor/profile", {"name": "Dr O'Brien", "email": "simet13909@dyleris.com"}, token=acc)
check("duplicate email 422", s == 422, r)
s, r = call("PUT", "doctor/profile", {"name": "Dr O'Brien", "degree": "<b>MBBS</b>"}, token=acc)
check("html stripped, blanks -> null", s == 200 and r["data"]["user"]["degree"] == "MBBS" and r["data"]["user"]["email"] is None, r)

s, r = call("POST", "auth/refresh", {"refresh_token": ref})
check("refresh 200", s == 200 and r["data"]["access_token"], r)
s, r = call("POST", "auth/refresh", {"refresh_token": "nope"})
check("refresh invalid 401", s == 401, r)

s, r = call("POST", "auth/login-new", {"identifier": mobile, "password": "Wrong1234"})
check("wrong password 401", s == 401, r)
s, r = call("POST", "auth/login-new", {"identifier": "9999999991", "password": pw})
check("unknown number 401 (same msg)", s == 401, r)
s, r = call("POST", "auth/login-new", {"identifier": phone, "password": pw})
check("login national number 200", s == 200, r); acc2, ref2 = r["data"]["access_token"], r["data"]["refresh_token"]
s, r = call("POST", "auth/refresh", {"refresh_token": ref})
check("old session revoked by new login (single session)", s == 401, r)

s, r = call("PUT", "doctor/change-password", {"current_password": "bad", "password": "Newpass456"}, token=acc2)
check("change pw wrong current 422", s == 422, r)
s, r = call("PUT", "doctor/change-password", {"current_password": pw, "password": "Newpass456"}, token=acc2)
check("change pw 200 + new tokens", s == 200 and r["data"]["refresh_token"], r)
ref3 = r["data"]["refresh_token"]
s, r = call("POST", "auth/refresh", {"refresh_token": ref2})
check("old refresh dead after pw change", s == 401, r)

s, r = call("POST", "auth/logout", {"refresh_token": ref3})
check("logout 200", s == 200, r)
s, r = call("POST", "auth/refresh", {"refresh_token": ref3})
check("refresh after logout 401", s == 401, r)

s, r = call("POST", "auth/forgot-password", {"mobile": phone})
check("forgot pw 200 + dev_otp", s == 200 and "dev_otp" in r["data"], r); rotp = r["data"]["dev_otp"]
s, r = call("POST", "auth/forgot-password", {"mobile": "9999999992"})
check("forgot pw unknown number: same 200, no otp", s == 200 and "dev_otp" not in (r["data"] or {}), r)
s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": "12", "password": "Another789"})
check("reset wrong otp 400", s == 400, r)
s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": rotp, "password": "weakpass"})
check("reset weak password 422", s == 422, r)
s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": rotp, "password": "Another789"})
check("reset 200", s == 200, r)
s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": rotp, "password": "Another789"})
check("otp single-use", s == 400, r)
s, r = call("POST", "auth/login-new", {"identifier": mobile, "password": "Another789"})
check("login with reset password", s == 200, r)

# brute force: 5 wrong guesses lock the code
s, r = call("POST", "auth/forgot-password", {"mobile": phone}); rotp = r["data"]["dev_otp"]
wrong = "000000" if rotp != "000000" else "111111"
for _ in range(5): s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": wrong, "password": "Another789"})
s, r = call("POST", "auth/reset-password", {"mobile": phone, "otp": rotp, "password": "Another789"})
check("code locked after 5 wrong attempts", s == 429, r)

s, r = call("POST", "auth/login-new", {"identifier": mobile, "password": "x"}, headers={"X-Forwarded-For": "10.9.8.7"})
check("spoofed X-Forwarded-For does not change rate-limit identity (smoke)", s in (401, 429), r)
s, r = call("GET", "nonexistent"); check("unknown route 404", s == 404, r)
s, r = call("GET", "auth/login-new"); check("wrong verb 405", s == 405, r)


# ── hostile / unusual input ──────────────────────────────────────────────────
for payload in ["' OR 1=1 --", "admin'--", '" OR ""="', "1; DROP TABLE doctors"]:
    s, r = call("POST", "auth/login-new", {"identifier": payload, "password": payload})
    check(f"injection-style identifier {payload!r} -> 401, not 500", s == 401, r)
s, r = call("POST", "auth/register-new", {"name": ["array"], "password": {"a": 1}, "phone": 123456789, "countrycode": 91})
check("wrong JSON types -> 422", s == 422, r)
s, r = call("POST", "auth/login-new", {"identifier": None, "password": None})
check("null credentials -> 422", s == 422, r)
s, r = call("POST", "auth/register-new", {"name": "x" * 101, "password": pw, "phone": phone, "countrycode": "+91"})
check("101-char name rejected", s == 422, r)
uni_phone = "8" + "".join(random.choice("0123456789") for _ in range(9))
s, r = call("POST", "auth/register-new", {"name": "डॉ. आशा राव 🩺", "password": pw, "phone": uni_phone, "countrycode": "+91"})
s, r = call("POST", "auth/verify-otp", {"mobile": "+91" + uni_phone, "otp": r["data"]["dev_otp"]})
check("unicode/emoji name round-trips", s == 200 and r["data"]["user"]["name"] == "डॉ. आशा राव 🩺", r)
for path in ["php_rest_api.sql", "README.md", ".git/config", "tests/api_smoke.py", "config/Config.php"]:
    req = urllib.request.Request(f"{BASE}/{path}")
    try: code = urllib.request.urlopen(req).status
    except urllib.error.HTTPError as e: code = e.code
    check(f"/{path} is not publicly readable", code in (403, 404), code)

print("\nALL PASSED" if not fails else f"\n{fails} FAILED")
sys.exit(1 if fails else 0)
