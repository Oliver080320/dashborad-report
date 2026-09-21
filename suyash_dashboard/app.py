"""Monitoring dashboard — Sales Queue. A Python rebuild of tdu_queue/monitoring_system, run on
the CSV exports in ../data.  Start with:  python -m streamlit run dashboard/app.py
"""
from __future__ import annotations

import altair as alt
import pandas as pd
import streamlit as st

import metrics as m

st.set_page_config(page_title="Monitoring dashboard · Sales Queue", page_icon="📊", layout="wide")

# Palette copied from assets/js/monitoring/core.js and monitoring.css
CALLER_COLORS = ["#1C93C4", "#F5A623", "#EF6C24", "#DC2626"]
TEAL, ALERT, GOOD, MUTED, INK = "#0F766E", "#C0392B", "#3FA34D", "#64748B", "#334155"
OUTCOME_COLORS = {"next_call": "#0F766E", "no_answer_email": "#C28A3E", "interested": "#3FA34D", "inbound_call": "#6B5B95"}
BUCKET_COLORS = {"sp1": "#1C93C4", "sp2": "#F5A623", "sp3": "#EF6C24", "sp4": "#4A7B8C", "carryover": "#92400e"}
STAGE_COLORS = {"accepted": GOOD, "rejected": ALERT, "requote": "#C28A3E"}

st.markdown("""
<style>
  .stApp { background: #F8FAFC; }
  .block-container { max-width: 1800px; padding-top: 1.6rem; }
  div[data-testid="stMetricValue"] { font-weight: 800; }
  .kpi-head { color:#64748B; font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin:.2rem 0 .4rem; }
  .chip { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.78rem; margin:2px 4px 2px 0; }
  .muted { color:#64748B; font-size:.85rem; }
  .kpi-label { color:#334155; font-size:.875rem; }
  .kpi-value { font-size:2.1rem; font-weight:800; line-height:1.25; }
  .heat { display:inline-block; padding:4px 10px; border-radius:6px; color:#fff; font-size:.8rem; margin:2px 6px 2px 0; }
</style>
""", unsafe_allow_html=True)


@st.cache_data(show_spinner="Loading CSV exports…")
def load() -> dict:
    return m.load()


D = load()

# ---------------------------------------------------------------------------------------------
# Sidebar: date, callers filter, per-person / total toggle
# ---------------------------------------------------------------------------------------------
with st.sidebar:
    st.markdown("### Filters")
    today = pd.Timestamp(st.date_input("Treat as today", m.DEFAULT_TODAY.date(),
                                       help="Call logs in the export stop after 20 Aug 2026, so this defaults to 18 Aug, the last normal day."))
    picked = st.multiselect("Callers", list(m.CALLERS.values()), default=list(m.CALLERS.values()))
    users = [m.FULLNAME_TO_LOGIN[n] for n in picked]
    total_mode = st.radio("Series", ["Per person", "Total"], horizontal=True) == "Total"
    st.caption("Data: `Sales Queue/data/*.csv`. See the **Data notes** tab for what is approximated.")

if not users:
    st.warning("Pick at least one caller in the sidebar.")
    st.stop()

DAYS = m.trend_days(today)
START = DAYS[0]
DAY_ORDER = [d.strftime("%d %b") for d in DAYS]


# ---------------------------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------------------------

def series_scale() -> alt.Scale:
    if total_mode:
        return alt.Scale(domain=["Total"], range=[TEAL])
    labels = [m.CALLERS[u] for u in users]
    return alt.Scale(domain=labels, range=[CALLER_COLORS[i % 4] for i in range(len(labels))])


def grid(df: pd.DataFrame, cols: list[str], days=None) -> pd.DataFrame:
    """Fill every (day, selected caller) with zeros, then collapse to one 'Total' series if asked."""
    days = days or DAYS
    df = df[df["user"].isin(users)] if len(df) else df
    idx = pd.MultiIndex.from_product([days, users], names=["date", "user"])
    out = (df.set_index(["date", "user"])[cols] if len(df) else pd.DataFrame(columns=cols, index=idx[:0]))
    out = out.reindex(idx, fill_value=0).reset_index()
    if total_mode:
        out = out.groupby("date", as_index=False)[cols].sum()
        out["user"] = "Total"
    out["Day"] = out["date"].dt.strftime("%d %b")
    out["Caller"] = out["user"].map(lambda u: m.CALLERS.get(u, u))
    return out


def rate(num, den):
    return (num / den * 100).round(1).where(den > 0)


def line(df, y, title, pct=True, order=None):
    return (alt.Chart(df).mark_line(point=True, strokeWidth=2.5).encode(
        x=alt.X("Day:N", sort=order or DAY_ORDER, title=None),
        y=alt.Y(f"{y}:Q", title=title, scale=alt.Scale(domain=[0, 100]) if pct else alt.Undefined),
        color=alt.Color("Caller:N", scale=series_scale(), legend=alt.Legend(orient="bottom", title=None)),
        tooltip=["Caller", "Day", alt.Tooltip(f"{y}:Q", title=title)],
    ).properties(height=280))


def grouped_bars(df, y, title, order=None):
    return (alt.Chart(df).mark_bar().encode(
        x=alt.X("Day:N", sort=order or DAY_ORDER, title=None),
        xOffset=alt.XOffset("Caller:N", sort=[m.CALLERS.get(u, u) for u in users] + ["Total"]),
        y=alt.Y(f"{y}:Q", title=title),
        color=alt.Color("Caller:N", scale=series_scale(), legend=alt.Legend(orient="bottom", title=None)),
        tooltip=["Caller", "Day", alt.Tooltip(f"{y}:Q", title=title)],
    ).properties(height=280))


def card(title: str, subtitle: str = ""):
    box = st.container(border=True)
    box.markdown(f"**{title}**")
    if subtitle:
        box.markdown(f"<div class='muted'>{subtitle}</div>", unsafe_allow_html=True)
    return box


def chip(text, bg="#E2E8F0", fg=INK):
    return f"<span class='chip' style='background:{bg};color:{fg}'>{text}</span>"


def unavailable(box, why: str):
    box.info(f"Not available from this export — {why}", icon="🚫")


def pct_text(n, d):
    return f"{n / d * 100:.1f}%" if d else "—"


def kpi(col, label, value, sub="", color=INK):
    col.markdown(f"<div class='kpi-label'>{label}</div>"
                 f"<div class='kpi-value' style='color:{color}'>{value}</div>"
                 f"<div class='muted'>{sub}</div>", unsafe_allow_html=True)


def caller_names():
    return "all 4 callers" if len(users) == 4 else ", ".join(m.CALLERS[u].split()[0] for u in users)


# ---------------------------------------------------------------------------------------------
# Header + KPI rows
# ---------------------------------------------------------------------------------------------
st.markdown("## Monitoring dashboard · sales queue")
st.markdown(f"<div class='muted'>Rebuilt from CSV exports · today = <b>{today:%a %d %b %Y}</b> · "
            f"{caller_names()}</div>", unsafe_allow_html=True)
quiet_after = m.last_active_call_day(D)
if today > quiet_after:
    st.warning(f"Call logging in this export effectively stops after **{quiet_after:%d %b %Y}**, but the queue "
               f"(daily_runs) keeps running to 31 Aug. Call-based panels will look empty. Set **Treat as today** "
               f"to {quiet_after:%d %b} or earlier to see normal activity.", icon="⚠️")


def kpi_values(day):
    calls = m.calls_by_day(D, day, day)
    calls = int(calls[calls["user"].isin(users)]["calls"].sum())
    sw = m.surfaced_worked_by_day(D, day, day)
    sw = sw[sw["user"].isin(users)]
    ct = m.contact_by_day(D, day, day)
    ct = ct[ct["user"].isin(users)]
    return calls, int(sw["surfaced"].sum()), int(sw["worked"].sum()), int(ct["reached"].sum()), int(ct["not_reached"].sum())


lwd = m.last_working_day(D, today)
if lwd is not None:
    calls, surf, work, reach, nreach = kpi_values(lwd)
    backlog_rows = m.backlog_by_day(D, lwd, lwd)
    backlog_rows = int(backlog_rows[backlog_rows["user"].isin(users)]["carryover"].sum())
    with st.container(border=True):
        st.markdown(f"<div class='kpi-head'>Last working day ({lwd:%d %b})</div>", unsafe_allow_html=True)
        c1, c2, c3, c4 = st.columns(4)
        kpi(c1, "Calls", f"{calls:,}", f"across {caller_names()}")
        kpi(c2, "Queue worked", pct_text(work, surf), f"{work} of {surf} surfaced")
        kpi(c3, "Backlog", f"{backlog_rows:,}", "carry-over rows")
        kpi(c4, "Contact rate", pct_text(reach, reach + nreach), f"{reach} of {reach + nreach} reached")

calls, surf, work, reach, nreach = kpi_values(today)
carry = m.carryover_now(D, today, users)
with st.container(border=True):
    st.markdown("<div class='kpi-head'>Today (in progress)</div>", unsafe_allow_html=True)
    c1, c2, c3, c4 = st.columns(4)
    oldest = int(carry["days_behind"].max()) if len(carry) else 0
    kpi(c1, "Calls today", f"{calls:,}", f"across {caller_names()}")
    kpi(c2, "Queue worked", pct_text(work, surf), f"{work} of {surf} surfaced")
    kpi(c3, "Backlog now", f"{len(carry):,}", f"carry-over quotes · oldest {oldest}d", color=ALERT)
    kpi(c4, "Contact rate", pct_text(reach, reach + nreach), f"{reach} of {reach + nreach} reached")

# Quote timeline search
with st.expander("🔎 Quote timeline — search a quote (e.g. TDU00456 or 456)"):
    qtext = st.text_input("Quote number", key="qsearch", label_visibility="collapsed", placeholder="TDU00456")
    if qtext:
        hit = m.find_quote(D, qtext)
        if hit is None:
            st.warning(f"No quote matches “{qtext}”.")
        else:
            tl = m.timeline(D, int(hit["quoteid"]))
            h1, h2, h3, h4, h5 = st.columns(5)
            h1.metric("Quote", hit["quote_no"])
            h2.metric("Stage", hit["stage"])
            h3.metric("Created", f"{hit['created_at']:%d %b %Y}" if pd.notna(hit["created_at"]) else "—")
            h4.metric("Trip", f"{hit['trip']:%d %b %Y}" if pd.notna(hit["trip"]) else "—")
            h5.metric("Next call", f"{hit['next_call_date']:%d %b %Y}" if pd.notna(hit["next_call_date"]) else "—")
            if tl["reversed"]:
                st.error("Reversed after acceptance — was Accepted, now Rejected After Confirmation.")
            t1, t2, t3 = st.tabs([f"Queue appearances ({len(tl['appearances'])})", f"Calls ({len(tl['calls'])})",
                                  f"Stage changes ({len(tl['stages'])})"])
            t1.dataframe(tl["appearances"], hide_index=True, width="stretch")
            t2.dataframe(tl["calls"], hide_index=True, width="stretch")
            t3.dataframe(tl["stages"], hide_index=True, width="stretch")

tab_team, tab_work, tab_acct, tab_pipe, tab_person, tab_notes = st.tabs(
    ["Team activity", "Workload & capacity", "Account intelligence", "Quote pipeline", "By person", "Data notes"])

# ---------------------------------------------------------------------------------------------
# Team activity
# ---------------------------------------------------------------------------------------------
with tab_team:
    left, right = st.columns(2)

    with left:
        box = card("Calls logged", "Call entries per caller per day, last 14 days (Sundays hidden). "
                                   "Grey = quotes surfaced to that caller that day.")
        calls_df = m.calls_by_day(D, START, today)
        sw_df = m.surfaced_worked_by_day(D, START, today)
        g = grid(calls_df.merge(sw_df, on=["date", "user"], how="outer").fillna(0), ["calls", "surfaced"])
        order = [m.CALLERS.get(u, u) for u in users] + ["Total"]
        ghost = alt.Chart(g).mark_bar(color="#E2E8F0").encode(
            x=alt.X("Day:N", sort=DAY_ORDER, title=None), xOffset=alt.XOffset("Caller:N", sort=order),
            y=alt.Y("surfaced:Q", title="Calls"), tooltip=["Caller", "Day", alt.Tooltip("surfaced", title="Surfaced that day")])
        box.altair_chart(ghost + grouped_bars(g, "calls", "Calls"), width="stretch")

    with right:
        box = card("% of queue worked", "Surfaced quotes that got a logged outcome, Accepted, Requote or Rejected the same day.")
        g = grid(m.surfaced_worked_by_day(D, START, today), ["surfaced", "worked"])
        g["% worked"] = rate(g["worked"], g["surfaced"])
        box.altair_chart(line(g, "% worked", "% worked"), width="stretch")

    left, right = st.columns(2)
    with left:
        box = card("Contact / connect rate", "Reached (interested, next_call, inbound_call) ÷ reached + no_answer_email.")
        g = grid(m.contact_by_day(D, START, today), ["reached", "not_reached"])
        g["Contact rate %"] = rate(g["reached"], g["reached"] + g["not_reached"])
        box.altair_chart(line(g, "Contact rate %", "Contact rate %"), width="stretch")

    with right:
        box = card("Outcome mix (last 14 days)", "Call outcomes per caller.")
        om = m.outcome_mix(D, START, today)
        om = om[om["user"].isin(users)]
        if total_mode:
            om = om.groupby("outcome", as_index=False)["n"].sum().assign(user="Total")
        om["Caller"] = om["user"].map(lambda u: m.CALLERS.get(u, u))
        chart = alt.Chart(om).mark_bar().encode(
            x=alt.X("Caller:N", title=None, sort=order),
            y=alt.Y("n:Q", title="Calls", stack="zero"),
            color=alt.Color("outcome:N", scale=alt.Scale(domain=m.OUTCOMES, range=[OUTCOME_COLORS[o] for o in m.OUTCOMES]),
                            legend=alt.Legend(orient="bottom", title=None)),
            order=alt.Order("outcome_rank:Q"),
            tooltip=["Caller", "outcome", "n"],
        ).transform_calculate(outcome_rank=f"indexof({m.OUTCOMES}, datum.outcome)").properties(height=280)
        box.altair_chart(chart, width="stretch")

    left, right = st.columns(2)
    with left:
        box = card("Channel mix", "Phone / WhatsApp / email per caller.")
        unavailable(box, "channel lives in `tdu_quotes_followup_ext`, which wasn't exported.")

    with right:
        box = card("Calls by hour of day (India time)", "Average calls per active day, last 90 days, 9am–6pm IST.")
        hours, active = m.calls_by_hour(D, today)
        hours = hours[hours["user"].isin(users)]
        active = active[active["user"].isin(users)]
        if total_mode:
            hours = hours.groupby("hour", as_index=False)["calls"].sum().assign(user="Total")
            n_days = {"Total": active["day"].nunique()}  # union of days, not the sum
        else:
            n_days = active.groupby("user")["day"].nunique().to_dict()
        hours["avg"] = (hours["calls"] / hours["user"].map(n_days)).round(2)
        hours["Caller"] = hours["user"].map(lambda u: m.CALLERS.get(u, u))
        hours["Hour"] = hours["hour"].map(lambda h: f"{h:02d}:00")
        chart = alt.Chart(hours).mark_bar().encode(
            x=alt.X("Hour:N", title=None), xOffset=alt.XOffset("Caller:N", sort=order),
            y=alt.Y("avg:Q", title="Calls / active day"),
            color=alt.Color("Caller:N", scale=series_scale(), legend=alt.Legend(orient="bottom", title=None)),
            tooltip=["Caller", "Hour", alt.Tooltip("avg", title="Avg per day"), alt.Tooltip("calls", title="Total calls")],
        ).properties(height=280)
        box.altair_chart(chart, width="stretch")

    # Accepted this month (full width)
    box = card(f"Accepted this month ({today:%B %Y})",
               "First Accepted date per quote, credited to its current owner. Internal account excluded.")
    acc = m.accepted_month(D, today)
    acc_sel = acc[acc["label"].isin(users) | acc["label"].str.startswith("Unassigned")]
    fit, grp = acc_sel[~acc_sel["is_group"]], acc_sel[acc_sel["is_group"]]
    un_fit = int(fit["label"].str.startswith("Unassigned").sum())
    box.markdown(chip(f"{len(fit)} FIT ({un_fit} unassigned) + {len(grp)} Groups = {len(acc_sel)} total", "#DCFCE7", "#166534"),
                 unsafe_allow_html=True)
    mdays = m.month_days(today)
    morder = [d.strftime("%d %b") for d in mdays]
    a = acc_sel.groupby(["ev_date", "label"]).size().rename("n").reset_index()
    a = a[a["ev_date"].isin(mdays)]
    a["Day"] = a["ev_date"].dt.strftime("%d %b")
    a["Owner"] = a["label"].map(lambda u: m.CALLERS.get(u, u))
    dom = [m.CALLERS[u] for u in users] + ["Unassigned (FIT)", "Unassigned (Groups)"]
    rng = [CALLER_COLORS[i % 4] for i in range(len(users))] + ["#94A3B8", "#CBD5E1"]
    box.altair_chart(alt.Chart(a).mark_bar().encode(
        x=alt.X("Day:N", sort=morder, title=None), y=alt.Y("n:Q", title="Accepted", stack="zero"),
        color=alt.Color("Owner:N", scale=alt.Scale(domain=dom, range=rng), legend=alt.Legend(orient="bottom", title=None)),
        tooltip=["Owner", "Day", "n"]).properties(height=260), width="stretch")
    box.download_button("Download list (CSV)", acc_sel[["quote_no", "label", "is_group", "stage", "created_at", "ev_date", "trip", "pax"]]
                        .rename(columns={"label": "owner", "ev_date": "accepted_at", "is_group": "groups"}).to_csv(index=False),
                        file_name=f"accepted_{today:%Y_%m}.csv", mime="text/csv")

    left, right = st.columns(2)
    with left:
        box = card("Quote lifecycle (this month)", "Stage-change events per owner — accepted / rejected / requote.")
        lc = m.lifecycle_month(D, today)
        lc = lc[lc["owner"].isin(users)]
        if total_mode:
            lc = lc.groupby("kind", as_index=False)["n"].sum().assign(owner="Total")
        lc["Owner"] = lc["owner"].map(lambda u: m.CALLERS.get(u, u))
        box.altair_chart(alt.Chart(lc).mark_bar().encode(
            x=alt.X("Owner:N", title=None, sort=order), y=alt.Y("n:Q", title="Events", stack="zero"),
            color=alt.Color("kind:N", scale=alt.Scale(domain=list(STAGE_COLORS), range=list(STAGE_COLORS.values())),
                            legend=alt.Legend(orient="bottom", title=None)),
            tooltip=["Owner", "kind", "n"]).properties(height=260), width="stretch")

    with right:
        wr = m.win_rate_month(D, today)
        tot_a, tot_r = int(wr["accepted"].sum()), int(wr["rejected"].sum())
        box = card("Win rate (Accepted vs Rejected)", "Business-wide, this month. Days with nothing resolved are gaps.")
        box.markdown(chip(f"{pct_text(tot_a, tot_a + tot_r)} ({tot_a}/{tot_a + tot_r})", "#DCFCE7", "#166534"), unsafe_allow_html=True)
        wr = wr.set_index("date").reindex(mdays).reset_index().rename(columns={"index": "date"})
        wr["Win rate %"] = rate(wr["accepted"], wr["accepted"] + wr["rejected"])
        wr["Day"] = wr["date"].dt.strftime("%d %b")
        box.altair_chart(alt.Chart(wr).mark_line(point=True, color=GOOD, strokeWidth=2.5).encode(
            x=alt.X("Day:N", sort=morder, title=None), y=alt.Y("Win rate %:Q", scale=alt.Scale(domain=[0, 100])),
            tooltip=["Day", "Win rate %", "accepted", "rejected"]).properties(height=260), width="stretch")

    box = card("Cycle time: Created → Accepted", "Average days from quote creation to Accepted, this month.")
    ct = m.cycle_time_month(D, today)
    ct = ct[ct["owner"].isin(users)]
    if total_mode and len(ct):
        ct = pd.DataFrame([{"owner": "Total", "sum_days": ct["sum_days"].sum(), "n": ct["n"].sum()}])
    ct["avg days"] = (ct["sum_days"] / ct["n"]).round(1)
    ct["Owner"] = ct["owner"].map(lambda u: m.CALLERS.get(u, u))
    box.altair_chart(alt.Chart(ct).mark_bar().encode(
        x=alt.X("Owner:N", title=None, sort=order), y=alt.Y("avg days:Q", title="Avg days"),
        color=alt.Color("Owner:N", scale=series_scale() if not total_mode else alt.Scale(range=[TEAL]), legend=None),
        tooltip=["Owner", "avg days", alt.Tooltip("n", title="n")]).properties(height=240), width="stretch")

# ---------------------------------------------------------------------------------------------
# Workload & capacity
# ---------------------------------------------------------------------------------------------
with tab_work:
    box = card("Backlog (carry-over) over time & age", "Carry-over rows in each caller's queue, last 14 days. Red at 3+ days behind.")
    g = grid(m.backlog_by_day(D, START, today), ["carryover"])
    box.altair_chart(line(g, "carryover", "Carry-over rows", pct=False), width="stretch")
    heat = []
    for u in users:
        mine = carry[carry["owner"] == u]
        young = int((mine["days_behind"] < m.CARRYOVER_RED_DAYS).sum())
        old = int((mine["days_behind"] >= m.CARRYOVER_RED_DAYS).sum())
        heat.append(f"<b style='color:{INK}'>{m.CALLERS[u]}</b> "
                    f"<span class='heat' style='background:#27ae60'>0-2d: {young}</span>"
                    f"<span class='heat' style='background:{ALERT}'>3d+: {old}</span>")
    box.markdown("&nbsp;&nbsp;&nbsp;".join(heat), unsafe_allow_html=True)
    with box.expander(f"Carry-over quotes today ({len(carry)})"):
        show = carry.assign(owner=carry["owner"].map(m.CALLERS))
        st.dataframe(show[["quote_no", "owner", "days_behind", "first_pending", "next_call_date", "trip", "worked_today"]],
                     hide_index=True, width="stretch")

    left, right = st.columns(2)
    with left:
        box = card("Coverage: are we surfacing everything?", "Distinct quotes surfaced ÷ live quotes with a trip still ahead. All callers.")
        cv = m.coverage(D, DAYS)
        cv["Coverage %"] = rate(cv["surfaced"], cv["qualifying"])
        cv["Day"] = cv["date"].dt.strftime("%d %b")
        box.altair_chart(alt.Chart(cv).mark_line(point=True, color=TEAL, strokeWidth=2.5).encode(
            x=alt.X("Day:N", sort=DAY_ORDER, title=None), y=alt.Y("Coverage %:Q", scale=alt.Scale(domain=[0, 100])),
            tooltip=["Day", "Coverage %", "surfaced", "qualifying"]).properties(height=280), width="stretch")

    with right:
        box = card("Quotes owned right now", "Live Created / Requote quotes per owner.")
        own = m.quotes_owned_now(D)
        own = own[own["owner"].isin(users)]
        own["Owner"] = own["owner"].map(m.CALLERS)
        box.altair_chart(alt.Chart(own).mark_bar().encode(
            x=alt.X("Owner:N", title=None, sort=[m.CALLERS[u] for u in users]), y=alt.Y("n:Q", title="Quotes"),
            color=alt.Color("Owner:N", scale=alt.Scale(domain=[m.CALLERS[u] for u in users],
                                                       range=[CALLER_COLORS[i % 4] for i in range(len(users))]), legend=None),
            tooltip=["Owner", "n"]).properties(height=280), width="stretch")

    box = card("Capacity vs demand", "Follow-up cohort sorted into SP1–SP4 as of each day. Dashed line = daily slot budget.")
    cap, budget = m.capacity_vs_demand(D, DAYS, today)
    cl = cap.melt("date", var_name="bucket", value_name="n")
    cl["Day"] = cl["date"].dt.strftime("%d %b")
    bars = alt.Chart(cl).mark_bar().encode(
        x=alt.X("Day:N", sort=DAY_ORDER, title=None), y=alt.Y("n:Q", title="Quotes", stack="zero"),
        color=alt.Color("bucket:N", scale=alt.Scale(domain=["sp1", "sp2", "sp3", "sp4"],
                                                    range=[BUCKET_COLORS[b] for b in ["sp1", "sp2", "sp3", "sp4"]]),
                        legend=alt.Legend(orient="bottom", title=None)),
        order=alt.Order("bucket:N", sort="ascending"),
        tooltip=["Day", "bucket", "n"])
    rule = alt.Chart(pd.DataFrame({"y": [budget]})).mark_rule(color=INK, strokeDash=[6, 4], size=2).encode(
        y="y:Q", tooltip=[alt.Tooltip("y", title="Daily slot budget")])
    box.altair_chart((bars + rule).properties(height=300), width="stretch")
    box.markdown(chip(f"Budget {budget}/day = 8 regions × ⌊70 × 0.6⌋"), unsafe_allow_html=True)

    box = card("Queue composition over time", "What each day's queue was made of, by bucket.")
    qc = m.queue_composition(D, START, today)
    qc = qc[qc["user"].isin(users) & qc["date"].isin(DAYS)]
    border = list(m.FOLLOW_BUCKETS)
    bscale = alt.Scale(domain=border, range=[BUCKET_COLORS[b] for b in border])
    if total_mode:
        qc = qc.groupby(["date", "bucket"], as_index=False)["n"].sum()
        qc["Day"] = qc["date"].dt.strftime("%d %b")
        chart = alt.Chart(qc).mark_bar().encode(
            x=alt.X("Day:N", sort=DAY_ORDER, title=None), y=alt.Y("n:Q", title="Queue rows", stack="zero"),
            color=alt.Color("bucket:N", scale=bscale, legend=alt.Legend(orient="bottom", title=None)),
            tooltip=["Day", "bucket", "n"])
    else:
        qc = qc.groupby(["user", "bucket"], as_index=False)["n"].sum()
        qc["Caller"] = qc["user"].map(m.CALLERS)
        chart = alt.Chart(qc).mark_bar().encode(
            x=alt.X("Caller:N", sort=[m.CALLERS[u] for u in users], title=None),
            y=alt.Y("n:Q", title="Queue rows (14 days)", stack="zero"),
            color=alt.Color("bucket:N", scale=bscale, legend=alt.Legend(orient="bottom", title=None)),
            tooltip=["Caller", "bucket", "n"])
    box.altair_chart(chart.properties(height=300), width="stretch")

# ---------------------------------------------------------------------------------------------
# Account intelligence
# ---------------------------------------------------------------------------------------------
with tab_acct:
    left, right = st.columns(2)
    with left:
        box = card("Unresponsive organisations", "≥3 contact days in the last 90, and ≥80% of them unanswered. Top 10.")
        ur = m.unresponsive_accounts(D, today)
        if ur.empty:
            box.success("No account meets the threshold.")
        else:
            box.altair_chart(alt.Chart(ur).mark_bar(color=ALERT).encode(
                y=alt.Y("account:N", sort="-x", title=None), x=alt.X("rate:Q", title="% days unanswered", scale=alt.Scale(domain=[0, 100])),
                tooltip=["account", alt.Tooltip("rate", format=".0f"), "days"]).properties(height=300), width="stretch")

    with right:
        mode = st.radio("Sort", ["By volume", "By rejection rate"], horizontal=True, key="acct_mode")
        box = card("Account insights", "Top 10 accounts, all time. Organisation names aren't in the export, so IDs are shown.")
        ai = m.account_insights(D, "rate" if mode == "By rejection rate" else "volume")
        al = ai.melt(id_vars=["account"], value_vars=["Accepted", "Rejected", "In progress"], var_name="kind", value_name="n")
        box.altair_chart(alt.Chart(al).mark_bar().encode(
            y=alt.Y("account:N", sort=list(ai["account"]), title=None), x=alt.X("n:Q", title="Quotes", stack="zero"),
            color=alt.Color("kind:N", scale=alt.Scale(domain=["Accepted", "Rejected", "In progress"], range=["#EF6C24", ALERT, "#94A3B8"]),
                            legend=alt.Legend(orient="bottom", title=None)),
            tooltip=["account", "kind", "n"]).properties(height=300), width="stretch")

    box = card("Why we lose — rejection reasons")
    unavailable(box, "reasons are stored in `tdu_quote_closure_feedback`, which wasn't exported.")

# ---------------------------------------------------------------------------------------------
# Quote pipeline
# ---------------------------------------------------------------------------------------------
with tab_pipe:
    live = m.live_basket(D)
    box = card("Live basket by stage", f"{len(live):,} live quotes — excludes Rejected, Auto Rejected and Lead.")
    sc = live["stage"].value_counts().rename_axis("stage").rename("n").reset_index()
    box.altair_chart(alt.Chart(sc).mark_bar(color=TEAL).encode(
        y=alt.Y("stage:N", sort="-x", title=None), x=alt.X("n:Q", title="Quotes"), tooltip=["stage", "n"])
        .properties(height=max(160, 26 * len(sc))), width="stretch")

    scope_label = st.radio("Scope for the charts below", ["Created / Requote", "All stages"], horizontal=True, key="pipe_scope")
    df_scope = m.scope(D, "open" if scope_label == "Created / Requote" else "all")

    left, right = st.columns(2)
    with left:
        box = card("Composition: FIT vs Groups", f"Groups split at {m.GROUPS_PAX_THRESHOLD} pax.")
        cp = m.composition(df_scope)
        cp["label"] = cp.apply(lambda r: f"{r['kind']} — {r['n']} ({r['n'] / cp['n'].sum() * 100:.0f}%)", axis=1)
        box.altair_chart(alt.Chart(cp).mark_arc(innerRadius=70).encode(
            theta="n:Q", color=alt.Color("label:N", scale=alt.Scale(range=["#1C93C4", "#EF6C24", "#F5A623"]),
                                         legend=alt.Legend(orient="right", title=None)),
            tooltip=["kind", "n"]).properties(height=260), width="stretch")
    with right:
        box = card("Destination: Australia vs New Zealand")
        ds = df_scope["country"].value_counts().rename_axis("country").rename("n").reset_index()
        ds["label"] = ds.apply(lambda r: f"{r['country']} — {r['n']} ({r['n'] / ds['n'].sum() * 100:.0f}%)", axis=1)
        box.altair_chart(alt.Chart(ds).mark_arc(innerRadius=70).encode(
            theta="n:Q", color=alt.Color("label:N", scale=alt.Scale(range=["#4C6EF5", "#0CA678", "#94A3B8"]),
                                         legend=alt.Legend(orient="right", title=None)),
            tooltip=["country", "n"]).properties(height=260), width="stretch")

    left, right = st.columns(2)
    with left:
        hz_scope = st.radio("Travel-date scope", ["Created / Requote", "All", "Accepted"], horizontal=True, key="hz")
        box = card("Travel-date horizon", "Live quotes by trip month (trip still ahead).")
        hz = m.travel_horizon(m.scope(D, {"Created / Requote": "open", "All": "all", "Accepted": "accepted"}[hz_scope]), today)
        box.altair_chart(alt.Chart(hz).mark_bar(color=TEAL).encode(
            x=alt.X("label:N", sort=list(hz["label"]), title=None), y=alt.Y("n:Q", title="Quotes"),
            tooltip=["label", "n"]).properties(height=280), width="stretch")
    with right:
        box = card("Passenger counts", "Adults + children + infants; zero-pax quotes excluded.")
        f, g = m.pax_buckets(df_scope)
        cf, cg = box.columns(2)
        cf.markdown(f"**FIT** · avg {f.attrs['avg']:.1f} pax · {f.attrs['count']:,} quotes")
        cf.altair_chart(alt.Chart(f).mark_bar(color="#1C93C4").encode(
            x=alt.X("bucket:N", sort=list(f["bucket"]), title=None), y=alt.Y("n:Q", title=None), tooltip=["bucket", "n"])
            .properties(height=230), width="stretch")
        cg.markdown(f"**Groups** · avg {g.attrs['avg']:.1f} pax · {g.attrs['count']:,} quotes")
        cg.altair_chart(alt.Chart(g).mark_bar(color="#EF6C24").encode(
            x=alt.X("bucket:N", sort=list(g["bucket"]), title=None), y=alt.Y("n:Q", title=None), tooltip=["bucket", "n"])
            .properties(height=230), width="stretch")

    box = card("Where are our quotes?")
    unavailable(box, "origin region comes from `vtiger_quotes_info` and `vtiger_groups`, which weren't exported.")

    exp = m.export_next_3_months(D, today)
    st.download_button(f"Download next 3 months report (CSV) — {len(exp):,} quotes", exp.to_csv(index=False),
                       file_name=f"next_3_months_{today:%Y_%m}.csv", mime="text/csv")

# ---------------------------------------------------------------------------------------------
# By person
# ---------------------------------------------------------------------------------------------
with tab_person:
    c1, c2, c3 = st.columns([2, 2, 3])
    who_name = c1.selectbox("Caller", list(m.CALLERS.values()), key="who")
    who = m.FULLNAME_TO_LOGIN[who_name]
    rng = c2.radio("Range", ["This month", "This year", "Custom"], horizontal=True, key="rng")
    if rng == "This month":
        p_start, p_end = today.replace(day=1), today
    elif rng == "This year":
        p_start, p_end = today.replace(month=1, day=1), today
    else:
        picked_range = c3.date_input("From – to", (today.replace(day=1).date(), today.date()), key="custom")
        if isinstance(picked_range, tuple) and len(picked_range) == 2:
            p_start, p_end = pd.Timestamp(picked_range[0]), pd.Timestamp(picked_range[1])
        else:
            p_start = p_end = today

    ps = m.person_summary(D, who, p_start, p_end)
    st.markdown(f"<div class='muted'>{who_name} · {p_start:%d %b %Y} → {p_end:%d %b %Y}</div>", unsafe_allow_html=True)
    r1 = st.columns(6)
    r1[0].metric("Total", ps["total"])
    r1[1].metric("Companies", ps["companies"])
    r1[2].metric("Created", ps["created"])
    r1[3].metric("Requote", ps["requote"])
    r1[4].metric("Accepted", ps["accepted"])
    r1[5].metric("Rejected", ps["rejected"])
    r2 = st.columns(4)
    r2[0].metric("Inbound calls", ps["inbound"])
    r2[1].metric("Conversion", f"{ps['conversion']:.1f}%" if ps["conversion"] is not None else "—")
    r2[2].metric("Avg first response", f"{ps['first_response']:.1f} d" if ps["first_response"] is not None else "—")
    r2[3].metric("Avg cycle time", f"{ps['cycle']:.1f} d" if ps["cycle"] is not None else "—")

    with st.expander(f"Quotes created in range ({len(ps['made'])})"):
        st.dataframe(ps["made"][["quote_no", "stage", "created_at", "trip", "pax", "accountid"]], hide_index=True, width="stretch")

    left, right = st.columns(2)
    with left:
        box = card("Calls by hour (India time)", "Raw call counts in the selected range.")
        pc = D["calls"]
        pc = pc[(pc["login"] == who) & pc["calltime"].between(p_start, p_end + m.DAY)]
        ist = (pc["calltime"].dt.tz_localize("Australia/Melbourne", ambiguous="NaT", nonexistent="shift_forward")
               .dt.tz_convert("Asia/Kolkata").dt.hour)
        ph = ist[ist.between(9, 18)].value_counts().reindex(range(9, 19), fill_value=0).rename_axis("hour").rename("calls").reset_index()
        ph["Hour"] = ph["hour"].map(lambda h: f"{h:02d}:00")
        box.altair_chart(alt.Chart(ph).mark_bar(color=CALLER_COLORS[list(m.CALLERS).index(who)]).encode(
            x=alt.X("Hour:N", title=None), y=alt.Y("calls:Q", title="Calls"), tooltip=["Hour", "calls"])
            .properties(height=260), width="stretch")

    book = m.live_book(D, who, today)
    with right:
        box = card("Upcoming follow-ups", f"{len(book)} live Created / Requote quotes owned by {who_name.split()[0]}.")
        ub = m.upcoming_buckets(book, today)
        ub["color"] = ub["bucket"].map(lambda b: ALERT if b == "Overdue" else ("#94A3B8" if b == "Not scheduled" else TEAL))
        box.altair_chart(alt.Chart(ub).mark_bar().encode(
            x=alt.X("bucket:N", sort=list(ub["bucket"]), title=None), y=alt.Y("n:Q", title="Quotes"),
            color=alt.Color("color:N", scale=None), tooltip=["bucket", "n"]).properties(height=260), width="stretch")

    box = card("Live book by travel month")
    lb = book[book["trip"].notna()].copy()
    lb["month"] = lb["trip"].dt.to_period("M").dt.to_timestamp()
    lbm = lb.groupby("month").size().rename("n").reset_index()
    lbm["label"] = lbm["month"].dt.strftime("%b %Y")
    box.altair_chart(alt.Chart(lbm).mark_bar(color=TEAL).encode(
        x=alt.X("label:N", sort=list(lbm["label"]), title=None), y=alt.Y("n:Q", title="Quotes"), tooltip=["label", "n"])
        .properties(height=240), width="stretch")

    box = card("Live book")
    search = box.text_input("Filter by quote number", key="book_q", placeholder="TDU0…")
    shown = book[book["quote_no"].str.contains(search.strip(), case=False)] if search else book
    box.dataframe(shown.drop(columns=["quoteid"]), hide_index=True, width="stretch",
                  column_config={"trip": st.column_config.DateColumn("Trip"), "created_at": st.column_config.DatetimeColumn("Created"),
                                 "last_call": st.column_config.DatetimeColumn("Last call"),
                                 "next_call_date": st.column_config.DateColumn("Next call"),
                                 "is_group": st.column_config.CheckboxColumn("Groups")})

    unavailable(st.container(border=True), "the Region / Priority breakdown donut needs `vtiger_quotes_info`.")

# ---------------------------------------------------------------------------------------------
# Data notes
# ---------------------------------------------------------------------------------------------
with tab_notes:
    st.markdown(f"""
#### What this is
A Python rebuild of the PHP monitoring dashboard (`tdu_queue/monitoring_system`), run against the
seven CSV exports in `Sales Queue/data/`. Every calculation follows the PHP logic. Where the export is
missing a table the PHP needs, the stand-in is listed below.

#### Stand-ins for missing tables
| Needed by the PHP | Missing table | Used instead |
|---|---|---|
| Quote owner | `vtiger_quotes_info.assigned_to_sales_agent` | Caller on the quote's **latest `daily_runs` row**, else `created_by` when it's one of the four callers |
| Region filter | `vtiger_quotes_info.assigned_to_region` | Quotes that **ever appeared in `daily_runs`**, which only happens for India regions |
| "Worked" via closure | `tdu_quote_closure_feedback` | A **"Change Stage to Rejected"** event that day |
| Organisation names | `tdu_organisation` | `Account #<accountid>` |
| Slot budget | per-region config | **8 India regions** × ⌊70 × 0.6⌋ = 336 |

#### Not buildable from this export
Channel mix · Rejection reasons · "Where are our quotes?" map · Region/Priority breakdown in By person.

#### Snapshot caveat
"Today" is set to **{today:%d %b %Y}**. Stages and next-call dates are as of the export (Sep 2026), so
historical days use *current* stages — the PHP does the same for the coverage and capacity charts.

#### Callers
{", ".join(f"`{k}` = {v}" for k, v in m.CALLERS.items())}. `Arun` (Arun Karotiya) is a different person from `ArunP`.
""")
