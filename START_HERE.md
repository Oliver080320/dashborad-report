# Sales Queue — Handover

Read this first. It explains what's in the folder, which code is whose, and how to get the dashboard running.

---

## What's in this folder

| Folder | Whose | What it is | Look at it? |
|---|---|---|---|
| **`suyash_dashboard/`** | **Suyash** | Python/Streamlit dashboard that rebuilds the sales-queue monitoring dashboard from the CSV data | **Start here** |
| `data/` | Dev team export | 7 CSV tables from the CRM database `dev2yourbestwayh_v5` | Used by the dashboard, don't edit |
| `suyash_analysis/` | **Suyash** | `DATA_ANALYSIS_REPORT.md`, a written analysis of the same data | Read second |
| `daniel_queue_system_reference/` | **Daniel** | The original live queue system (PHP), its README and user manual | **Reference only**, don't run or edit |

**Rule of thumb:** if you're changing something, it's in `suyash_dashboard/`. Only open Daniel's code to check how the live system calculates a number.

---

## 1. Run the dashboard (about 5 minutes)

**You need:** Python **3.11 or newer** ([python.org](https://www.python.org/downloads/)). Tick "Add Python to PATH" when you install it.

**Windows, easiest way:** open `suyash_dashboard/` and double-click **`START_DASHBOARD.bat`**. The first run installs the packages, then your browser opens at http://localhost:8501.

**Any OS, from a terminal:**
```bash
cd suyash_dashboard
python -m pip install -r requirements.txt
python run.py
```
Then open http://localhost:8501. Press `Ctrl+C` in the terminal to stop it.

> Keep the folder layout as it is. The dashboard finds its data by looking for `data/` next to `suyash_dashboard/`.

---

## 2. Using the dashboard

- **Treat as today** (sidebar) picks the date the dashboard pretends it is. It defaults to **18 Aug 2026**; see "Known data issue" below.
- **Callers** filters to Karthik, Harsh, Hemant and Arun, in any combination.
- **Per person / Total** switches between one line per caller and one combined line.
- **Tabs** match the original dashboard: Team activity · Workload & capacity · Account intelligence · Quote pipeline · By person · **Data notes**.
- **Quote timeline** (the search box at the top) takes `TDU00456` or just `456`, and shows every queue appearance, call and stage change for that quote.
- **Data notes** tab: read it. It lists every approximation.

---

## 3. Suyash's code — `suyash_dashboard/`

| File | What it does |
|---|---|
| `metrics.py` | **All the calculations.** One function per dashboard panel. Each docstring names the PHP function and line in Daniel's code that it copies (e.g. `MB:264-320` = `monitoring_builder.php` lines 264–320). |
| `app.py` | Page layout, filters, tabs and charts (Streamlit + Altair). No business logic. |
| `run.py` | Launcher. Starts Streamlit on port 8501, or on `$PORT` if that's set. |
| `requirements.txt` | Exact package versions it was tested with. |
| `START_DASHBOARD.bat` | Windows double-click launcher. |

**To change a number:** edit the function in `metrics.py`. **To change how it looks:** edit `app.py`.

Abbreviations used in the code comments:
- `MB` = `daniel_queue_system_reference/tdu_queue/monitoring_system/monitoring_builder.php`
- `QB` = `.../tdu_queue/queues/queue_builder.php`
- `MV` = `.../tdu_queue/views/monitoring_view.php`

---

## 4. Daniel's code — `daniel_queue_system_reference/` (reference only)

This is the **live** sales-queue system running inside the vtiger CRM. It's here so you can check how things are meant to be calculated. **It won't run on its own**: it needs the CRM, a live MySQL database, the CRM's `dbconn.php` login file (not included, on purpose) and an admin login.

Where to look:

| You want to know… | Look in |
|---|---|
| How each dashboard panel is calculated | `tdu_queue/monitoring_system/monitoring_builder.php` |
| How the daily call queue is built, and carry-over | `tdu_queue/queues/queue_builder.php`, `tdu_queue/cron_daily_runs.php` |
| Every setting and threshold (capacity, % splits, day limits) | `tdu_queue/config.php` |
| Database tables the system added | `tdu_queue/migrations/*.sql` |
| How callers use it | `README.md`, `user_manual.php` |

Note: in `config.php`, `MONITORING_MAINTENANCE_MODE = true`, so the live dashboard is currently switched off.

---

## 5. The data — `data/`

Exported from database `dev2yourbestwayh_v5`.

| File | Rows | Contents |
|---|---:|---|
| `vtiger_quotes.csv` | 9,973 | One row per quote (stage, created, country, pax, account) |
| `vtiger_quotescf.csv` | 9,973 | Custom fields; `cf_1162` = **trip date**. Other `cf_*` codes are unmapped. |
| `vtiger_quotes_followup.csv` | 25,901 | Calls (`call_info`) and scheduled follow-ups |
| `vtiger_quote_stage_track.csv` | 221,070 | Audit log of every stage change |
| `vtiger_payment_history.csv` | 2,339 | Payments |
| `vtiger_users.csv` | 77 | CRM users. **The password column was removed on purpose.** |
| `daily_runs.csv` | 11,822 | The queue system's log of which quote was shown to which caller each day |

---

## 6. Known issues — read before trusting any number

1. **Call logging stops after 20 Aug 2026.** Up to 18 Aug the callers logged 40–66 calls a day. After that the logs almost stop, but the queue (`daily_runs`) keeps running to 31 Aug. That's why the dashboard defaults to 18 Aug. **Open question for the dev team:** was the export cut short, or did callers stop logging calls in this system?
2. **Quote owner is approximated.** The real owner is in `vtiger_quotes_info`, which wasn't exported. The dashboard uses the caller the queue last showed the quote to.
3. **Organisation names are missing.** Accounts show as `Account #<id>`.
4. **Four panels can't be built** from this export: Channel mix, Rejection reasons, the quote location map, and the By-person region/priority donut. They appear as grey "not available" cards.
5. **Nothing has been compared with the live dashboard yet**, because it's switched off.

To fill the gaps, ask the dev team for these tables: `vtiger_quotes_info`, `tdu_organisation`, `tdu_quote_closure_feedback`, `tdu_quotes_followup_ext`, `vtiger_groups`, plus a fresh `vtiger_quotes_followup` export that runs past 20 Aug.

---

## 7. Suggested first tasks

1. Get the dashboard running, then click through every tab.
2. Read `suyash_analysis/DATA_ANALYSIS_REPORT.md`.
3. Check one panel against Daniel's PHP (start with **Calls logged**: `calls_by_day()` in `metrics.py` vs `MB:219-247`).
4. Chase the missing tables listed in section 6.
