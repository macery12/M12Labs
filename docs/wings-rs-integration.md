# Wings-RS Integration

This document describes the Wings-RS (Supercharged) integration for the M12Labs panel.

## Overview

Wings-RS is a Rust-based alternative daemon that provides enhanced features compared to the standard Pterodactyl Wings daemon. When a node is running Wings-RS, the panel automatically detects it and unlocks supercharged features.

## Architecture

### Detection Flow

1. The panel calls `GET /api/system` on the node
2. If the response contains `"supercharged": true` or a Wings-RS version string, the node is detected as supercharged
3. Node record is updated with `wings_type = 'wings-rs'`, version, and detection timestamp
4. All Wings-RS exclusive features become available in the admin and client UIs

### Backend Components

| File | Purpose |
|------|---------|
| `app/Models/Node.php` | Updated with `WINGS_TYPE_RS`, `WINGS_TYPE_DEFAULT` constants, `isSupercharged()` method |
| `app/Services/Nodes/WingsDetectionService.php` | Detects Wings-RS nodes and fetches system overview |
| `app/Repositories/Wings/DaemonWingsRsRepository.php` | Repository for all Wings-RS exclusive API endpoints |
| `app/Http/Controllers/Api/Application/Nodes/NodeWingsRsController.php` | Admin API for node management |
| `app/Http/Controllers/Api/Client/Servers/WingsRsController.php` | Client API for server-level features |
| `database/migrations/2026_02_28_000001_add_wings_rs_columns_to_nodes.php` | Database migration |

### Frontend Components

| File | Purpose |
|------|---------|
| `resources/scripts/api/routes/admin/nodes/wingsRs.ts` | Admin API functions |
| `resources/scripts/api/routes/server/wingsRs.ts` | Client API functions |
| `resources/scripts/components/admin/management/nodes/NodeWingsRsContainer.tsx` | Admin Wings-RS tab page |
| `resources/scripts/components/admin/management/nodes/NodeStatsContainer.tsx` | Real-time system stats |
| `resources/scripts/components/admin/management/nodes/NodeLogsContainer.tsx` | System log viewer |
| `resources/scripts/components/server/wingsrs/WingsRsContainer.tsx` | Server-level Wings-RS features |
| `resources/scripts/components/server/files/CompressFormatDialog.tsx` | Advanced compression with format selection |
| `resources/scripts/components/server/files/FileSearchDialog.tsx` | Advanced file search (glob/regex) |
| `resources/scripts/components/server/files/FileFingerprintDialog.tsx` | File checksum generation |
| `resources/scripts/components/server/files/SshInfoPanel.tsx` | SSH access guidance |

## API Endpoints

### Application API (Admin)

All endpoints under `/api/application/nodes/{node}/wings-rs/`:

| Method | Path | Description |
|--------|------|-------------|
| POST | `/detect` | Detect if node is running Wings-RS |
| GET | `/overview` | Get Wings-RS system overview (version, features, uptime) |
| GET | `/stats` | Get real-time system stats (CPU, memory, disk, network) |
| GET | `/logs` | List available log files |
| GET | `/logs/{file}` | Get contents of a specific log file |
| POST | `/upgrade` | Trigger Wings-RS self-upgrade |

### Client API (Server)

All endpoints under `/api/client/servers/{server}/wings-rs/`:

| Method | Path | Description | Permission |
|--------|------|-------------|-----------|
| GET | `/status` | Get supercharged status and features | - |
| POST | `/fingerprints` | Compute file checksums | `file.read` |
| POST | `/search` | Advanced file search (glob/regex) | `file.read` |
| POST | `/compress` | Compress with format selection | `file.archive` |
| DELETE | `/operations/{operation}` | Cancel an ongoing operation | `file.update` |
| POST | `/script` | Execute a shell script | `startup.update` |
| POST | `/abort-install` | Abort ongoing installation | `settings.reinstall` |
| GET | `/install-logs` | View installation logs | `control.console` |
| GET | `/ssh` | Get SSH connection details | `file.sftp` |

## Supported Archive Formats

Wings-RS supports these archive formats for compression:

- `.tar` — Uncompressed tar
- `.tar.gz` — Gzip compressed tar (default)
- `.tar.xz` — XZ compressed tar
- `.tar.bz2` — Bzip2 compressed tar
- `.tar.lz4` — LZ4 compressed tar (fastest)
- `.tar.zst` — Zstandard compressed tar
- `.zip` — ZIP archive
- `.7z` — 7-Zip archive

## Fingerprint Algorithms

Supported hash algorithms for file checksums:

- SHA-256 (default)
- SHA-1
- MD5
- BLAKE3

## Graceful Fallback

All Wings-RS features are conditionally enabled:

- **Backend**: Every Wings-RS controller method validates `$server->node->isSupercharged()` and returns HTTP 400 if the node is not supercharged
- **Admin UI**: The Wings-RS tab appears for all nodes but shows a detection button for non-RS nodes
- **Client UI**: The Wings-RS sidebar tab only appears when `isNodeSupercharged` is true on the server
- **File Manager**: Advanced compress, search, and checksum features only appear for supercharged nodes

Standard Wings nodes continue to work exactly as before with zero impact.

## Database Changes

The migration adds three columns to the `nodes` table:

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `wings_type` | string | `'default'` | Either `'default'` or `'wings-rs'` |
| `wings_version` | string (nullable) | null | The Wings-RS version string |
| `wings_detected_at` | timestamp (nullable) | null | When Wings-RS was last detected |

## Running Tests

```bash
php artisan test --filter=WingsDetection
php artisan test --filter=WingsRsController
```

## Security Considerations

- All admin endpoints require application API key authentication
- All client endpoints require user authentication and appropriate permissions
- The `assertSupercharged()` method in `DaemonWingsRsRepository` prevents calls to Wings-RS endpoints on standard nodes
- Script execution requires `startup.update` permission
- Install abort requires `settings.reinstall` permission
- File operations respect existing permission scopes (file.read, file.archive, etc.)
