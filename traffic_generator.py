import requests
import random
import time
import json
from datetime import datetime, timezone

BASE_URL = "http://127.0.0.1:8000/api"

# Request distribution (base load)
BASE_DISTRIBUTION = [
    ("/normal",        "GET",  70),
    ("/slow",          "GET",  15),
    ("/slow?hard=1",   "GET",   5),
    ("/error",         "GET",   5),
    ("/db",            "GET",   3),
    ("/validate",      "POST",  2),
]

# Anomaly distribution (error spike)
ANOMALY_DISTRIBUTION = [
    ("/normal",        "GET",  45),
    ("/slow",          "GET",  10),
    ("/slow?hard=1",   "GET",   5),
    ("/error",         "GET",  35),
    ("/db",            "GET",   3),
    ("/validate",      "POST",  2),
]

VALID_PAYLOADS = [
    {"email": "user@example.com", "age": 25},
    {"email": "test@gmail.com",   "age": 30},
    {"email": "admin@site.com",   "age": 45},
]

INVALID_PAYLOADS = [
    {"email": "notvalid",  "age": 5},
    {"email": "",          "age": 200},
    {"email": "bad",       "age": -1},
]

def build_weighted_list(distribution):
    weighted = []
    for path, method, weight in distribution:
        weighted.extend([(path, method)] * weight)
    return weighted

def send_request(path, method):
    url = BASE_URL + path
    try:
        if method == "POST":
            if random.random() < 0.5:
                payload = random.choice(INVALID_PAYLOADS)
            else:
                payload = random.choice(VALID_PAYLOADS)
            requests.post(url, json=payload, timeout=15)
        else:
            requests.get(url, timeout=15)
    except Exception:
        pass

def run():
    print(f"[{datetime.now()}] Starting traffic generator...")

    # Phase 1: Base load
    base_requests = 2700
    base_weighted = build_weighted_list(BASE_DISTRIBUTION)

    print(f"[{datetime.now()}] Base load started ({base_requests} requests)...")
    for i in range(base_requests):
        path, method = random.choice(base_weighted)
        send_request(path, method)
        if i % 100 == 0:
            print(f"  Sent {i}/{base_requests} requests")
        time.sleep(0.1)

    # Anomaly window
    anomaly_start = datetime.now(timezone.utc)
    anomaly_requests = 400
    anomaly_weighted = build_weighted_list(ANOMALY_DISTRIBUTION)

    print(f"[{datetime.now()}] ANOMALY WINDOW STARTED at {anomaly_start.isoformat()}")
    for i in range(anomaly_requests):
        path, method = random.choice(anomaly_weighted)
        send_request(path, method)
        if i % 50 == 0:
            print(f"  Anomaly {i}/{anomaly_requests} requests")
        time.sleep(0.1)

    anomaly_end = datetime.now(timezone.utc)
    print(f"[{datetime.now()}] ANOMALY WINDOW ENDED at {anomaly_end.isoformat()}")

    # Ground truth
    ground_truth = {
        "anomaly_start_iso": anomaly_start.isoformat(),
        "anomaly_end_iso":   anomaly_end.isoformat(),
        "anomaly_type":      "error_spike",
        "expected_behavior": "Error rate spikes from ~5% to ~35-40% during anomaly window due to increased /api/error traffic"
    }

    with open("ground_truth.json", "w") as f:
        json.dump(ground_truth, f, indent=2)

    print(f"[{datetime.now()}] ground_truth.json saved!")
    print(f"[{datetime.now()}] Traffic generation complete! Total: {base_requests + anomaly_requests} requests")

if __name__ == "__main__":
    run()