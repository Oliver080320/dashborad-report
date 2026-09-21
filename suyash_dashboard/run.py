"""Launcher: starts the Streamlit dashboard on $PORT (default 8501)."""
import os
import sys
from pathlib import Path

from streamlit.web import cli

APP = Path(__file__).resolve().parent / "app.py"
sys.argv = ["streamlit", "run", str(APP), "--server.port", os.environ.get("PORT", "8501"),
            "--server.headless", "true", "--browser.gatherUsageStats", "false",
            "--theme.primaryColor", "#0F766E", "--theme.backgroundColor", "#F8FAFC",
            "--theme.secondaryBackgroundColor", "#FFFFFF", "--theme.textColor", "#334155"]
sys.exit(cli.main())
