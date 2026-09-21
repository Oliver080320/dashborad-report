"""Start or reuse this dashboard's loopback server, then open the browser."""
import ctypes
import functools
import http.server
import json
import subprocess
import sys
import time
import urllib.request
import webbrowser
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DIST = ROOT / 'dashboard' / 'dist'

def matches(port):
    try:
        with urllib.request.urlopen(f'http://127.0.0.1:{port}/data-app-build.json',timeout=1) as response:
            return json.load(response) == json.loads((DIST/'data-app-build.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return False

def launch(open_browser=True, start_port=4173):
    if not (DIST/'index.html').is_file():
        raise RuntimeError('Dashboard build is missing. Keep this launcher beside the dashboard folder.')
    for port in range(start_port, start_port+10):
        if matches(port):
            url=f'http://127.0.0.1:{port}/'
            if open_browser: webbrowser.open(url)
            return url
        # The child binds atomically; an occupied port fails without disturbing its owner.
        child=subprocess.Popen([sys.executable,str(Path(__file__).resolve()),'--serve',str(port)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        for _ in range(30):
            if child.poll() is not None: break
            if matches(port):
                url=f'http://127.0.0.1:{port}/'
                if open_browser: webbrowser.open(url)
                return url
            time.sleep(.1)
        if child.poll() is None: child.terminate()
    raise RuntimeError('Could not start the dashboard on local ports 4173–4182. Close an unused local preview and try again.')

if __name__ == '__main__':
    try:
        if '--serve' in sys.argv:
            handler=functools.partial(http.server.SimpleHTTPRequestHandler,directory=str(DIST))
            http.server.ThreadingHTTPServer(('127.0.0.1',int(sys.argv[-1])),handler).serve_forever()
        else:
            url=launch(open_browser='--no-browser' not in sys.argv)
            if sys.stdout: print(url)
    except Exception as error:
        if sys.stdout: print(error)
        if '--serve' not in sys.argv and '--no-browser' not in sys.argv:
            ctypes.windll.user32.MessageBoxW(0,str(error),'Sales dashboard',0x10)
        sys.exit(1)
