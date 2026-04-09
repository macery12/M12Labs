#!/usr/bin/env python3
"""
Generate a SHA-256 file hash manifest for the repository.

Writes release-manifest.json at the repo root with:
  - generated_at  : ISO-8601 UTC timestamp
  - algorithm     : "sha256"
  - files         : mapping of repo-relative path -> hex digest

Paths are sorted for stable diffs.  The script itself and the manifest
output file are excluded from the manifest so re-running is idempotent.

Usage:
    python3 scripts/generate-manifest.py [REPO_ROOT]

REPO_ROOT defaults to the directory that contains this script's parent.
"""

import hashlib
import json
import os
import sys
from datetime import datetime, timezone

MANIFEST_FILENAME = "release-manifest.json"
ALGORITHM = "sha256"

# Paths (relative to repo root) that should never appear in the manifest.
EXCLUDED_PATHS = {
    MANIFEST_FILENAME,
    "scripts/generate-manifest.py",
}

# Directory names that are never walked into.
EXCLUDED_DIRS = {
    ".git",
    "node_modules",
    "vendor",
    "storage",
    ".github",
}


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def collect_files(root: str) -> dict:
    file_hashes: dict[str, str] = {}
    for dirpath, dirnames, filenames in os.walk(root):
        # Prune excluded directories in-place so os.walk skips them.
        dirnames[:] = sorted(
            d for d in dirnames if d not in EXCLUDED_DIRS and not d.startswith(".")
        )
        for filename in sorted(filenames):
            if filename.startswith("."):
                continue
            abs_path = os.path.join(dirpath, filename)
            rel_path = os.path.relpath(abs_path, root).replace(os.sep, "/")
            if rel_path in EXCLUDED_PATHS:
                continue
            file_hashes[rel_path] = sha256_file(abs_path)
    return file_hashes


def main() -> None:
    if len(sys.argv) > 1:
        repo_root = os.path.abspath(sys.argv[1])
    else:
        repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

    if not os.path.isdir(repo_root):
        print(f"error: {repo_root} is not a directory", file=sys.stderr)
        sys.exit(1)

    files = collect_files(repo_root)

    manifest = {
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "algorithm": ALGORITHM,
        "files": files,
    }

    output_path = os.path.join(repo_root, MANIFEST_FILENAME)
    with open(output_path, "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, indent=2, ensure_ascii=False)
        fh.write("\n")

    print(f"Manifest written to {output_path} ({len(files)} files)")


if __name__ == "__main__":
    main()
