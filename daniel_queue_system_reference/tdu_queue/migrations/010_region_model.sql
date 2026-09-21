-- Migration 010: regional model
-- Additive only: no existing table changes shape and no existing query is affected.
--
-- No table here for who owns a region. That roster is the CRM's own
-- tdu_auto_assign_rules (assign_type = 'region', category 'sales' or 'external'), the
-- same rows quotes.php reads when it stamps ownership on a newly created quote, so the
-- queue and the CRM cannot drift apart and then overwrite each other daily. A person
-- covering several regions simply has a row per region. What that table cannot enforce,
-- config.php decides instead: a region named by two different people is left unowned and
-- reported on screen, rather than one of them being picked silently.
--
-- Deliberately NOT included here, and not planned: tdu_region_moves and a can_transfer
-- flag, for a supervisor moving a quote between regions. That capability was considered
-- and dropped, not deferred.
--
-- No table for the regions themselves either, and none translating CRM regions to queue
-- regions. A tdu_region_map used to fold the three Mumbai sub-regions into a single 'West'
-- queue region; dropped 2026-09-02, because it meant four auto-assign rules had to keep
-- naming the same person or West (179 live quotes) lost its owner over a rule covering
-- nine. The queue's regions are now exactly the CRM's own India regions, read from
-- vtiger_groups (country = 'India'), with their capacity as the derived QUEUE_REGIONS
-- constant in config.php. Per-region daily_capacity was never a table for the same reason
-- it is not one now: a tuning number follows this project's convention for those
-- (DAILY_CAPACITY, MAX_SCHEDULED_PER_DAY_PER_CALLER are PHP constants), not the
-- convention for rosters (tdu_callers, tdu_queue_access_view).
--
-- can_personal_claim on tdu_queue_access_view (created by migration 007) grants the
-- "pull a Follow-up quote to yourself" capability explicitly, independent of scope or
-- region ownership, so granting it to someone who is later also given a region (or who
-- stops being a supervisor) never silently changes.

-- The region model's own daily snapshot. The old daily_runs is left completely
-- untouched, forever; this is a separate table, not a repurposing.
CREATE TABLE IF NOT EXISTS tdu_region_daily_runs (
    id              INT          NOT NULL AUTO_INCREMENT,
    run_date        DATE         NOT NULL,
    queue_region    VARCHAR(50)  NOT NULL,
    bucket          VARCHAR(30)  NOT NULL,
    item_type       VARCHAR(20)  NOT NULL,
    item_id         INT          NOT NULL,
    position        INT          NOT NULL,
    owner_user_name VARCHAR(50)  NULL,  -- the region's owner's login user_name at the
                                        -- moment this row was written; NULL if the
                                        -- region had no owner. Point-in-time, not a
                                        -- live join: if the owner later changes, this
                                        -- row keeps showing who actually held it.
    PRIMARY KEY (id),
    UNIQUE KEY uq_run (run_date, queue_region, bucket, item_type, item_id),
    INDEX idx_run_region (run_date, queue_region),
    INDEX idx_item (item_type, item_id, run_date)
);

ALTER TABLE tdu_queue_access_view
    ADD COLUMN can_personal_claim TINYINT(1) NOT NULL DEFAULT 0;

-- Seed data

-- Supervisors (view every region, log outcomes anywhere). Karthik owns no region, so he
-- also gets can_personal_claim: the ability to pull a Follow-up quote to himself and work
-- it directly, rather than only ever seeing the merged read side of every region.
INSERT INTO tdu_queue_access_view (user_name, scope, can_personal_claim) VALUES
    ('karthik', 'supervisor', 1),
    ('Prajna',  'supervisor', 0)
ON DUPLICATE KEY UPDATE scope = VALUES(scope), can_personal_claim = VALUES(can_personal_claim), active = 1;

-- Post-sale pair (Payment Deadline + Awaiting Information, all five regions, no
-- per-region split, no Follow-up/Leads access).
INSERT INTO tdu_queue_access_view (user_name, scope) VALUES
    ('hemant',  'post_sale'),
    ('Dhiraj',  'post_sale')
ON DUPLICATE KEY UPDATE scope = VALUES(scope), active = 1;

-- Retire the old read-only helper role: mayur becomes a region owner, cannot hold both.
-- Run only when fit_viewer.php is deleted, not before.
-- DELETE FROM tdu_queue_access_view WHERE user_name = 'mayur' AND scope = 'india_fit_viewer';

-- Record the migration by hand after running (no runner exists in this project):
-- INSERT INTO schema_migrations (migration) VALUES ('010_region_model.sql');
