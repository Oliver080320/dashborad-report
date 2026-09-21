-- =============================================================================
-- Migration 004 — Create tdu_callers
-- Maps CRM login user_name to a queue type and pax rule.
-- Replaces the hardcoded INDIA_CALLERS and GROUPS_CALLERS constants in config.php.
--
-- Full name is read at runtime via JOIN with vtiger_users
-- (CONCAT(first_name, ' ', last_name)), so name changes in the CRM are
-- picked up automatically without touching code.
--
-- pax_role: used only for groups_caller rows. 'high' = handles groups above
-- GROUPS_PAX_THRESHOLD (currently Arun); 'low' = handles groups at or below
-- (currently Karthik). NULL for all other queue types.
--
-- active: set to 0 to remove a caller from the queue without deleting the row
-- (preserves the audit trail in daily_runs).
--
-- Run once. IF NOT EXISTS makes the CREATE safe to re-run.
-- Schema: dev2yourbestwayh_v5
-- =============================================================================

USE dev2yourbestwayh_v5;

CREATE TABLE IF NOT EXISTS tdu_callers (
    id          INT         NOT NULL AUTO_INCREMENT,
    user_name   VARCHAR(50) NOT NULL,   -- matches $_SESSION['user_name'] and vtiger_users.user_name
    queue_type  VARCHAR(30) NOT NULL,   -- india_caller | groups_caller | asia_caller | field_rep
    pax_role    VARCHAR(10) NULL,       -- 'high' | 'low' | NULL (groups_caller only)
    active      TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user (user_name)
);

-- India FIT callers
INSERT INTO tdu_callers (user_name, queue_type, pax_role) VALUES
    ('HarshP', 'india_caller', NULL),
    ('hemant', 'india_caller', NULL);

-- Groups & MICE callers
-- pax_role 'high' = total pax > GROUPS_PAX_THRESHOLD (50) → Arun
-- pax_role 'low'  = total pax <= GROUPS_PAX_THRESHOLD (50) → Karthik
INSERT INTO tdu_callers (user_name, queue_type, pax_role) VALUES
    ('ArunP',   'groups_caller', 'high'),
    ('karthik', 'groups_caller', 'low');
