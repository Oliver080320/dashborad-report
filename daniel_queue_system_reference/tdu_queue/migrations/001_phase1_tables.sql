-- =============================================================================
-- Migration 001 — All phase tables
-- Run once before Phase 1 goes live.
-- Schema: dev2yourbestwayh_v5
--
-- Note: MySQL DDL statements (CREATE TABLE) cause an implicit commit and cannot
-- be rolled back. IF NOT EXISTS makes re-running this script safe. If a table
-- already exists the statement is skipped without error.
-- =============================================================================

USE dev2yourbestwayh_v5;

-- =============================================================================
-- PHASE 1 — Critical tables
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. tdu_quotes_followup_ext
--    Extends vtiger_quotes_followup with structured outcome data.
--    Legacy rows written before this system have no matching row here.
--    Every new row written by our queue inserts into both tables inside a single
--    transaction — there are never orphaned records.
--    One ext row per follow-up row enforced by UNIQUE KEY on followup_id.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tdu_quotes_followup_ext (
    id              INT          NOT NULL AUTO_INCREMENT,
    followup_id     INT          NOT NULL,
    outcome         VARCHAR(30)  NOT NULL,   -- next_call | no_answer_email | interested | inbound_call
    channel         VARCHAR(20)  NOT NULL DEFAULT 'phone',  -- phone | whatsapp | email | linkedin | in_person | other
    snooze_until    DATE         NULL,       -- NULL = no cool-down; date = hidden until this date
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  uq_followup_id (followup_id),
    FOREIGN KEY (followup_id) REFERENCES vtiger_quotes_followup(auto_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- -----------------------------------------------------------------------------
-- 2. daily_runs
--    Snapshot of what was surfaced to each caller on each day.
--    Serves three purposes:
--      (a) Consistency — serves the same queue on page refresh.
--      (b) Safety check — detects quotes that disappeared without a stage change.
--      (c) Audit trail — manager can see exactly what each caller was working on.
--
--    item_type + item_id is a polymorphic FK (quote in Phase 1, org in Phase 2+).
--    No DB-level FK is possible on a polymorphic column — the PHP safety-check
--    query must treat a missing quoteid in vtiger_quotes as a flag, not a skip.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_runs (
    id          INT          NOT NULL AUTO_INCREMENT,
    run_date    DATE         NOT NULL,
    user_name   VARCHAR(50)  NOT NULL,
    bucket      VARCHAR(30)  NOT NULL,   -- followup | engagement | outreach
    item_type   VARCHAR(20)  NOT NULL,   -- quote (Phase 1) | org (Phase 2+)
    item_id     INT          NOT NULL,   -- quoteid or organizationid
    position    INT          NOT NULL,   -- position in the queue (1, 2, 3 ...)
    original_owner VARCHAR(100) NULL,    -- assigned_to_sales_agent BEFORE assign-on-encounter
                                         -- rewrote it. Backup for rolling quotes back to their
                                         -- pre-takeover owner. For the true original, read the
                                         -- EARLIEST run_date per item_id (later days hold the
                                         -- post-reassignment owner).
    PRIMARY KEY (id),
    UNIQUE KEY uq_run (run_date, user_name, bucket, item_type, item_id),
    INDEX idx_run_user (run_date, user_name),
    INDEX idx_item (item_type, item_id, run_date)
);

-- For an already-created daily_runs (staging/prod), add the column with:
--   ALTER TABLE daily_runs ADD COLUMN original_owner VARCHAR(100) NULL AFTER position;

-- =============================================================================
-- PHASE 2 — Create now, used in Phase 2
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 4. tdu_org_followup_ext
--    Extends tdu_organisation_followup with structured outcome data.
--    Same extension pattern as tdu_quotes_followup_ext but for org-level touches.
--    disqualified_reason is required when outcome = 'disqualified'.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tdu_org_followup_ext (
    id                  INT          NOT NULL AUTO_INCREMENT,
    followup_id         INT          NOT NULL,
    outcome             VARCHAR(30)  NOT NULL,   -- next_call | no_answer | email_sent | interested | inbound_call | no_opportunity | disqualified
    channel             VARCHAR(20)  NOT NULL DEFAULT 'phone',
    snooze_until        DATE         NULL,
    disqualified_reason VARCHAR(255) NULL,       -- required when outcome = 'disqualified'
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY  uq_followup_id (followup_id),
    FOREIGN KEY (followup_id) REFERENCES tdu_organisation_followup(auto_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- -----------------------------------------------------------------------------
-- 5. engagement_exclusions
--    High-tier organisations excluded from the Engagement queue.
--    Populated and maintained by the sales manager.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS engagement_exclusions (
    id              INT          NOT NULL AUTO_INCREMENT,
    organizationid  INT          NOT NULL,
    reason          VARCHAR(255) NULL,
    added_by        VARCHAR(50)  NULL,
    added_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_org (organizationid)
);
