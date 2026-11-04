#!/usr/bin/env python3
"""Exercise full/headless Waxum on loopback with isolated disposable SQLite data.

This verifies HTTP behavior and samples no-session performance. It never
connects to WhatsApp, contacts real users, or claims delivery correctness.
Results are informative: shared GitHub runner measurements are noisy.
"""
from __future__ import annotations

import argparse
import json
import math
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

# Used only for ephemeral CI processes; never use any deployed secret.
SMOKE_TOKEN = "ci-offline-only-inert-test-token-2026"
READ_COUNT = 48


def free_loopback_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def http_get(base: str, path: str, *, auth: bool = False) -> tuple[int, bytes]:
    headers = {"Accept": "application/json"}
    if auth:
        headers["Authorization"] = "Bearer " + SMOKE_TOKEN
    request = Request(base + path, headers=headers)
    try:
        with urlopen(request, timeout=4) as response:
            return int(response.status), response.read(1024 * 1024)
    except HTTPError as error:
        return int(error.code), error.read(1024 * 1024)


def require_status(base: str, path: str, expected: int, *, auth: bool = False) -> bytes:
    status, body = http_get(base, path, auth=auth)
    if status != expected:
        raise AssertionError(
            f"{path} expected HTTP {expected}, got {status}: "
            + body[:180].decode("utf-8", "replace")
        )
    return body


def percentile_ms(samples: list[float], percentile: float) -> float:
    if not samples:
        raise ValueError("empty latency sample")
    ordered = sorted(samples)
    position = max(0, math.ceil(percentile * len(ordered)) - 1)
    return round(ordered[position], 3)


def process_snapshot(pid: int) -> dict:
    # Linux /proc values are a point-in-time sample, NOT peak / PSS.
    try:
        rows = (Path("/proc") / str(pid) / "status").read_text().splitlines()
    except OSError:
        return {}
    fields = {}
    for line in rows:
        if line.startswith("VmRSS:"):
            fields["rss_kib"] = int(line.split()[1])
        if line.startswith("Threads:"):
            fields["threads"] = int(line.split()[1])
    return fields


def measure(binary: Path, *, headless: bool) -> dict:
    if not binary.is_file():
        raise FileNotFoundError(binary)
    port = free_loopback_port()
    label = "headless_candidate" if headless else "full_baseline"
    with tempfile.TemporaryDirectory(prefix=f"waxum-{label}-") as root:
        working = Path(root)
        storage = working / "sessions"
        storage.mkdir()
        env = os.environ.copy()
        env.update({
            "PORT": str(port),
            "DATABASE_URL": "sqlite://" + str(working / "waxum.sqlite"),
            "WHATSAPP_STORAGE_PATH": str(storage),
            "SUPERADMIN_TOKEN": SMOKE_TOKEN,
            "JWT_SECRET": "ci-offline-test-secret-do-not-use-outside-ci",
            "WA_RS_WORKER_THREADS": "2",
            "WA_RS_BLOCKING_THREADS": "16",
            "RUST_LOG": "waxum=warn",
            "AUTO_PRESENCE_ON_SEND": "false",
        })
        # Prevent unrelated CI environment from causing NATS/S3 access.
        for key in ("NATS_URL", "S3_BUCKET", "AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY",
                    "AWS_SESSION_TOKEN", "POSTGRES_HOST", "MYSQL_HOST", "WHATSAPP_CLOUD_TOKEN"):
            env.pop(key, None)
        logfile = working / "waxum-smoke.log"
        start = time.perf_counter()
        with logfile.open("wb") as log:
            process = subprocess.Popen(
                [str(binary.resolve())], cwd=working, env=env,
                stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
            )
            try:
                base = f"http://127.0.0.1:{port}"
                while True:
                    if process.poll() is not None:
                        raise RuntimeError(f"{label} exited prematurely: {process.returncode}")
                    try:
                        status, _ = http_get(base, "/livez")
                        if status == 200:
                            break
                    except (OSError, URLError):
                        pass
                    if time.perf_counter() - start > 45:
                        raise TimeoutError(f"{label} /livez not ready after startup")
                    time.sleep(0.15)

                startup_ms = round((time.perf_counter() - start) * 1000, 2)
                require_status(base, "/readyz", 200)
                require_status(base, "/api/v1/sessions", 401)
                raw = require_status(base, "/api/v1/sessions", 200, auth=True)
                json.loads(raw.decode("utf-8"))
                require_status(base, "/api/v1/info", 200, auth=True)
                if headless:
                    for path in ("/", "/login", "/swagger-ui",
                                 "/api-docs/openapi.json"):
                        require_status(base, path, 404, auth=True)

                # One warmup before timed requests, with a single client and
                # identical no-session API route for both builds.
                require_status(base, "/api/v1/sessions", 200, auth=True)
                latencies = []
                for _ in range(READ_COUNT):
                    t0 = time.perf_counter()
                    require_status(base, "/api/v1/sessions", 200, auth=True)
                    latencies.append((time.perf_counter() - t0) * 1000)

                return {
                    "binary": label, "headless": headless,
                    "startup_ms": startup_ms,
                    "api_reads": READ_COUNT,
                    "api_p50_ms": percentile_ms(latencies, .5),
                    "api_p95_ms": percentile_ms(latencies, .95),
                    "api_max_ms": round(max(latencies), 3),
                    "connected_sessions": 0,
                    "rss_is_snapshot": True,
                    **process_snapshot(process.pid),
                    "api_auth_ok": True,
                    "isolated_sqlite": True,
                    "real_device_verified": False,
                }
            except BaseException as error:
                log.flush()
                with logfile.open("rb") as log_read:
                    tail = log_read.read()[-2000:].decode("utf-8", "replace")
                raise RuntimeError(f"{label}: {error}; process log tail: {tail}") from error
            finally:
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=6)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=4)


def markdown(data: dict) -> str:
    full, lite = data["full"], data["headless"]
    rows = [
        "# Waxum Core v1 — disposable no-session HTTP smoke",
        "",
        "| Measurement | Full baseline | Core v1 headless |",
        "|---|---:|---:|",
    ]
    for key, label, unit in (
        ("startup_ms", "Startup to /livez", "ms"),
        ("rss_kib", "Idle RSS snapshot", "KiB"),
        ("threads", "Idle threads", ""),
        ("api_p50_ms", "Authenticated sessions API p50", "ms"),
        ("api_p95_ms", "Authenticated sessions API p95", "ms"),
    ):
        a, b = full.get(key), lite.get(key)
        rows.append(f"| {label} | {a} {unit} | {b} {unit} |")
    rows.extend([
        "",
        f"Each binary handled {READ_COUNT} local authenticated session-list "
        "requests with **zero connected WhatsApp sessions**.",
        "",
        "These results are observational measurements on shared CI hardware, "
        "not throughput guarantees or robust CPU/RAM comparisons. "
        "RSS is a single snapshot, not peak RSS/PSS. No message was sent. "
        "QR linking, delivery receipts and session persistence still require "
        "real-device staging checks.",
        "",
    ])
    return "\n".join(rows)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--full", type=Path, required=True)
    parser.add_argument("--candidate", type=Path, required=True)
    parser.add_argument("--json-out", type=Path, required=True)
    parser.add_argument("--markdown-out", type=Path, required=True)
    args = parser.parse_args()

    data = {"full": measure(args.full, headless=False)}
    data["headless"] = measure(args.candidate, headless=True)
    summary = markdown(data)
    args.json_out.parent.mkdir(parents=True, exist_ok=True)
    args.json_out.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")
    args.markdown_out.write_text(summary)
    print(summary)


if __name__ == "__main__":
    main()
