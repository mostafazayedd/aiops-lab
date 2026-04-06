import json
import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler
import warnings
warnings.filterwarnings('ignore')

# ─── Step 1: Load and Parse Logs ─────────────────────────────────────────────

print("=" * 60)
print("AIOps ML Anomaly Detection Pipeline")
print("=" * 60)
print("\n[1/6] Loading logs...")

records = []
with open('storage/logs/aiops.log', 'r', encoding='utf-8') as f:
    for line in f:
        line = line.strip()
        if not line:
            continue
        try:
            entry = json.loads(line)
            ctx = entry.get('context', {})
            if ctx.get('path') is None:
                continue
            records.append({
                'timestamp':      ctx.get('timestamp', entry.get('datetime')),
                'endpoint':       ctx.get('path', 'unknown'),
                'latency':        float(ctx.get('latency_ms', 0)),
                'status_code':    int(ctx.get('status_code', 200)),
                'error_category': ctx.get('error_category') or 'none',
                'method':         ctx.get('method', 'GET'),
            })
        except Exception:
            continue

df = pd.DataFrame(records)
df['timestamp'] = pd.to_datetime(df['timestamp'], utc=True)
df = df.sort_values('timestamp').reset_index(drop=True)
df['is_error'] = df['status_code'].apply(lambda x: 1 if x >= 400 else 0)

print(f"    Loaded {len(df)} log records")
print(f"    Time range: {df['timestamp'].min()} → {df['timestamp'].max()}")
print(f"    Endpoints: {df['endpoint'].unique().tolist()}")

# ─── Step 2: Build Dataset with Time Windows ─────────────────────────────────

print("\n[2/6] Building dataset with 60s time windows...")

df.set_index('timestamp', inplace=True)
df_resampled = df.resample('60s')

rows = []
for window_start, window_df in df_resampled:
    if len(window_df) == 0:
        continue

    for endpoint in ['/api/normal', '/api/slow', '/api/db', '/api/error', '/api/validate']:
        ep_df = window_df[window_df['endpoint'] == endpoint.replace('/api/', 'api/')]
        # Try both with and without leading slash
        if len(ep_df) == 0:
            ep_df = window_df[window_df['endpoint'].str.contains(endpoint.replace('/api/', ''), na=False)]

        if len(ep_df) == 0:
            continue

        total     = len(ep_df)
        errors    = ep_df['is_error'].sum()
        latencies = ep_df['latency'].values

        rows.append({
            'timestamp':          window_start,
            'endpoint':           endpoint,
            'latency':            float(np.mean(latencies)),
            'avg_latency':        float(np.mean(latencies)),
            'max_latency':        float(np.max(latencies)),
            'latency_std':        float(np.std(latencies)) if len(latencies) > 1 else 0.0,
            'request_rate':       float(total / 60.0),
            'error_rate':         float(errors / total) if total > 0 else 0.0,
            'errors_per_window':  int(errors),
            'endpoint_frequency': int(total),
            'error_category':     ep_df['error_category'].mode()[0] if len(ep_df) > 0 else 'none',
        })

dataset = pd.DataFrame(rows)
dataset = dataset.sort_values(['timestamp', 'endpoint']).reset_index(drop=True)

print(f"    Dataset size: {len(dataset)} observations")
print(f"    Columns: {dataset.columns.tolist()}")

# Save dataset
dataset.to_csv('aiops_dataset.csv', index=False)
print("    Saved → aiops_dataset.csv")

# ─── Step 3: Feature Engineering ─────────────────────────────────────────────

print("\n[3/6] Engineering features...")

features = ['avg_latency', 'max_latency', 'request_rate', 'error_rate',
            'latency_std', 'errors_per_window', 'endpoint_frequency']

# One-hot encode endpoint
dataset_ml = pd.get_dummies(dataset, columns=['endpoint'], prefix='ep')
ep_cols = [c for c in dataset_ml.columns if c.startswith('ep_')]
feature_cols = features + ep_cols

X = dataset_ml[feature_cols].fillna(0)
print(f"    Feature matrix: {X.shape[0]} rows × {X.shape[1]} features")

# ─── Step 4: Train on Normal Period ──────────────────────────────────────────

print("\n[4/6] Training Isolation Forest on normal behavior period...")

# Use first 60% of data as normal training period
split_idx     = int(len(X) * 0.6)
X_train       = X.iloc[:split_idx]
timestamps_all = dataset_ml['timestamp'] if 'timestamp' in dataset_ml.columns else dataset['timestamp']

scaler  = StandardScaler()
X_train_scaled = scaler.fit_transform(X_train)
X_all_scaled   = scaler.transform(X)

model = IsolationForest(
    n_estimators=200,
    contamination=0.05,
    random_state=42,
    max_samples='auto'
)
model.fit(X_train_scaled)
print(f"    Trained on {len(X_train)} normal observations (first 60%)")

# ─── Step 5: Anomaly Prediction ──────────────────────────────────────────────

print("\n[5/6] Running anomaly predictions...")

scores      = model.decision_function(X_all_scaled)
predictions = model.predict(X_all_scaled)

# Normalize score: lower = more anomalous, flip so higher = more anomalous
anomaly_scores = -scores

results = dataset[['timestamp', 'endpoint']].copy()
results['anomaly_score'] = anomaly_scores
results['is_anomaly']    = (predictions == -1).astype(int)

anomaly_count = results['is_anomaly'].sum()
print(f"    Total anomalies detected: {anomaly_count} / {len(results)}")
print(f"    Anomaly rate: {anomaly_count/len(results)*100:.1f}%")

# Show detected anomalies per endpoint
print("\n    Anomalies by endpoint:")
for ep, grp in results.groupby('endpoint'):
    count = grp['is_anomaly'].sum()
    print(f"      {ep}: {count} anomalous windows")

results.to_csv('anomaly_predictions.csv', index=False)
print("\n    Saved → anomaly_predictions.csv")

# ─── Step 6: Visualization ───────────────────────────────────────────────────

print("\n[6/6] Generating visualization plots...")

endpoints  = dataset['endpoint'].unique()
n_eps      = len(endpoints)
fig, axes  = plt.subplots(n_eps * 2, 1, figsize=(16, n_eps * 6))
fig.suptitle('AIOps ML Anomaly Detection — Latency & Error Rate Timelines', fontsize=14, fontweight='bold')

plot_idx = 0
for ep in sorted(endpoints):
    ep_data  = dataset[dataset['endpoint'] == ep].copy()
    ep_preds = results[results['endpoint'] == ep].copy()

    if len(ep_data) == 0:
        plot_idx += 2
        continue

    # Merge anomaly labels
    ep_data = ep_data.merge(ep_preds[['timestamp', 'is_anomaly', 'anomaly_score']], on='timestamp', how='left')
    anomalies = ep_data[ep_data['is_anomaly'] == 1]

    # ── Latency Timeline ──
    ax1 = axes[plot_idx]
    ax1.plot(ep_data['timestamp'], ep_data['avg_latency'], color='steelblue', linewidth=1.2, label='Avg Latency (ms)')
    ax1.fill_between(ep_data['timestamp'], ep_data['avg_latency'], alpha=0.1, color='steelblue')
    if len(anomalies) > 0:
        ax1.scatter(anomalies['timestamp'], anomalies['avg_latency'],
                    color='red', s=60, zorder=5, label='Anomaly', marker='x', linewidths=2)
    ax1.set_title(f'{ep} — Latency', fontsize=10, fontweight='bold')
    ax1.set_ylabel('Latency (ms)')
    ax1.legend(fontsize=8)
    ax1.grid(True, alpha=0.3)
    ax1.xaxis.set_major_formatter(mdates.DateFormatter('%H:%M'))
    plot_idx += 1

    # ── Error Rate Timeline ──
    ax2 = axes[plot_idx]
    ax2.plot(ep_data['timestamp'], ep_data['error_rate'] * 100, color='darkorange', linewidth=1.2, label='Error Rate (%)')
    ax2.fill_between(ep_data['timestamp'], ep_data['error_rate'] * 100, alpha=0.1, color='darkorange')
    if len(anomalies) > 0:
        ax2.scatter(anomalies['timestamp'], anomalies['error_rate'] * 100,
                    color='red', s=60, zorder=5, label='Anomaly', marker='x', linewidths=2)
    ax2.set_title(f'{ep} — Error Rate', fontsize=10, fontweight='bold')
    ax2.set_ylabel('Error Rate (%)')
    ax2.legend(fontsize=8)
    ax2.grid(True, alpha=0.3)
    ax2.xaxis.set_major_formatter(mdates.DateFormatter('%H:%M'))
    plot_idx += 1

plt.tight_layout()
plt.savefig('anomaly_plots.png', dpi=150, bbox_inches='tight')
print("    Saved → anomaly_plots.png")

print("\n" + "=" * 60)
print("Pipeline Complete!")
print("=" * 60)
print("Output files:")
print("  aiops_dataset.csv        — feature dataset")
print("  anomaly_predictions.csv  — ML predictions")
print("  anomaly_plots.png        — visualization")
