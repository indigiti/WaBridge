#!/usr/bin/env python3
"""Apply fail-closed, reviewable headless/reuse patches to pinned Waxum v0.16.1.

The original upstream source must be checked out at the exact commit by CI.
This intentionally creates a staging-only build. It is not a replacement for
an independently maintained and regression-tested upstream fork.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import re

UPSTREAM_COMMIT = "851bedf3189bda742433ef57bc06e75be590860b"
UPSTREAM_VERSION = "0.16.1"

LIVE_LOOKUP = """fn get_client(
    state: &AppState,
    session_id: &str,
) -> Result<std::sync::Arc<whatsapp_rust::Client>, ApiError> {
    let runtime = state
        .get_session(session_id)
        .ok_or(ApiError::NotConnected)?;

    runtime.get_live_client().ok_or(ApiError::NotConnected)
}"""

DELEGATED_LOOKUP = """fn get_client(
    state: &AppState,
    session_id: &str,
) -> Result<std::sync::Arc<whatsapp_rust::Client>, ApiError> {
    crate::api::shared::messages::handlers::get_client(state, session_id)
}"""

# Only these known-identical helpers are refactored; paused-session access is
# deliberately different and must keep using get_installed_client.
REUSABLE_LIVE_LOOKUP_PATHS = (
    "src/api/web/contacts/handlers.rs",
    "src/api/web/groups/handlers.rs",
    "src/api/web/status/handlers.rs",
    "src/api/web/operations/handlers.rs",
    "src/api/web/mex/handlers.rs",
)


def replace_exact(source: str, old: str, new: str, *, context: str) -> str:
    """Abort on upstream drift instead of silently writing an invalid patch."""
    count = source.count(old)
    if count != 1:
        raise ValueError(f"{context}: expected one exact match, found {count}")
    return source.replace(old, new, 1)


def replace_file(root: Path, path: str, old: str, new: str) -> None:
    file = root / path
    source = file.read_text()
    updated = replace_exact(source, old, new, context=path)
    file.write_text(updated)



def optimize_media_send_buffers(root: Path) -> None:
    """Move owned media buffers into uploads; preserve the protobuf file length.

    Image, video, audio, document and sticker paths previously cloned their
    complete Vec<u8> for Client::upload and only used the source to read len.
    Avoiding that copy reduces peak temporary memory by up to one media payload.
    Fail closed if any one of the five original patterns changes upstream.
    """
    path = root / "src/api/shared/messages/handlers/media.rs"
    source = path.read_text()
    variants = (
        ("image", "mimetype", "Image"),
        ("video", "mimetype", "Video"),
        ("audio", "mimetype", "Audio"),
        ("document", "mimetype", "Document"),
        ("sticker", "_mimetype", "Sticker"),
    )
    for kind, mimetype, media_type in variants:
        old = (
            f"    let (data, {mimetype}) = get_media_data(&request.{kind}).await?;\n\n"
            f"    let upload = client\n        .upload(\n"
            f"            data.clone(),\n"
            f"            wacore::download::MediaType::{media_type},"
        )
        new = (
            f"    let (data, {mimetype}) = get_media_data(&request.{kind}).await?;\n"
            f"    let media_len = data.len();\n\n"
            f"    let upload = client\n        .upload(\n"
            f"            data,\n"
            f"            wacore::download::MediaType::{media_type},"
        )
        source = replace_exact(source, old, new, context=f"{kind} upload without clone")
    old_length = "file_length: Some(data.len() as u64),"
    if source.count(old_length) != len(variants):
        raise ValueError("media protobuf length expressions changed upstream")
    source = source.replace(old_length, "file_length: Some(media_len as u64),")
    if "data.clone()," in source:
        raise ValueError("additional cloned media payloads need manual audit")
    path.write_text(source)


def extract_event_summary(root: Path) -> None:
    path = root / "src/console/handlers.rs"
    content = path.read_text()
    begin_marker = "pub fn summarize_event(event: &str, payload: &str) -> String {"
    end_marker = "\n}\n\nasync fn build_overview_data("
    if content.count(begin_marker) != 1 or content.count(end_marker) != 1:
        raise ValueError("console summary: upstream drift")
    start = content.index(begin_marker)
    end = content.index(end_marker, start) + len("\n}")
    original = content[start:end]
    # One shared summary implementation for the core and the optional console.
    event_summary = (
        "//! Shared, redacted event summaries for session diagnostics and console.\n"
        "//! Never leak pairing secrets or raw media contents into summaries.\n\n"
        + original + "\n\n"
        + """#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn qr_secret_is_never_echoed() {
        let payload = r#"{"data":{"secret":"do-not-log","code":"secret"}}"#;
        assert_eq!(summarize_event("qr_code", payload), "new QR ready to scan");
        assert!(!summarize_event("qr_code", payload).contains("do-not-log"));
    }

    #[test]
    fn receipt_summary_keeps_type() {
        let payload = r#"{"data":{"type":"delivered"}}"#;
        assert_eq!(summarize_event("receipt", payload), "delivered");
    }
}
"""
    )
    target = root / "src/event_summary.rs"
    if target.exists():
        raise ValueError("src/event_summary.rs already exists")
    target.write_text(event_summary)
    rewritten = content[:start] + content[end:]
    # The browser console still uses exactly the same summary function.
    anchor = "use crate::state::AppState;"
    rewritten = replace_exact(
        rewritten, anchor, anchor + "\nuse crate::event_summary::summarize_event;",
        context="console summary import",
    )
    path.write_text(rewritten)


def gate_main_router(root: Path) -> None:
    path = root / "src/main.rs"
    content = path.read_text()
    content = replace_exact(
        content, "use utoipa::OpenApi;\nuse utoipa_swagger_ui::SwaggerUi;",
        '#[cfg(feature = "swagger-ui")]\nuse utoipa::OpenApi;\n#[cfg(feature = "swagger-ui")]\nuse utoipa_swagger_ui::SwaggerUi;',
        context="optional Swagger imports",
    )
    content = replace_exact(
        content, "use waxum::console;",
        '#[cfg(feature = "embedded-console")]\nuse waxum::console;',
        context="optional console import",
    )
    content = replace_exact(
        content, "use waxum::api::openapi::ApiDoc;",
        '#[cfg(feature = "swagger-ui")]\nuse waxum::api::openapi::ApiDoc;',
        context="optional OpenAPI import",
    )
    old_router = """    let mut openapi = ApiDoc::openapi();
    if let Ok(public_base_url) = std::env::var("PUBLIC_BASE_URL") {
        openapi.servers = Some(vec![utoipa::openapi::Server::new(
            public_base_url.trim_end_matches('/'),
        )]);
    }
    let swagger_router: axum::Router<AppState> = SwaggerUi::new("/swagger-ui")
        .url("/api-docs/openapi.json", openapi)
        .into();

    let mut app = create_router()
        .merge(swagger_router)
        .merge(console::console_router())
        .layer(axum::middleware::from_fn_with_state("""
    new_router = """    let mut app = create_router();
    #[cfg(feature = "swagger-ui")]
    {
        let mut openapi = ApiDoc::openapi();
        if let Ok(public_base_url) = std::env::var("PUBLIC_BASE_URL") {
            openapi.servers = Some(vec![utoipa::openapi::Server::new(
                public_base_url.trim_end_matches('/'),
            )]);
        }
        let swagger_router: axum::Router<AppState> = SwaggerUi::new("/swagger-ui")
            .url("/api-docs/openapi.json", openapi)
            .into();
        app = app.merge(swagger_router);
    }
    #[cfg(feature = "embedded-console")]
    {
        app = app.merge(console::console_router());
    }
    let mut app = app
        .layer(axum::middleware::from_fn_with_state("""
    content = replace_exact(content, old_router, new_router, context="optional router composition")
    # Do not advertise a Swagger endpoint when the Swagger router is absent.
    matches = list(re.finditer(
        r'    println!\(\n        "[^\n]*Swagger:[^\n]*\n        addr\n    \);\n',
        content,
    ))
    if len(matches) != 1:
        raise ValueError(f"Swagger startup display: expected one match, found {len(matches)}")
    m = matches[0]
    content = content[:m.start()] + '    #[cfg(feature = "swagger-ui")]\n' + content[m.start():]
    path.write_text(content)


def apply(root: Path) -> None:
    if not (root / "Cargo.toml").is_file():
        raise ValueError("not a Waxum source directory")

    manifest = (root / "Cargo.toml").read_text()
    if 'version = "0.16.1"' not in manifest:
        raise ValueError("unsupported upstream Waxum version")

    replace_file(root, "Cargo.toml", "[dependencies]", """[features]
# Preserve upstream behavior when compiling without an explicit profile.
default = ["embedded-console", "swagger-ui"]
embedded-console = ["dep:handlebars", "dep:qrcode"]
swagger-ui = ["dep:utoipa-swagger-ui"]

[dependencies]""")
    replace_file(
        root, "Cargo.toml",
        'utoipa-swagger-ui = { version = "9", features = ["axum"] }',
        'utoipa-swagger-ui = { version = "9", features = ["axum"], optional = true }',
    )
    replace_file(root, "Cargo.toml", 'handlebars = "6"', 'handlebars = { version = "6", optional = true }')
    replace_file(
        root, "Cargo.toml",
        'qrcode = { version = "0.14", default-features = false, features = ["svg"] }',
        'qrcode = { version = "0.14", default-features = false, features = ["svg"], optional = true }',
    )

    extract_event_summary(root)
    replace_file(
        root, "src/lib.rs", "pub mod console;\n",
        '#[cfg(feature = "embedded-console")]\npub mod console;\npub mod event_summary;\n',
    )
    replace_file(
        root, "src/state.rs",
        "crate::console::handlers::summarize_event(event, payload)",
        "crate::event_summary::summarize_event(event, payload)",
    )
    replace_file(
        root, "src/api/mod.rs", "pub mod openapi;",
        '#[cfg(feature = "swagger-ui")]\npub mod openapi;',
    )
    replace_file(
        root, "src/middleware/jwt.rs",
        "    if crate::console::is_console_path(path) {",
        '    #[cfg(feature = "embedded-console")]\n    if crate::console::is_console_path(path) {',
    )
    gate_main_router(root)
    optimize_media_send_buffers(root)

    for path in REUSABLE_LIVE_LOOKUP_PATHS:
        replace_file(root, path, LIVE_LOOKUP, DELEGATED_LOOKUP)

    # A post-condition: the disabled console must not be referenced in core
    # state or auth without a matching compilation condition.
    assert "crate::console::handlers::summarize_event" not in (root / "src/state.rs").read_text()
    assert "pub mod event_summary;" in (root / "src/lib.rs").read_text()
    assert 'default = ["embedded-console", "swagger-ui"]' in (root / "Cargo.toml").read_text()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path)
    args = parser.parse_args()
    apply(args.source)
    print("Waxum Core v1 headless/reuse source patches applied; upstream drift checks passed.")


if __name__ == "__main__":
    main()
