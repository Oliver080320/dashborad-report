-- =============================================================================
-- Migration 003 — Add country column to vtiger_groups
-- Identifies which groups belong to which country/region scope.
-- Used by config.php to build the INDIA_REGIONS list dynamically instead of
-- the hardcoded INDIA_REGIONS constant.
--
-- Run once. Safe to re-run: UPDATE with WHERE groupid IN (...) is idempotent.
-- ALTER TABLE will error if the column already exists — comment it out on re-run.
-- Schema: dev2yourbestwayh_v5
-- =============================================================================

USE dev2yourbestwayh_v5;

ALTER TABLE vtiger_groups
    ADD COLUMN country VARCHAR(50) NULL AFTER description;

-- India regions — groupids confirmed from vtiger_groups (SELECT * FROM vtiger_groups):
--   12  North
--   13  South
--   14  East
--   28  West
--   29  Gujarat
--   112 West Central Mumbai
--   113 West Western Mumbai Pune & Nashik
--   114 West Harbour Mumbai Solapur & Nagpur
--
-- Verify these groupids match production before running:
--   SELECT groupid, groupname FROM vtiger_groups WHERE groupid IN (12,13,14,28,29,112,113,114);
UPDATE vtiger_groups
    SET country = 'India'
    WHERE groupid IN (12, 13, 14, 28, 29, 112, 113, 114);
