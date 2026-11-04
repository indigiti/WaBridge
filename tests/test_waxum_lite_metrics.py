#!/usr/bin/env python3
"""Offline regression tests for the Waxum Lite binary-size evidence gate."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools" / "compare-waxum-binaries.py"
spec = importlib.util.spec_from_file_location("waxum_binary_metrics", SCRIPT)
metrics = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metrics)


class SizeEvidenceTests(unittest.TestCase):
    def test_smaller_candidate_passes_raw_and_gzip_gates(self):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            full = folder / "full"
            lite = folder / "lite"
            full.write_bytes(b"A" * 12000)
            lite.write_bytes(b"B" * 5000)
            result = metrics.compare(metrics.measure(full), metrics.measure(lite), 5)
            self.assertTrue(result["size_gate_passed"])
            self.assertFalse(result["production_approved"])
            self.assertFalse(result["live_device_verified"])
            self.assertEqual(result["full"]["bytes"], 12000)
            self.assertGreater(result["raw_savings_pct"], 50)
            # Deterministic gzip bytes do not depend on filename or mtime.
            self.assertEqual(metrics.measure(full), metrics.measure(full))

    def test_bigger_candidate_fails_even_if_minimum_is_zero(self):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            full = folder / "full"
            lite = folder / "lite"
            full.write_bytes(b"A" * 1024)
            lite.write_bytes(b"Z" * 4096)
            result = metrics.compare(metrics.measure(full), metrics.measure(lite), 0)
            self.assertFalse(result["size_gate_passed"])
            self.assertLess(result["raw_savings_pct"], 0)

    def test_missing_or_empty_files_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "empty"
            with self.assertRaises(ValueError):
                metrics.measure(path)
            path.write_bytes(b"")
            with self.assertRaises(ValueError):
                metrics.measure(path)

    def test_enforce_exits_nonzero_and_writes_report_for_failed_gate(self):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            full = folder / "full"
            lite = folder / "lite"
            full.write_bytes(b"A" * 1000)
            lite.write_bytes(b"B" * 2000)
            output = folder / "results.json"
            run = subprocess.run(
                [sys.executable, str(SCRIPT), "--full", str(full), "--lite", str(lite),
                 "--json-out", str(output), "--enforce"],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(run.returncode, 1)
            self.assertTrue(output.is_file())
            self.assertIn("FAIL", run.stdout)


if __name__ == "__main__":
    unittest.main()
