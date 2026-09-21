-- Migration 007: tdu_queue_access_view
-- Read-only access whitelist for helpers who are neither a configured caller
-- nor an admin. Same pattern as tdu_callers — managed by hand via SQL, no
-- admin UI. scope is a string (not boolean) so a future role doesn't need a
-- schema change.

CREATE TABLE IF NOT EXISTS tdu_queue_access_view (
    id        INT          NOT NULL AUTO_INCREMENT,
    user_name VARCHAR(50)  NOT NULL,   -- matches $_SESSION['user_name'] and vtiger_users.user_name
    scope     VARCHAR(50)  NOT NULL,   -- 'india_fit_viewer'
    active    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_name (user_name)
);

-- Seed: mayur is the FIT helper (confirmed 2026-08-06). Their vtiger_users row
-- already exists with title 'sales', which is left untouched — this table is the
-- only thing granting the read-only view.
-- ON DUPLICATE KEY UPDATE because staging already has this row, inserted by hand
-- during testing before the migration file was finalised.
INSERT INTO tdu_queue_access_view (user_name, scope) VALUES ('mayur', 'india_fit_viewer');

-- Record the migration by hand after running (no runner exists in this project):
-- INSERT INTO schema_migrations (migration) VALUES ('007_tdu_queue_access_view.sql');
