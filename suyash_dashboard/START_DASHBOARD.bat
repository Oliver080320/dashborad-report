@echo off
REM Double-click to install the packages (first run only) and open the dashboard.
cd /d "%~dp0"
echo Installing / checking packages...
python -m pip install -r requirements.txt --quiet
if errorlevel 1 (
  echo.
  echo Package install failed. Check that Python 3.11+ is installed and on PATH.
  pause
  exit /b 1
)
echo Starting dashboard at http://localhost:8501  (close this window to stop it)
start "" cmd /c "timeout /t 8 >nul & start http://localhost:8501"
python run.py
pause
