-- Migration 002: add outcome column to vtiger_quotes_followup (test DB only)
-- The outcome code (next_call, no_answer_email, interested, inbound_call) is stored
-- here so it is visible in the CRM and the history panel without joining the ext table.
-- The ext table keeps outcome for snooze logic compatibility; this column is the
-- canonical display source.

ALTER TABLE vtiger_quotes_followup
    ADD COLUMN outcome VARCHAR(30) NULL AFTER description;
