-- =============================================================================
-- Migration 005 — Performance indexes
-- Run after 001–004 on any environment.
-- Schema: dev2yourbestwayh_v5
--
-- Note: ALTER TABLE ADD INDEX is not transactional in MySQL — it cannot be
-- rolled back. Each statement is safe to re-run only if the index does not
-- already exist; duplicate index names cause an error. Verify with:
--   SHOW INDEX FROM <table> WHERE Key_name = '<index_name>';
-- before running on an environment where earlier migrations may have added
-- these manually.
-- =============================================================================

USE dev2yourbestwayh_v5;

-- -----------------------------------------------------------------------------
-- vtiger_quotes_followup
--   Existing CRM table. Three indexes to cover the subqueries in queue_builder.php.
-- -----------------------------------------------------------------------------

-- Used by call_count and schedule subqueries (filter by quoteid + followup_type)
ALTER TABLE vtiger_quotes_followup
    ADD INDEX idx_quoteid_type (quoteid, followup_type);

-- Used by worked_today detection (filter by calltime date + outcome)
ALTER TABLE vtiger_quotes_followup
    ADD INDEX idx_calltime_outcome (calltime, outcome);

-- Used by load_carryover_quotes (latest calltime per quote)
ALTER TABLE vtiger_quotes_followup
    ADD INDEX idx_quoteid_calltime (quoteid, calltime);

-- daily_runs indexes are created inline in migration 001 (CREATE TABLE).
