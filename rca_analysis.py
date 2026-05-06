import json
import os
from datetime import datetime, timezone
from collections import defaultdict
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
import numpy as np

# ── Load data ────────────────────────────────────────────────
with open('logs.json', 'r') as f:
    logs = json.load(f)

with open('ground_truth.json', 'r') as f:
    ground_truth = json.load(f)

anomaly_start = datetime.fromisoformat("2026-03-09T21:45:00+00:00")
anomaly_end   = datetime.fromisoformat("2026-03-09T22:30:00+00:00")

# ── Helper ───────────────────────────────────────────────────
def parse_ts(ts):
    try:
        return datetime.fromisoformat(ts)
    except:
        return None

def in_anomaly(ts):
    if ts is None:
        return False
    ts_aware = ts if ts.tzinfo else ts.replace(tzinfo=timezone.utc)
    return anomaly_start <= ts_aware <= anomaly_end

# ── Split logs into normal and anomaly windows ────────────────
normal_logs  = []
anomaly_logs = []

for log in logs:
    ts = parse_ts(log.get('timestamp', ''))
    if in_anomaly(ts):
        anomaly_logs.append(log)
    else:
        normal_logs.append(log)

print(f"Normal window logs  : {len(normal_logs)}")
print(f"Anomaly window logs : {len(anomaly_logs)}")

# ── Signal Analysis ───────────────────────────────────────────
def analyze_window(window_logs, label):
    total = len(window_logs)
    if total == 0:
        return {}

    errors = [l for l in window_logs if l.get('severity') == 'error']
    error_rate = len(errors) / total * 100

    latencies = [l.get('latency_ms', 0) for l in window_logs]
    avg_latency = sum(latencies) / len(latencies)
    p95_latency = float(np.percentile(latencies, 95))

    endpoint_counts = defaultdict(int)
    endpoint_errors  = defaultdict(int)
    for l in window_logs:
        path = l.get('path', 'unknown')
        endpoint_counts[path] += 1
        if l.get('severity') == 'error':
            endpoint_errors[path] += 1

    error_categories = defaultdict(int)
    for l in errors:
        cat = l.get('error_category', 'UNKNOWN')
        error_categories[cat] += 1

    print(f"\n── {label} ──")
    print(f"  Total requests : {total}")
    print(f"  Error rate     : {error_rate:.1f}%")
    print(f"  Avg latency    : {avg_latency:.1f}ms")
    print(f"  P95 latency    : {p95_latency:.1f}ms")
    print(f"  Endpoints      : {dict(endpoint_counts)}")
    print(f"  Error cats     : {dict(error_categories)}")

    return {
        'total': total,
        'error_rate': round(error_rate, 2),
        'avg_latency_ms': round(avg_latency, 2),
        'p95_latency_ms': round(p95_latency, 2),
        'endpoint_counts': dict(endpoint_counts),
        'endpoint_errors': dict(endpoint_errors),
        'error_categories': dict(error_categories),
    }

normal_stats  = analyze_window(normal_logs,  'NORMAL WINDOW')
anomaly_stats = analyze_window(anomaly_logs, 'ANOMALY WINDOW')

# ── Root Cause Identification ─────────────────────────────────
# Find endpoint with most errors during anomaly
top_endpoint = max(
    anomaly_stats['endpoint_errors'].items(),
    key=lambda x: x[1],
    default=('unknown', 0)
)

# Find primary signal
error_rate_delta = anomaly_stats['error_rate'] - normal_stats['error_rate']
latency_delta    = anomaly_stats['avg_latency_ms'] - normal_stats['avg_latency_ms']

primary_signal = 'error_rate' if error_rate_delta > latency_delta else 'latency'

# Find top error category
top_category = max(
    anomaly_stats['error_categories'].items(),
    key=lambda x: x[1],
    default=('UNKNOWN', 0)
)

# Confidence score
confidence = min(100, round(
    (error_rate_delta / max(normal_stats['error_rate'], 1)) * 50 +
    (top_endpoint[1] / max(anomaly_stats['total'], 1)) * 50
))

# ── Build RCA Report ──────────────────────────────────────────
rca = {
    "incident_id": "INC-20260309-001",
    "anomaly_window": {
        "start": ground_truth['anomaly_start_iso'],
        "end":   ground_truth['anomaly_end_iso'],
        "type":  ground_truth['anomaly_type'],
    },
    "signal_analysis": {
        "normal_window":  normal_stats,
        "anomaly_window": anomaly_stats,
        "error_rate_delta_pct": round(error_rate_delta, 2),
        "latency_delta_ms":     round(latency_delta, 2),
    },
    "root_cause_endpoint": top_endpoint[0],
    "primary_signal":      primary_signal,
    "supporting_evidence": {
        "top_error_category":       top_category[0],
        "top_category_count":       top_category[1],
        "endpoint_error_count":     top_endpoint[1],
        "error_rate_normal_pct":    normal_stats['error_rate'],
        "error_rate_anomaly_pct":   anomaly_stats['error_rate'],
        "avg_latency_normal_ms":    normal_stats['avg_latency_ms'],
        "avg_latency_anomaly_ms":   anomaly_stats['avg_latency_ms'],
    },
    "confidence_score": confidence,
    "recommended_action": (
        f"Investigate {top_endpoint[0]} — it generated {top_endpoint[1]} errors "
        f"during the anomaly window. Primary signal was {primary_signal} spike. "
        f"Top error category was {top_category[0]}. "
        f"Consider adding circuit breaker or rate limiting to this endpoint."
    ),
    "incident_timeline": [
        {
            "phase": "Normal State",
            "description": f"System operating normally. Error rate {normal_stats['error_rate']}%, avg latency {normal_stats['avg_latency_ms']}ms.",
            "timestamp": "2026-03-09T21:00:00+00:00"
        },
        {
            "phase": "Anomaly Start",
            "description": f"Error rate began spiking. {top_endpoint[0]} started generating excessive errors.",
            "timestamp": ground_truth['anomaly_start_iso']
        },
        {
            "phase": "Peak Incident",
            "description": f"Error rate reached {anomaly_stats['error_rate']}%. {top_category[0]} was dominant error category.",
            "timestamp": ground_truth['anomaly_start_iso']
        },
        {
            "phase": "Recovery",
            "description": "Anomaly window ended. Traffic generator returned to base load distribution.",
            "timestamp": ground_truth['anomaly_end_iso']
        }
    ]
}

with open('rca_report.json', 'w') as f:
    json.dump(rca, f, indent=2)

print("\n✅ rca_report.json saved!")

# ── Timeline Visualization ────────────────────────────────────
fig, axes = plt.subplots(3, 1, figsize=(14, 10))
fig.suptitle('AIOps Root Cause Analysis — Incident Timeline', fontsize=16, fontweight='bold')

# Build time series
time_buckets = defaultdict(lambda: {'total': 0, 'errors': 0, 'latency': []})

for log in logs:
    ts = parse_ts(log.get('timestamp', ''))
    if ts is None:
        continue
    bucket = ts.strftime('%H:%M')
    time_buckets[bucket]['total'] += 1
    if log.get('severity') == 'error':
        time_buckets[bucket]['errors'] += 1
    time_buckets[bucket]['latency'].append(log.get('latency_ms', 0))

sorted_buckets = sorted(time_buckets.keys())
x = range(len(sorted_buckets))
error_rates  = [time_buckets[b]['errors'] / max(time_buckets[b]['total'], 1) * 100 for b in sorted_buckets]
avg_latencies = [sum(time_buckets[b]['latency']) / max(len(time_buckets[b]['latency']), 1) for b in sorted_buckets]
request_rates = [time_buckets[b]['total'] for b in sorted_buckets]

anomaly_start_str = anomaly_start.strftime('%H:%M')
anomaly_end_str   = anomaly_end.strftime('%H:%M')

def get_anomaly_range(buckets, start_str, end_str):
    start_idx = next((i for i, b in enumerate(buckets) if b >= start_str), None)
    end_idx   = next((i for i, b in enumerate(buckets) if b >= end_str), len(buckets)-1)
    return start_idx, end_idx

a_start, a_end = get_anomaly_range(sorted_buckets, anomaly_start_str, anomaly_end_str)

colors = ['#e74c3c' if a_start and a_end and a_start <= i <= a_end else '#3498db' for i in x]

# Panel 1: Error Rate
axes[0].bar(x, error_rates, color=colors, alpha=0.8)
axes[0].set_title('Error Rate % Over Time', fontweight='bold')
axes[0].set_ylabel('Error Rate (%)')
axes[0].set_xticks(list(x)[::5])
axes[0].set_xticklabels(sorted_buckets[::5], rotation=45, fontsize=8)
if a_start and a_end:
axes[0].axvspan(a_start, a_end, alpha=0.15, color='red')
    

# Panel 2: Avg Latency
axes[1].plot(list(x), avg_latencies, color='#e67e22', linewidth=2)
axes[1].set_title('Average Latency (ms) Over Time', fontweight='bold')
axes[1].set_ylabel('Latency (ms)')
axes[1].set_xticks(list(x)[::5])
axes[1].set_xticklabels(sorted_buckets[::5], rotation=45, fontsize=8)
if a_start and a_end:
    axes[1].axvspan(a_start, a_end, alpha=0.15, color='red')


# Panel 3: Request Rate
axes[2].bar(x, request_rates, color=colors, alpha=0.8)
axes[2].set_title('Request Rate Over Time', fontweight='bold')
axes[2].set_ylabel('Requests per minute')
axes[2].set_xticks(list(x)[::5])
axes[2].set_xticklabels(sorted_buckets[::5], rotation=45, fontsize=8)
if a_start and a_end:
    axes[2].axvspan(a_start, a_end, alpha=0.15, color='red')


normal_patch  = mpatches.Patch(color='#3498db', label='Normal')
anomaly_patch = mpatches.Patch(color='#e74c3c', label='Anomaly')
fig.legend(handles=[normal_patch, anomaly_patch], loc='upper right')

plt.tight_layout()
plt.savefig('incident_timeline.png', dpi=150, bbox_inches='tight')
print("✅ incident_timeline.png saved!")
plt.show()