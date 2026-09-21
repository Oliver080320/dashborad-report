-- Migration 006: quote closure feedback
-- Stores structured rejection reasons when a caller closes a quote via the outcome modal.
-- Accepted and Requote closures are stage-only changes (no row written here).
-- Channel is captured at closure time (not via tdu_quotes_followup_ext — rejected quotes
-- do not write to vtiger_quotes_followup, so there is no followup_id to link to).

CREATE TABLE IF NOT EXISTS tdu_quote_closure_feedback (
    id           INT           NOT NULL AUTO_INCREMENT,
    quoteid      INT           NOT NULL,
    category     VARCHAR(32)   NOT NULL,          -- 'company' | 'competition' | 'client' | 'other'
    reason       VARCHAR(64)   NOT NULL,          -- reason code, e.g. 'too_expensive'
    other_reason VARCHAR(255)  DEFAULT NULL,      -- free text when reason = 'other'
    channel      VARCHAR(32)   NOT NULL DEFAULT 'phone',
    created_by   VARCHAR(64)   NOT NULL DEFAULT '',
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_quoteid    (quoteid),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_closure_quoteid FOREIGN KEY (quoteid)
        REFERENCES vtiger_quotes (quoteid)
)
