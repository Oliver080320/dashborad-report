"""Data layer for the Sales Queue monitoring dashboard rebuild.

Every metric mirrors the PHP dashboard (tdu_queue/monitoring_system/monitoring_builder.php and
tdu_queue/queues/queue_builder.php), recomputed from the CSV exports in ../data.

The export is missing some tables the PHP reads. The stand-ins used:
  - Quote owner (vtiger_quotes_info.assigned_to_sales_agent) -> user_name on the quote's latest
    daily_runs row, else vtiger_quotes.created_by when it is one of the four callers.
  - Region (vtiger_quotes_info.assigned_to_region) -> "quote ever appeared in daily_runs", which
    only happens for India-region quotes.
  - Closure feedback (tdu_quote_closure_feedback) -> a "Change Stage to Rejected" event.
  - Organisation names (tdu_organisation) -> "Account #<accountid>".
"""
from __future__ import annotations

import math
from pathlib import Path

import pandas as pd

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
# Call logging in the export stops after 20 Aug 2026 while daily_runs runs to 31 Aug, so default
# to the last day with normal call activity.
DEFAULT_TODAY = pd.Timestamp("2026-08-18")

CALLERS = {  # login -> full name, in the dashboard's fixed order
    "karthik": "Karthik Suriyan",
    "HarshP": "Harsh Purswani",
    "hemant": "Hemant Dongrani",
    "ArunP": "Arun Prabhakaran",
}
FULLNAME_TO_LOGIN = {v: k for k, v in CALLERS.items()}
LOWER_TO_LOGIN = {k.lower(): k for k in CALLERS}

FOLLOW_BUCKETS = ["sp1", "sp2", "sp3", "sp4", "carryover"]
ACCEPTED_STAGES = {s.lower() for s in [
    "Accepted", "PRE QA - pending", "PRE QA - completed", "Payment Received - Release Vouchers",
    "Final QA", "Delivered", "On Ground", "Accounts - Reconciliation", "Completed (Accounts)",
]}
# The CRM stores this stage under both the misspelling "Confrmation" and the correct spelling
# "Confirmation" — the PHP matches only the misspelling, so 20 correctly-spelled rows in the export
# are missed. Both spellings are matched here.
REJECTED_STAGES = {s.lower() for s in [
    "Rejected", "Rejected After Confrmation QA pending", "Rejected After Confrmation QA completed",
    "Rejected After Confirmation QA pending", "Rejected After Confirmation QA completed",
]}
LIVE_EXCLUDED = REJECTED_STAGES | {"auto rejected", "lead"}
OPEN_STAGES = {"created", "requote"}
REACHED = {"interested", "next_call", "inbound_call"}
NOT_REACHED = {"no_answer_email"}
OUTCOMES = ["next_call", "no_answer_email", "interested", "inbound_call"]

# config.php
CARRYOVER_RED_DAYS = 3
DAILY_CAPACITY = 70
INDIA_FOLLOWUP_PCT = 0.60
N_INDIA_REGIONS = 8
FOLLOWUP_CREATED_DAYS = 60
FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS = 90
GROUPS_PAX_THRESHOLD = 40
TDU_INTERNAL_ORG_ID = 91445

DAY = pd.Timedelta(days=1)


# ---------------------------------------------------------------------------------------------
# Loading
# ---------------------------------------------------------------------------------------------

def load(data_dir: Path = DATA_DIR) -> dict:
    d = Path(data_dir)

    q = pd.read_csv(d / "vtiger_quotes.csv", low_memory=False, usecols=[
        "quoteid", "quote_no", "quotestage", "created_at", "created_by", "country", "accountid",
        "adults", "children", "infants", "deleted",
    ])
    cf = pd.read_csv(d / "vtiger_quotescf.csv", low_memory=False, usecols=["quoteid", "cf_1162"])
    q = q.merge(cf, on="quoteid", how="left")
    q["created_at"] = pd.to_datetime(q["created_at"], errors="coerce")
    q["trip"] = pd.to_datetime(q["cf_1162"], errors="coerce")
    q["stage"] = q["quotestage"].fillna("").str.strip()
    q["stage_l"] = q["stage"].str.lower()
    q["quote_no"] = q["quote_no"].fillna("").astype(str)
    q["is_group"] = q["quote_no"].str.upper().str.endswith("G")
    q["pax"] = q[["adults", "children", "infants"]].fillna(0).sum(axis=1).astype(int)
    q["country"] = q["country"].fillna("Unknown").str.strip().str.title()

    fu = pd.read_csv(d / "vtiger_quotes_followup.csv", low_memory=False, usecols=[
        "auto_id", "quoteid", "followup_type", "next_follow_up_date", "description", "outcome",
        "followup", "calltime", "created_at", "created_by",
    ])
    for c in ["calltime", "created_at", "next_follow_up_date"]:
        fu[c] = pd.to_datetime(fu[c], errors="coerce")
    fu["call_date"] = fu["calltime"].dt.normalize()
    fu["login"] = fu["created_by"].map(FULLNAME_TO_LOGIN)
    calls = fu[fu["followup_type"] == "call_info"]

    st = pd.read_csv(d / "vtiger_quote_stage_track.csv", low_memory=False)
    st["created_at"] = pd.to_datetime(st["created_at"], errors="coerce")
    prefix = "Change Stage to "
    ch = st[st["stage"].fillna("").str.startswith(prefix)].copy()
    ch["ev"] = ch["stage"].str.slice(len(prefix)).str.strip().str.lower()
    ch["ev_date"] = ch["created_at"].dt.normalize()

    dr = pd.read_csv(d / "daily_runs.csv")
    dr = dr[dr["item_type"] == "quote"].copy()
    dr["run_date"] = pd.to_datetime(dr["run_date"])

    # Owner approximation: latest daily_runs user_name (the same rule QB:431 uses for carry-over
    # ownership), else created_by when it is one of the four callers.
    latest = dr.sort_values(["run_date", "id"]).groupby("item_id")["user_name"].last()
    by_creator = q.set_index("quoteid")["created_by"].fillna("").str.lower().map(LOWER_TO_LOGIN).dropna()
    owner = latest.combine_first(by_creator)
    q["owner"] = q["quoteid"].map(owner)
    q["in_queue"] = q["quoteid"].isin(set(dr["item_id"]))

    # next_call_date: latest unchecked schedule_follow_up row per quote (QB, sched subquery).
    sched = fu[(fu["followup_type"] == "schedule_follow_up") & (fu["followup"].fillna("") != "checked")]
    ncd = sched.sort_values("auto_id").groupby("quoteid")["next_follow_up_date"].last().dt.normalize()
    q["next_call_date"] = q["quoteid"].map(ncd)

    # "Worked" (quoteid, date) pairs — fetch_worked_set(), MB:24-44.
    w_calls = fu.loc[fu["outcome"].notna() & fu["call_date"].notna(), ["quoteid", "call_date"]]
    w_calls = w_calls.rename(columns={"call_date": "date"})
    w_stage = ch.loc[ch["ev"].isin(["accepted", "requote", "rejected"]), ["quoteid", "ev_date"]]
    w_stage = w_stage.rename(columns={"ev_date": "date"})
    worked = pd.concat([w_calls, w_stage]).drop_duplicates()
    worked["worked"] = True

    return {"q": q, "qi": q.set_index("quoteid"), "fu": fu, "calls": calls, "st": st, "ch": ch,
            "dr": dr, "worked": worked}


# ---------------------------------------------------------------------------------------------
# Dates
# ---------------------------------------------------------------------------------------------

def trend_days(today: pd.Timestamp, n: int = 14) -> list[pd.Timestamp]:
    """Last n calendar days ending today, Sundays dropped (core.js:114-121)."""
    return [d for d in pd.date_range(today - (n - 1) * DAY, today) if d.weekday() != 6]


def month_days(today: pd.Timestamp) -> list[pd.Timestamp]:
    return [d for d in pd.date_range(today.replace(day=1), today) if d.weekday() != 6]


def last_active_call_day(D: dict, min_calls: int = 10) -> pd.Timestamp:
    """Latest day on which the four callers logged at least min_calls calls."""
    c = D["calls"]
    per_day = c[c["login"].notna()].groupby("call_date").size()
    return per_day[per_day >= min_calls].index.max()


def last_working_day(D: dict, today: pd.Timestamp) -> pd.Timestamp | None:
    keys = set(D["calls"].loc[D["calls"]["login"].notna(), "call_date"].dropna()) | set(D["dr"]["run_date"])
    past = [d for d in keys if d < today and d.weekday() != 6]
    return max(past) if past else None


# ---------------------------------------------------------------------------------------------
# Daily per-caller series (long format: date, user, counts...)
# ---------------------------------------------------------------------------------------------

def calls_by_day(D, start, end) -> pd.DataFrame:
    c = D["calls"]
    c = c[c["login"].notna() & c["call_date"].between(start, end)]
    return (c.groupby(["call_date", "login"]).size().rename("calls").reset_index()
             .rename(columns={"call_date": "date", "login": "user"}))


def surfaced_worked_by_day(D, start, end) -> pd.DataFrame:
    dr = D["dr"]
    s = dr[dr["bucket"].isin(FOLLOW_BUCKETS) & dr["run_date"].between(start, end)]
    s = s[["run_date", "user_name", "item_id"]].drop_duplicates()
    s = s.merge(D["worked"], left_on=["item_id", "run_date"], right_on=["quoteid", "date"], how="left")
    s["worked"] = s["worked"].eq(True)
    return (s.groupby(["run_date", "user_name"])
             .agg(surfaced=("item_id", "size"), worked=("worked", "sum")).reset_index()
             .rename(columns={"run_date": "date", "user_name": "user"}))


def contact_by_day(D, start, end) -> pd.DataFrame:
    c = D["calls"]
    c = c[c["login"].notna() & c["call_date"].between(start, end)].copy()
    c["reached"] = c["outcome"].isin(REACHED)
    c["not_reached"] = c["outcome"].isin(NOT_REACHED)
    return (c.groupby(["call_date", "login"])[["reached", "not_reached"]].sum().reset_index()
             .rename(columns={"call_date": "date", "login": "user"}))


def backlog_by_day(D, start, end) -> pd.DataFrame:
    dr = D["dr"]
    b = dr[(dr["bucket"] == "carryover") & dr["run_date"].between(start, end)]
    return (b.groupby(["run_date", "user_name"]).size().rename("carryover").reset_index()
             .rename(columns={"run_date": "date", "user_name": "user"}))


def queue_composition(D, start, end) -> pd.DataFrame:
    dr = D["dr"]
    b = dr[dr["bucket"].isin(FOLLOW_BUCKETS) & dr["run_date"].between(start, end)]
    return (b.groupby(["run_date", "user_name", "bucket"]).size().rename("n").reset_index()
             .rename(columns={"run_date": "date", "user_name": "user"}))


def outcome_mix(D, start, end) -> pd.DataFrame:
    c = D["calls"]
    c = c[c["login"].notna() & c["call_date"].between(start, end) & c["outcome"].isin(OUTCOMES)]
    return c.groupby(["login", "outcome"]).size().rename("n").reset_index().rename(columns={"login": "user"})


def calls_by_hour(D, today) -> tuple[pd.DataFrame, pd.DataFrame]:
    """MB:967-1003. calltime is Melbourne server time; callers work in India, so convert per row
    (DST-aware) before bucketing by hour. Returns (hour/user/calls, user/day active-day pairs)."""
    c = D["calls"]
    c = c[c["login"].notna() & (c["calltime"] > today - 90 * DAY) & (c["calltime"] < today + DAY)]
    ist = (c["calltime"].dt.tz_localize("Australia/Melbourne", ambiguous="NaT", nonexistent="shift_forward")
           .dt.tz_convert("Asia/Kolkata"))
    c = c.assign(hour=ist.dt.hour, day=ist.dt.tz_localize(None).dt.normalize()).dropna(subset=["hour"])
    active = c[["login", "day"]].drop_duplicates().rename(columns={"login": "user"})
    c = c[c["hour"].between(9, 18)]
    hours = c.groupby(["hour", "login"]).size().rename("calls").reset_index().rename(columns={"login": "user"})
    hours["hour"] = hours["hour"].astype(int)
    return hours, active


# ---------------------------------------------------------------------------------------------
# Carry-over backlog (load_carryover_quotes + resolve_first_pending, QB:345-603)
# ---------------------------------------------------------------------------------------------

def carryover_now(D, today, users) -> pd.DataFrame:
    dr, fu, qi = D["dr"], D["fu"], D["qi"]
    prior = dr[(dr["run_date"] < today) & dr["bucket"].isin(FOLLOW_BUCKETS) & dr["user_name"].isin(users)]
    prior = prior.sort_values(["run_date", "id"])
    if prior.empty:
        return pd.DataFrame(columns=["quoteid", "owner", "first_pending", "next_call_date", "days_behind", "worked_today"])
    owner = prior.groupby("item_id")["user_name"].last()

    # Streak resets at the last logged call before today that has a later schedule_follow_up.
    sched_max = fu[fu["followup_type"] == "schedule_follow_up"].groupby("quoteid")["auto_id"].max()
    c = fu[fu["outcome"].notna() & (fu["calltime"] < today)]
    c = c[c["auto_id"] < c["quoteid"].map(sched_max).fillna(-1)]
    last_worked = c.groupby("quoteid")["calltime"].max().dt.normalize()

    p = prior[["item_id", "run_date"]].copy()
    p["lw"] = p["item_id"].map(last_worked)
    p = p[p["lw"].isna() | (p["run_date"] > p["lw"])]
    first_pending = p.groupby("item_id")["run_date"].min()

    # Re-apply the cohort: not deleted, stage Created/Requote, trip still ahead.
    ids = [i for i in first_pending.index if i in qi.index]
    cq = qi.loc[ids]
    cq = cq[(cq["deleted"] == 0) & cq["stage_l"].isin(OPEN_STAGES) & (cq["trip"] > today)]

    out = pd.DataFrame({
        "quoteid": cq.index,
        "quote_no": cq["quote_no"].values,
        "owner": owner.reindex(cq.index).values,
        "first_pending": first_pending.reindex(cq.index).values,
        "next_call_date": cq["next_call_date"].values,
        "trip": cq["trip"].values,
    })
    anchor = out["first_pending"].where(
        out["next_call_date"].isna() | (out["next_call_date"] >= out["first_pending"]), out["next_call_date"])
    out["days_behind"] = ((today - anchor).dt.days).clip(lower=0).astype(int)
    wt = D["worked"][D["worked"]["date"] == today]["quoteid"]
    out["worked_today"] = out["quoteid"].isin(set(wt))
    return out.sort_values("days_behind", ascending=False)


# ---------------------------------------------------------------------------------------------
# Stage events: accepted / lifecycle / win rate / cycle time
# ---------------------------------------------------------------------------------------------

def _events(D, start, end, kinds) -> pd.DataFrame:
    ch = D["ch"]
    e = ch[ch["ev"].isin(kinds) & ch["ev_date"].between(start, end)][["quoteid", "ev", "ev_date"]]
    e = e.merge(D["q"][["quoteid", "stage_l", "deleted", "accountid", "owner", "is_group", "created_at",
                        "quote_no", "trip", "pax", "stage"]], on="quoteid")
    e = e[e["deleted"] == 0]
    # An Accepted event counts only while the quote is still in an Accepted-family stage.
    return e[(e["ev"] != "accepted") | e["stage_l"].isin(ACCEPTED_STAGES)]


def accepted_month(D, today) -> pd.DataFrame:
    """MB:264-320. First Accepted date per quote this month, credited to the current owner."""
    e = _events(D, today.replace(day=1), today, {"accepted"})
    e = e[e["accountid"] != TDU_INTERNAL_ORG_ID]
    e = e.sort_values("ev_date").groupby("quoteid").first().reset_index()
    e["label"] = e["owner"].where(e["owner"].isin(CALLERS.keys()))
    e.loc[e["label"].isna(), "label"] = e["is_group"].map({True: "Unassigned (Groups)", False: "Unassigned (FIT)"})
    return e


def lifecycle_month(D, today) -> pd.DataFrame:
    """MB:406-443. Stage events this month per owner (not de-duplicated)."""
    e = _events(D, today.replace(day=1), today, {"accepted", "requote"} | REJECTED_STAGES)
    e = e[e["owner"].isin(CALLERS.keys())]
    e["kind"] = e["ev"].map(lambda s: "accepted" if s == "accepted" else ("requote" if s == "requote" else "rejected"))
    return e.groupby(["owner", "kind"]).size().rename("n").reset_index()


def win_rate_month(D, today) -> pd.DataFrame:
    """MB:450-479. Business-wide accepted / (accepted + rejected) per day."""
    e = _events(D, today.replace(day=1), today, {"accepted"} | REJECTED_STAGES)
    e["acc"] = e["ev"] == "accepted"
    g = e.groupby("ev_date").agg(accepted=("acc", "sum"), total=("acc", "size")).reset_index()
    g["rejected"] = g["total"] - g["accepted"]
    return g.rename(columns={"ev_date": "date"})[["date", "accepted", "rejected"]]


def cycle_time_month(D, today) -> pd.DataFrame:
    """MB:486-525. Days from quote creation to Accepted event, per owner."""
    e = _events(D, today.replace(day=1), today, {"accepted"})
    e = e[e["owner"].isin(CALLERS.keys())]
    e["days"] = (e["ev_date"] - e["created_at"].dt.normalize()).dt.days
    return e.groupby("owner").agg(sum_days=("days", "sum"), n=("days", "size")).reset_index()


# ---------------------------------------------------------------------------------------------
# Workload & capacity
# ---------------------------------------------------------------------------------------------

def quotes_owned_now(D) -> pd.DataFrame:
    q = D["q"]
    live = q[(q["deleted"] == 0) & q["stage_l"].isin(OPEN_STAGES) & q["owner"].isin(CALLERS.keys())]
    return live.groupby("owner").size().reindex(list(CALLERS), fill_value=0).rename("n").reset_index()


def coverage(D, days) -> pd.DataFrame:
    """MB:701-746. Surfaced ÷ qualifying. Qualifying uses today's stages for every past day."""
    q, dr = D["q"], D["dr"]
    live = q[(q["deleted"] == 0) & q["stage_l"].isin(OPEN_STAGES) & q["trip"].notna() & q["in_queue"]]
    fb = dr[dr["bucket"].isin(FOLLOW_BUCKETS)]
    rows = []
    for d in days:
        surfaced = fb.loc[fb["run_date"] == d, "item_id"].nunique()
        qualifying = int((live["trip"] > d).sum())
        rows.append({"date": d, "surfaced": surfaced, "qualifying": qualifying})
    return pd.DataFrame(rows)


def capacity_vs_demand(D, days, today) -> tuple[pd.DataFrame, int]:
    """MB:756-858. Reclassifies the live cohort into SP1-SP4 as of each day."""
    q, calls = D["q"], D["calls"]
    coh = q[(q["deleted"] == 0) & q["stage_l"].isin(OPEN_STAGES) & q["in_queue"]
            & (q["trip"] > today - 14 * DAY)]
    with_outcome = calls[calls["outcome"].notna()].sort_values("calltime")
    rows = []
    for d in days:
        c = coh[coh["trip"] > d]
        c = c[~(c["next_call_date"] > d)]  # snoozed until later
        last_out = with_outcome[with_outcome["calltime"] < d].groupby("quoteid")["outcome"].last()
        called = set(calls.loc[calls["calltime"] < d, "quoteid"])
        lo = c["quoteid"].map(last_out)
        sp1 = (c["next_call_date"] <= d) | (lo == "interested")
        sp2 = ~sp1 & ~c["quoteid"].isin(called) & (c["created_at"] >= d - FOLLOWUP_CREATED_DAYS * DAY)
        sp3 = ~sp1 & ~sp2 & ((c["trip"] - d).dt.days <= FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS)
        sp4 = ~sp1 & ~sp2 & ~sp3
        rows.append({"date": d, "sp1": int(sp1.sum()), "sp2": int(sp2.sum()),
                     "sp3": int(sp3.sum()), "sp4": int(sp4.sum())})
    budget = N_INDIA_REGIONS * math.floor(DAILY_CAPACITY * INDIA_FOLLOWUP_PCT)
    return pd.DataFrame(rows), budget


# ---------------------------------------------------------------------------------------------
# Account intelligence
# ---------------------------------------------------------------------------------------------

def unresponsive_accounts(D, today) -> pd.DataFrame:
    """MB:866-937. Accounts with ≥3 contact days in 90d and ≥80% of those days unanswered."""
    calls, q = D["calls"], D["q"]
    c = calls[calls["outcome"].notna() & (calls["calltime"] > today - 90 * DAY) & (calls["calltime"] < today + DAY)]
    c = c.merge(q.loc[q["stage_l"].isin(OPEN_STAGES) & q["accountid"].notna(), ["quoteid", "accountid"]], on="quoteid")
    c["reached"] = c["outcome"].isin(REACHED)
    days = c.groupby(["accountid", "call_date"])["reached"].any().reset_index()
    g = days.groupby("accountid").agg(days=("reached", "size"), reached=("reached", "sum")).reset_index()
    g["rate"] = (g["days"] - g["reached"]) / g["days"] * 100
    g = g[(g["days"] >= 3) & (g["rate"] >= 80)].sort_values(["rate", "days"], ascending=False).head(10)
    g["account"] = "Account #" + g["accountid"].astype(int).astype(str)
    return g


def account_insights(D, mode: str) -> pd.DataFrame:
    """MB:1554-1597. Top 10 accounts by volume or by rejection rate (≥3 resolved)."""
    q = D["q"]
    q = q[(q["deleted"] == 0) & q["accountid"].notna()].copy()
    q["kind"] = "other"
    q.loc[q["stage_l"].isin(ACCEPTED_STAGES), "kind"] = "Accepted"
    q.loc[q["stage_l"].isin(REJECTED_STAGES), "kind"] = "Rejected"
    q.loc[q["stage_l"].isin(OPEN_STAGES), "kind"] = "In progress"
    q = q[q["kind"] != "other"]
    g = q.pivot_table(index="accountid", columns="kind", values="quoteid", aggfunc="count", fill_value=0)
    for k in ["Accepted", "Rejected", "In progress"]:
        if k not in g:
            g[k] = 0
    g["total"] = g[["Accepted", "Rejected", "In progress"]].sum(axis=1)
    g["resolved"] = g["Accepted"] + g["Rejected"]
    if mode == "rate":
        g = g[g["resolved"] >= 3]
        g["rej_rate"] = g["Rejected"] / g["resolved"]
        g = g.sort_values(["rej_rate", "resolved"], ascending=False)
    else:
        g = g.sort_values("total", ascending=False)
    g = g.head(10).reset_index()
    g["account"] = "Account #" + g["accountid"].astype(int).astype(str)
    return g


# ---------------------------------------------------------------------------------------------
# Quote pipeline
# ---------------------------------------------------------------------------------------------

def live_basket(D) -> pd.DataFrame:
    q = D["q"]
    return q[(q["deleted"] == 0) & ~q["stage_l"].isin(LIVE_EXCLUDED)]


def scope(D, which: str) -> pd.DataFrame:
    live = live_basket(D)
    if which == "open":
        return live[live["stage_l"].isin(OPEN_STAGES)]
    if which == "accepted":
        return live[live["stage_l"].isin(ACCEPTED_STAGES)]
    return live


def composition(df) -> pd.DataFrame:
    kind = pd.Series("FIT", index=df.index)
    kind[df["is_group"] & (df["pax"] > GROUPS_PAX_THRESHOLD)] = f"Groups >{GROUPS_PAX_THRESHOLD} pax"
    kind[df["is_group"] & (df["pax"] <= GROUPS_PAX_THRESHOLD)] = f"Groups ≤{GROUPS_PAX_THRESHOLD} pax"
    return kind.value_counts().rename_axis("kind").rename("n").reset_index()


def travel_horizon(df, today) -> pd.DataFrame:
    df = df[df["trip"] > today]
    start = today.replace(day=1)
    months = [start + pd.DateOffset(months=i) for i in range(12)]
    rows = []
    for m in months:
        n = int(((df["trip"] >= m) & (df["trip"] < m + pd.DateOffset(months=1))).sum())
        rows.append({"label": m.strftime("%b %Y"), "n": n})
    rows.append({"label": "13+ months", "n": int((df["trip"] >= start + pd.DateOffset(months=12)).sum())})
    return pd.DataFrame(rows)


def pax_buckets(df) -> tuple[pd.DataFrame, pd.DataFrame]:
    df = df[df["pax"] > 0]
    fit = df[~df["is_group"]]["pax"]
    grp = df[df["is_group"]]["pax"]
    fit_b = pd.cut(fit, [0, 1, 2, 4, 6, 10, 10_000], labels=["1", "2", "3-4", "5-6", "7-10", "11+"])
    grp_b = pd.cut(grp, [0, 25, 50, 100, 200, 100_000], labels=["1-25", "26-50", "51-100", "101-200", "200+"])
    f = fit_b.value_counts(sort=False).rename_axis("bucket").rename("n").reset_index()
    g = grp_b.value_counts(sort=False).rename_axis("bucket").rename("n").reset_index()
    f.attrs = {"avg": fit.mean() if len(fit) else 0, "count": len(fit)}
    g.attrs = {"avg": grp.mean() if len(grp) else 0, "count": len(grp)}
    return f, g


# ---------------------------------------------------------------------------------------------
# By person
# ---------------------------------------------------------------------------------------------

def person_summary(D, login, start, end) -> dict:
    """MB:1082-1198."""
    q = D["q"]
    mine = q[(q["owner"] == login) & (q["deleted"] == 0)]
    made = mine[mine["created_at"].between(start, end + DAY)]

    ev = _events(D, start, end, {"accepted"} | REJECTED_STAGES)
    ev = ev[ev["owner"] == login]
    acc = ev[ev["ev"] == "accepted"].sort_values("ev_date").groupby("quoteid").first()
    rej = ev[(ev["ev"] != "accepted") & ev["stage_l"].isin(REJECTED_STAGES)].groupby("quoteid").last()

    calls = D["calls"]
    inbound = calls[(calls["login"] == login) & (calls["outcome"] == "inbound_call")
                    & calls["calltime"].between(start, end + DAY)]

    first_call = calls.groupby("quoteid")["calltime"].min()
    fr = (made["quoteid"].map(first_call) - made["created_at"]).dt.total_seconds() / 86400
    cyc = (acc["ev_date"] - acc["created_at"].dt.normalize()).dt.days

    resolved = len(acc) + len(rej)
    return {
        "total": len(made), "companies": made["accountid"].nunique(),
        "created": int((made["stage_l"] == "created").sum()), "requote": int((made["stage_l"] == "requote").sum()),
        "accepted": len(acc), "rejected": len(rej), "inbound": len(inbound),
        "conversion": (len(acc) / resolved * 100) if resolved else None,
        "first_response": fr.dropna().mean() if fr.notna().any() else None,
        "cycle": cyc.mean() if len(cyc) else None,
        "made": made, "acc_ids": list(acc.index), "rej_ids": list(rej.index), "inbound_rows": inbound,
    }


def live_book(D, login, today) -> pd.DataFrame:
    """MB:1479-1537. The caller's Created/Requote quotes."""
    q, calls = D["q"], D["calls"]
    b = q[(q["owner"] == login) & (q["deleted"] == 0) & q["stage_l"].isin(OPEN_STAGES)].copy()
    cc = calls[calls["calltime"] < today + DAY]
    b["calls"] = b["quoteid"].map(cc.groupby("quoteid").size()).fillna(0).astype(int)
    b["last_call"] = b["quoteid"].map(cc.groupby("quoteid")["calltime"].max())
    b["days_since_contact"] = (today - b["last_call"].dt.normalize()).dt.days
    return b[["quote_no", "stage", "trip", "created_at", "calls", "last_call", "next_call_date",
              "days_since_contact", "pax", "is_group", "quoteid"]].sort_values("trip")


def upcoming_buckets(book, today) -> pd.DataFrame:
    """by-person.js:810-834."""
    labels = []
    for ncd in book["next_call_date"]:
        if pd.isna(ncd):
            labels.append("Not scheduled")
            continue
        diff = (ncd - today).days
        if diff < 0:
            labels.append("Overdue")
        elif diff == 0:
            labels.append("Today")
        elif diff == 1:
            labels.append("Tomorrow")
        elif diff <= 6:
            labels.append((today + diff * DAY).strftime("%a %d"))
        elif diff <= 13:
            labels.append("Next week")
        else:
            labels.append("2+ weeks")
    order = (["Overdue", "Today", "Tomorrow"] + [(today + i * DAY).strftime("%a %d") for i in range(2, 7)]
             + ["Next week", "2+ weeks", "Not scheduled"])
    s = pd.Series(labels, dtype="object").value_counts().reindex(order, fill_value=0)
    return s.rename_axis("bucket").rename("n").reset_index()


# ---------------------------------------------------------------------------------------------
# Quote timeline + exports
# ---------------------------------------------------------------------------------------------

def find_quote(D, text: str):
    t = (text or "").strip().upper()
    if not t:
        return None
    if t.isdigit():
        t = "TDU" + t.zfill(5)
    elif not t.startswith("TDU"):
        t = "TDU" + t
    q = D["q"]
    hit = q[q["quote_no"].str.upper().isin([t, t + "G"])]
    return None if hit.empty else hit.iloc[0]


def timeline(D, quoteid) -> dict:
    dr, calls, st = D["dr"], D["calls"], D["st"]
    appearances = dr[dr["item_id"] == quoteid][["run_date", "user_name", "bucket", "position"]].sort_values("run_date")
    c = calls[calls["quoteid"] == quoteid][["calltime", "created_by", "outcome", "description"]].sort_values("calltime")
    s = st[st["quoteid"] == quoteid][["created_at", "user_name", "stage"]].sort_values("created_at")
    ev = s["stage"].fillna("").str.lower()
    reversed_ = ev.str.contains("change stage to accepted").any() and ev.str.contains("after confrmation").any()
    return {"appearances": appearances, "calls": c, "stages": s, "reversed": bool(reversed_)}


def export_next_3_months(D, today) -> pd.DataFrame:
    """MB:1879. Trips from the 1st of this month to before the 1st of month +3."""
    q = D["q"]
    start = today.replace(day=1)
    end = start + pd.DateOffset(months=3)
    x = q[(q["deleted"] == 0) & (q["trip"] >= start) & (q["trip"] < end)].copy()

    def bucket(s):
        if s in OPEN_STAGES:
            return s.title()
        if s in ACCEPTED_STAGES:
            return "Accepted"
        if s in REJECTED_STAGES:
            return "Rejected"
        return "Other"
    x["stage_bucket"] = x["stage_l"].map(bucket)
    x["owner_name"] = x["owner"].map(CALLERS)
    x["type"] = x["is_group"].map({True: "Groups", False: "FIT"})
    return x[["quote_no", "type", "owner_name", "stage", "stage_bucket", "created_at", "trip", "pax"]]
