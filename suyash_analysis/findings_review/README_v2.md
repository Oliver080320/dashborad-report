# Revised sales insights report ? v2

Review draft prepared 21 September 2026. Source data remains unchanged.

- Sales_Insights_Report_v2.docx: eight main pages and nine pages of audit appendices.
- Sales_Findings_Report.html: portable interactive report; open in Edge or Chrome.
- Local served dashboard: http://127.0.0.1:4175 while the report server is running.
- Suyash_Dashboard_Fixes_and_Review_Notes.docx: separate implementation and named-caller review note.
- evidence/: original complete proof extracts and screenshots.
- evidence_v2/: revised chart inputs and issue-number crosswalk.
- archive_v1/: original report and analysis preserved unchanged.

Unzip the complete review pack so the evidence directories stay beside the Word file. The Word appendices contain counts and examples; full row extracts are in these directories. Interactive source menus inspect embedded datasets, and Appendix A downloads affected rows.

To restart the served dashboard from the repository root:

    python -m http.server 4175 --bind 127.0.0.1 --directory suyash_analysis/findings_review/report_app/dist

The editorial source is report_app/src/content/report/findings-v2.json, with reviewed chart rows in report_app/src/data.json. The original build_report.py and initial revise_v2.py are retained as historical calculation scripts; rerunning them is not the final editorial build. write_v2_docx.py generates Word from the current editorial source and browser-captured v2_charts.

GitHub upload authorized by Oliver. This remains a review draft; no message has been sent to the sales team.
