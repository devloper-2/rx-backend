"""Tiny helpers shared by the API test scripts."""
import json, random, sys, urllib.request, urllib.error

BASE = "http://localhost/prescription-rx/rx-backend"
fails = 0


def call(method, path, body=None, token=None):
    h = {"Content-Type": "application/json"}
    if token: h["Authorization"] = "Bearer " + token
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


def new_doctor(name="Dr Test"):
    """Register + verify a throw-away doctor; returns (access_token, doctor_id)."""
    phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
    s, r = call("POST", "auth/register-new", {"name": name, "password": "Secret123", "phone": phone, "countrycode": "+91"})
    s, r = call("POST", "auth/verify-otp", {"mobile": "+91" + phone, "otp": r["data"]["dev_otp"]})
    return r["data"]["access_token"], r["data"]["user"]["id"]


def finish():
    print("\nALL PASSED" if not fails else f"\n{fails} FAILED")
    sys.exit(1 if fails else 0)
