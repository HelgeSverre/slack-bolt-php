-- Original, unversioned durable-job schema. Keep this fixture independent of migrations.
CREATE TABLE thunder_bolt_jobs (
    id TEXT PRIMARY KEY,
    tenant TEXT NOT NULL,
    type TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    context_json TEXT NOT NULL,
    retry_json TEXT NOT NULL,
    status TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    attempt_limit INTEGER NOT NULL,
    available_at INTEGER NOT NULL,
    lease_token TEXT NULL,
    lease_expires_at INTEGER NULL,
    cancellation_requested INTEGER NOT NULL DEFAULT 0,
    failure_type TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE (tenant, type, idempotency_key)
);
CREATE INDEX thunder_bolt_jobs_due ON thunder_bolt_jobs(status, available_at);
CREATE INDEX thunder_bolt_jobs_leases ON thunder_bolt_jobs(status, lease_expires_at);
