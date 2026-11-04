#!/usr/bin/env python3
"""Offline tests for fail-closed Waxum Core v1 source transformations."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools" / "waxum-core-v1.py"
spec = importlib.util.spec_from_file_location("waxum_core_v1", SCRIPT)
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)


class CorePatchTests(unittest.TestCase):
    def test_replace_exact_preserves_surrounding_source(self):
        self.assertEqual(
            core.replace_exact("left A right", "A", "B", context="example"),
            "left B right",
        )

    def test_replace_exact_fails_on_missing_or_ambiguous_source(self):
        for source in ("empty", "token and token"):
            with self.subTest(source=source):
                with self.assertRaises(ValueError):
                    core.replace_exact(source, "token", "safe", context="example")

    def test_default_build_keeps_compatibility_features(self):
        text = SCRIPT.read_text()
        self.assertIn('default = ["embedded-console", "swagger-ui"]', text)
        self.assertIn("REUSABLE_LIVE_LOOKUP_PATHS", text)
        self.assertIn("get_installed_client", text)
        self.assertIn("UPSTREAM_COMMIT", text)
        self.assertIn('feature = "embedded-console"', text)
        self.assertIn('feature = "swagger-ui"', text)

    @staticmethod
    def media_fixture():
        blocks = []
        for kind, mime, media_type in (
            ("image", "mimetype", "Image"),
            ("video", "mimetype", "Video"),
            ("audio", "mimetype", "Audio"),
            ("document", "mimetype", "Document"),
            ("sticker", "_mimetype", "Sticker"),
        ):
            blocks.append(
                f"    let (data, {mime}) = get_media_data(&request.{kind}).await?;\n\n"
                f"    let upload = client\n        .upload(\n"
                f"            data.clone(),\n"
                f"            wacore::download::MediaType::{media_type},\n"
                f"            Default::default(),\n"
                f"        ).await?;\n"
                f"    let message = file_length: Some(data.len() as u64),;\n"
            )
        return "\n".join(blocks)

    def test_media_buffers_move_without_full_payload_clones(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "src/api/shared/messages/handlers/media.rs"
            path.parent.mkdir(parents=True)
            path.write_text(self.media_fixture())
            core.optimize_media_send_buffers(Path(tmp))
            rewritten = path.read_text()
            self.assertEqual(rewritten.count("let media_len = data.len();"), 5)
            self.assertEqual(rewritten.count("file_length: Some(media_len as u64),"), 5)
            self.assertEqual(rewritten.count("            data,\n"), 5)
            self.assertNotIn("data.clone(),", rewritten)

    def test_media_patch_rejects_partial_upstream_drift(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "src/api/shared/messages/handlers/media.rs"
            path.parent.mkdir(parents=True)
            path.write_text(self.media_fixture().replace("MediaType::Video", "MediaType::Unknown"))
            with self.assertRaises(ValueError):
                core.optimize_media_send_buffers(Path(tmp))
            self.assertIn("data.clone(),", path.read_text())

    def test_refuses_to_rewrite_unrecognized_helpers(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "not-identical.rs"
            path.write_text("fn get_client() { panic!(\"different semantics\"); }")
            with self.assertRaises(ValueError):
                core.replace_file(
                    Path(tmp), "not-identical.rs",
                    core.LIVE_LOOKUP, core.DELEGATED_LOOKUP,
                )
            self.assertEqual(
                path.read_text(),
                'fn get_client() { panic!("different semantics"); }',
            )


if __name__ == "__main__":
    unittest.main()
