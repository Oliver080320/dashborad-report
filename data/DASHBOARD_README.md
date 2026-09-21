# Sales queue insights

Open the running dashboard at http://127.0.0.1:4173/.

**Easiest way:** double-click `Open Dashboard.cmd` in this folder. It starts the local server in the background and opens your browser. If this dashboard is already running, it reuses that server. If the port belongs to another app, it chooses another local port without stopping that app. No PowerShell command is needed.

The review tables now include suggested checks and filters for past scheduled dates, missing follow-ups and 30+ days without activity. The movement section compares distinct quote IDs between adjacent observed runs, with searchable Entered, Stayed and Left lists. Movement uses the selected assignee and destination in both runs, so transfers may appear as entries/exits. Left does not mean sold or completed.

To restart the local preview, run this command from this data folder and keep the terminal open:

```powershell
python -m http.server 4173 --bind 127.0.0.1 --directory dashboard/dist
```

The dashboard has three views:

- **Queue & follow-ups:** daily queue snapshots, assignee comparisons, follow-up review flags, searchable records and observed workload history.
- **Quote portfolio:** current stage mix, creation cohorts, destination comparisons and sales-in-progress inactivity.
- **Source coverage:** file inventory and interpretation limits.

Filters are local to the current tab session. Each evidence card provides a source menu. Queue history ends 31 August 2026; quote activity ends 11 September. These exports are not live data. Past scheduled dates do not prove tasks were missed. Inactive means no event in the provided activity log, including product edits.

Original CSV files are unchanged. Phone numbers, emails, customer names, descriptions, user profile data and free-text activity logs are not included in the dashboard snapshot. Internal quote IDs and queue assignee usernames support review.

`prepare_dashboard.py` reproduces the cleaned snapshot and analysis summary from the original CSVs. The editable app is under `dashboard/src/content/`; its source metadata is in `dashboard/src/data.json`. Preserve the app ID when refreshing its snapshot. Follow `dashboard/AGENTS.md` to rebuild after changes. Running the preparation script alone does not refresh the dashboard.

Validation: source uniqueness, join coverage, row-count reconciliation; browser rendering at desktop and narrow widths; date, assignee, stage and month filters; All/reset; table flags; empty selections; and runtime errors. Browser checks are reproducible with `python check_dashboard.py` while the local server is running (requires the installed Playwright package and Edge).
