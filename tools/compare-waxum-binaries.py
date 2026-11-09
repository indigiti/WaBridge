#!/usr/bin/env python3
"""Compare pinned Waxum full and experimental Lite binaries.

Reports raw ELF and deterministic gzip sizes. This does not benchmark RAM,
CPU, throughput, QR pairing, or WhatsApp message delivery.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import json
from pathlib import Path


def measure(path: Path) -> dict:
    if not path.is_file():
        raise ValueError(f"missing binary: {path}")
    contents = path.read_bytes()
    if not contents:
        raise ValueError(f"empty binary: {path}")
    return {
        "name": path.name,
        "bytes": len(contents),
        "gzip_bytes": len(gzip.compress(contents, compresslevel=9, mtime=0)),
        "sha256": hashlib.sha256(contents).hexdigest(),
    }


def compare(full: dict, lite: dict, threshold: float) -> dict:
    if full["bytes"] <= 0 or full["gzip_bytes"] <= 0:
        raise ValueError("baseline must be nonempty")
    raw_pct = (full["bytes"] - lite["bytes"]) / full["bytes"] * 100
    gzip_pct = (full["gzip_bytes"] - lite["gzip_bytes"]) / full["gzip_bytes"] * 100
    # A smaller extracted executable is necessary; a larger download is a regression.
    eligible = raw_pct >= threshold and gzip_pct > 0
    return {
        "full": full,
        "lite": lite,
        "raw_savings_pct": round(raw_pct, 3),
        "gzip_savings_pct": round(gzip_pct, 3),
        "minimum_raw_savings_pct": threshold,
        "size_gate_passed": eligible,
        "production_approved": False,
        "live_device_verified": False,
    }


def markdown(result: dict) -> str:
    a, b = result["full"], result["lite"]
    return "\n".join([
        "# Waxum Lite size evidence",
        "",
        "| Metric | Full, verified baseline | Lite candidate |",
        "|---|---:|---:|",
        f'| Raw executable (bytes) | {a["bytes"]:,} | {b["bytes"]:,} |',
        f'| Deterministic gzip (bytes) | {a["gzip_bytes"]:,} | {b["gzip_bytes"]:,} |',
        f'| SHA-256 | `{a["sha256"]}` | `{b["sha256"]}` |',
        "",
        f'Raw saving: **{result["raw_savings_pct"]:.3f}%**; gzip saving: **{result["gzip_savings_pct"]:.3f}%**.',
        f'Size gate (raw >= {result["minimum_raw_savings_pct"]:.1f}%, gzip smaller): '
        f'**{"PASS" if result["size_gate_passed"] else "FAIL"}**.',
        "",
        "**NOT a production release.** glibc compatibility and CLI smoke checks are "
        "separate gates. Actual QR, message receipt, reconnection, media, throughput, "
        "CPU/RSS and restart persistence require staging evidence.",
        "",
    ])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--full", type=Path, required=True)
    parser.add_argument("--lite", type=Path, required=True)
    parser.add_argument("--json-out", type=Path)
    parser.add_argument("--markdown-out", type=Path)
    parser.add_argument("--min-raw-savings-pct", type=float, default=5.0)
    parser.add_argument("--enforce", action="store_true")
    args = parser.parse_args()
    if not 0 <= args.min_raw_savings_pct <= 100:
        parser.error("minimum saving must be between 0 and 100")
    result = compare(measure(args.full), measure(args.lite), args.min_raw_savings_pct)
    report = markdown(result)
    print(report)
    if args.json_out:
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        args.json_out.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
    if args.markdown_out:
        args.markdown_out.parent.mkdir(parents=True, exist_ok=True)
        args.markdown_out.write_text(report)
    return int(args.enforce and not result["size_gate_passed"])


if __name__ == "__main__":
    raise SystemExit(main())
