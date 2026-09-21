-- Migration 008: lead closure feedback
-- Stores structured rejection reasons when a caller closes a Lead via the outcome modal.
-- Same shape as 006, kept separate because a Lead falls through for different reasons than
-- a worked quote does, so the two reason lists diverge.
-- Lead -> Created (converted) is stage-only, no row written here, as with Accepted in 006.
-- No FK to vtiger_quotes_followup: a rejected Lead skips that table entirely.
CREATE TABLE IF NOT EXISTS tdu_lead_closure_feedback (
    id           INT          NOT NULL AUTO_INCREMENT,
    quoteid      INT          NOT NULL,
    category     VARCHAR(50)  NOT NULL,
    reason       VARCHAR(100) NOT NULL,
    other_reason TEXT         NULL,
    channel      VARCHAR(20)  NULL,
    created_by   VARCHAR(50)  NOT NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_quoteid (quoteid),
    INDEX idx_created_at (created_at)
);
