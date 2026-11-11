"""Run both real Swoole process modes with isolated services inherited from the environment."""
import json
import os
import signal
import subprocess
import time
from urllib.request import urlopen
from pathlib import Path

root = Path(__file__).resolve().parent.parent
(root / 'runtime').mkdir(exist_ok=True)
for index, mode in enumerate(("base", "process")):
    port = 19501 + index
    with open(root / "runtime" / ("server-" + mode + ".log"), "w+") as log:
        child = subprocess.Popen([os.environ.get("PHP_BINARY", "php"), str(root / "bin/server-smoke.php"), mode, str(port)], cwd=root, stdout=log, stderr=log, start_new_session=True)
        try:
            deadline = time.monotonic() + 20
            while True:
                try:
                    with urlopen("http://127.0.0.1:%d/" % port, timeout=3) as response:
                        data = json.load(response)
                    break
                except Exception:
                    if child.poll() is not None or time.monotonic() > deadline:
                        log.seek(0)
                        raise RuntimeError(mode + " startup failed: " + log.read())
                    time.sleep(0.1)
            for attempt in range(6):
                with urlopen("http://127.0.0.1:%d/" % port, timeout=5) as response:
                    data = json.load(response)
                assert data["queries"] == 1 and data["loads"] == 1 and data["released"], (mode, data)
            print(mode + ": 2 workers, 6 repeated requests; each request issued one DB query for 6 parallel ID lookups; scope released", flush=True)
        finally:
            os.killpg(child.pid, signal.SIGTERM)
            try:
                child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(child.pid, signal.SIGKILL)
                child.wait()
