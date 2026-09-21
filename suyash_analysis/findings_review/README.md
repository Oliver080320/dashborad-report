# Current revision

See [README_v2.md](README_v2.md) for the updated dashboard, Word report and review pack.

# Sales findings review

Prepared for Oliver's review on 21 September 2026. Nothing has been published or sent.

- **Read the report:** [SALES_FINDINGS_REPORT.md](SALES_FINDINGS_REPORT.md)
- **Interactive report:** http://127.0.0.1:4175 (while the local server is running).
- **Full evidence:** `evidence/`, including affected-row CSVs, dashboard screenshots, panel exports and source fingerprints.
- **Calculations:** `analyze.py` and `analysis.ipynb`. Run from this folder; the original seven source CSVs must remain at `../../data/`.
- **Verification:** `verification.json` records hash, export, row-count, reconciliation and browser checks.

The review covers the supplied historical exports, not a refreshed live database. Product, vendor, acquisition-source and origin-region rankings cannot be established from the available mapped fields. The report explains these gaps and uses destination and FIT/Groups only as explicitly labeled alternative cuts.

To serve the compiled interactive report again, run from this folder:

```powershell
python -m http.server 4175 --bind 127.0.0.1 --directory report_app/dist
```

The review pack contains a portable HTML report, Markdown report, evidence, notebook and calculation scripts. The portable HTML's source inspector and issue-download buttons contain embedded evidence; its ordinary relative evidence links use the adjacent `evidence` folder.
