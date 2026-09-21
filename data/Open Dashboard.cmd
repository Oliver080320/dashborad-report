@echo off
cd /d "%~dp0"
where pythonw >nul 2>nul
if errorlevel 1 (
  echo Python was not found. Install Python or open the dashboard from the running localhost link.
  pause
  exit /b 1
)
start "" /b pythonw "%~dp0launch_dashboard.pyw"
