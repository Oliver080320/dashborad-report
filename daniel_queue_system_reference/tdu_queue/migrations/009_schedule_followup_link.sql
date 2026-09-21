-- Migration 009: schedule_followup_id link
-- Records which schedule_follow_up row a call actually owns, instead of the queue guessing
-- by row position. Set only when a call creates its own schedule row in the same request;
-- left NULL when it inherits an existing open promise, so the two cases can be told apart.
-- queue_builder.php's worked_today anchor uses it to pick which of a day's calls the row's
-- Edit button corrects, falling back to the day's last call when nothing is linked (every
-- row from before this migration, and any promise set from details.php).
-- No FK: this project's two previously-defined FKs don't actually exist in the real database
-- (stripped when applied by hand), and the value here always comes from an id the same
-- transaction just computed, never an untrusted source.
-- No index: the only read is a per-quote MAX(CASE ...) over rows already joined on
-- followup_id (which has its own UNIQUE).
ALTER TABLE tdu_quotes_followup_ext
    ADD COLUMN schedule_followup_id INT NULL AFTER snooze_until;

-- Record the migration by hand after running (no runner exists in this project):
-- INSERT INTO schema_migrations (migration) VALUES ('009_schedule_followup_link.sql');
