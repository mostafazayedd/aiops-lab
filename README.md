# AIOps Observability System

A Laravel-based AIOps observability system that emits ML-ready telemetry with structured logs, correlation IDs, Prometheus RED metrics, and a controlled traffic generator with ground-truth anomaly injection.

## Stack
- **Laravel 12** — API + telemetry middleware
- **SQLite** — database simulation
- **Prometheus** — metrics scraping
- **Grafana** — dashboards + anomaly visualization
- **Python** — traffic generator

## API Endpoints
| Endpoint | Method | Description |
|----------|--------|-------------|
| /api/normal | GET | Returns 200 OK |
| /api/slow | GET | Simulates slow response (1-3s), ?hard=1 for 5-7s |
| /api/error | GET | Simulates 500 error |
| /api/random | GET | Random success/slow/error |
| /api/db | GET | SQLite query, ?fail=1 forces QueryException |
| /api/validate | POST | Validates email + age (18-60) |
| /api/metrics | GET | Prometheus metrics endpoint |

## Setup

### Laravel API
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Docker (Prometheus + Grafana)
```bash
docker-compose up -d
```
- Prometheus: http://localhost:9090
- Grafana: http://localhost:3000 (admin/admin)

### Traffic Generator
```bash
pip install requests
python traffic_generator.py
```

## Log Schema
Every request produces a structured JSON log entry in `storage/logs/aiops.log` with these fields:
`correlation_id`, `method`, `path`, `route_name`, `status_code`, `latency_ms`, `error_category`, `severity`, `client_ip`, `user_agent`, `query`, `payload_size_bytes`, `response_size_bytes`, `build_version`, `host`, `timestamp`

## Error Categories
| Category | Trigger |
|----------|---------|
| VALIDATION_ERROR | Invalid email or age |
| DATABASE_ERROR | QueryException |
| TIMEOUT_ERROR | Latency > 4000ms (even if HTTP 200) |
| SYSTEM_ERROR | Unhandled 500 errors |
| UNKNOWN | Everything else |

## Anomaly Design
The traffic generator injects a 2-minute error spike anomaly:
- Base load: 5% error rate
- Anomaly window: 35-40% error rate
- Ground truth saved to `ground_truth.json`

## Deliverables
- `storage/logs/aiops.log` — 1600 structured log entries
- `logs.json` — exported logs
- `ground_truth.json` — anomaly window timestamps
- `docker-compose.yml` + `prometheus.yml` — monitoring stack
- `traffic_generator.py` — controlled traffic generator