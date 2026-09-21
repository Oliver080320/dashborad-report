-- schema_migrations: tracks which migrations have been applied to THIS database.
-- Comments in the individual migration files can't tell environments apart (the same
-- file is deployed to both staging and production), which is exactly how production
-- ended up missing migration 006 while staging already had it. This table lives inside
-- each database separately, so querying it always reflects the true state of that
-- specific environment.
--
-- Convention going forward: whenever a migration is applied by hand, also insert a row
-- here for it (same connection, right after running the .sql file).

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration  VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Backfill — only insert rows for migrations ALREADY APPLIED on the database you're
-- running this against.
INSERT IGNORE INTO schema_migrations (migration) VALUES
    ('001_phase1_tables.sql'),
    ('002_outcome_column.sql'),
    ('003_vtiger_groups_country.sql'),
    ('004_tdu_callers.sql'),
    ('005_indexes.sql'),
    ('006_quote_closure_feedback.sql'),
    ('007_tdu_queue_access_view.sql'),
    ('008_lead_closure_feedback.sql');
