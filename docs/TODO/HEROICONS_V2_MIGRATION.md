# Heroicons v2 Migration Guide

Migration from `@heroicons/react` v1 (1.0.6) to v2.

---

## What changes in v2

| Change | Detail |
|---|---|
| Import paths | `/outline` and `/solid` → `/24/outline` and `/24/solid` |
| New icon sizes | `/20/solid` (20px filled), `/16/solid` (16px filled) added |
| Icon renames | ~30 icons used in this repo were renamed |
| New icons | Many icons added; additive only |
| Package size | Significantly reduced — tree-shaking works correctly in v2 |

---

## Scope in this repo

- **78 files** import from `@heroicons/react/outline` or `@heroicons/react/solid`
- **55 unique icon names** are used
- **30 icon names changed** (see rename map below)
- **25 icon names are unchanged** — import path change only

---

## Phase 1 — Package change

```json
"@heroicons/react": "^2.0.0"
```

Run `corepack pnpm install --no-frozen-lockfile` after editing `package.json`.

---

## Phase 2 — Update import paths

All `@heroicons/react/outline` imports become `@heroicons/react/24/outline`.
All `@heroicons/react/solid` imports become `@heroicons/react/24/solid`.

This can be done with a single sed/find-replace pass:

```bash
# Dry run first (print matches, no changes)
grep -r "@heroicons/react/outline\|@heroicons/react/solid" resources/scripts --include="*.tsx" --include="*.ts" -l

# Replace import paths
find resources/scripts -type f \( -name "*.tsx" -o -name "*.ts" \) \
  -exec sed -i \
    -e "s|@heroicons/react/outline|@heroicons/react/24/outline|g" \
    -e "s|@heroicons/react/solid|@heroicons/react/24/solid|g" \
    {} +
```

---

## Phase 3 — Rename icons

Apply icon renames after the import path change. The table below covers every icon used in this
codebase that was renamed in v2.

### Icons used in this repo that were renamed

| v1 name (current) | v2 name (target) | Notes |
|---|---|---|
| `AdjustmentsIcon` | `AdjustmentsHorizontalIcon` | Vertical variant also added as `AdjustmentsVerticalIcon` |
| `ArrowsExpandIcon` | `ArrowsPointingOutIcon` | |
| `BanIcon` | `NoSymbolIcon` | |
| `CashIcon` | `BanknotesIcon` | |
| `ChartSquareBarIcon` | `ChartBarSquareIcon` | Word order reversed |
| `ChipIcon` | `CpuChipIcon` | |
| `ClipboardListIcon` | `ClipboardDocumentListIcon` | |
| `CloudDownloadIcon` | `CloudArrowDownIcon` | |
| `CloudUploadIcon` | `CloudArrowUpIcon` | |
| `CodeIcon` | `CodeBracketIcon` | |
| `CogIcon` | `Cog6ToothIcon` | A plain `CogIcon` (8-tooth) also exists |
| `DatabaseIcon` | `CircleStackIcon` | |
| `DesktopComputerIcon` | `ComputerDesktopIcon` | Word order reversed |
| `DownloadIcon` | `ArrowDownTrayIcon` | |
| `ExclamationIcon` | `ExclamationTriangleIcon` | |
| `ExternalLinkIcon` | `ArrowTopRightOnSquareIcon` | |
| `LightningBoltIcon` | `BoltIcon` | |
| `LogoutIcon` | `ArrowRightOnRectangleIcon` | |
| `OfficeBuildingIcon` | `BuildingOfficeIcon` | Word order reversed |
| `PencilAltIcon` | `PencilSquareIcon` | |
| `PuzzleIcon` | `PuzzlePieceIcon` | |
| `RefreshIcon` | `ArrowPathIcon` | |
| `ReplyIcon` | `ArrowUturnLeftIcon` | |
| `SearchIcon` | `MagnifyingGlassIcon` | |
| `TerminalIcon` | `CommandLineIcon` | |
| `ViewGridIcon` | `Squares2X2Icon` | |
| `WifiIcon` | `WifiIcon` | ✅ Unchanged — same name in v2 |
| `XIcon` | `XMarkIcon` | |

### Icons used in this repo that are unchanged

These only need the import path update (Phase 2) — no rename needed:

`CalendarIcon`, `CheckCircleIcon`, `CheckIcon`, `ChevronDoubleLeftIcon`,
`ChevronDoubleRightIcon`, `ChevronDownIcon`, `ChevronLeftIcon`, `ChevronRightIcon`,
`ClockIcon`, `CubeIcon`, `EyeIcon`, `ExclamationCircleIcon`, `FolderOpenIcon`, `HomeIcon`,
`InformationCircleIcon`, `PlayIcon`, `PlusIcon`, `ServerIcon`, `ShieldExclamationIcon`,
`ShoppingBagIcon`, `ShoppingCartIcon`, `SparklesIcon`, `StopIcon`, `TicketIcon`, `TrashIcon`,
`UserIcon`, `XCircleIcon`

---

## Phase 4 — Verification

```bash
# Build — tsc will error on any icon name that no longer exists in v2
corepack pnpm build

# If tsc reports "Module ... has no exported member 'XxxIcon'", that icon needs renaming
# Check the rename table above

corepack pnpm test
```

---

## Recommended approach

Given the volume (78 files), use a scripted approach:

1. Apply the import path sed replacement (Phase 2)
2. Apply renames one at a time using find+replace, starting with the most common icons
3. Build after each rename batch to confirm — tsc will error immediately on any missed icon
4. Final build pass should be clean

A single-pass script for all renames:

```bash
find resources/scripts -type f \( -name "*.tsx" -o -name "*.ts" \) -exec sed -i \
  -e 's/\bAdjustmentsIcon\b/AdjustmentsHorizontalIcon/g' \
  -e 's/\bArrowsExpandIcon\b/ArrowsPointingOutIcon/g' \
  -e 's/\bBanIcon\b/NoSymbolIcon/g' \
  -e 's/\bCashIcon\b/BanknotesIcon/g' \
  -e 's/\bChartSquareBarIcon\b/ChartBarSquareIcon/g' \
  -e 's/\bChipIcon\b/CpuChipIcon/g' \
  -e 's/\bClipboardListIcon\b/ClipboardDocumentListIcon/g' \
  -e 's/\bCloudDownloadIcon\b/CloudArrowDownIcon/g' \
  -e 's/\bCloudUploadIcon\b/CloudArrowUpIcon/g' \
  -e 's/\bCodeIcon\b/CodeBracketIcon/g' \
  -e 's/\bCogIcon\b/Cog6ToothIcon/g' \
  -e 's/\bDatabaseIcon\b/CircleStackIcon/g' \
  -e 's/\bDesktopComputerIcon\b/ComputerDesktopIcon/g' \
  -e 's/\bDownloadIcon\b/ArrowDownTrayIcon/g' \
  -e 's/\bExclamationIcon\b/ExclamationTriangleIcon/g' \
  -e 's/\bExternalLinkIcon\b/ArrowTopRightOnSquareIcon/g' \
  -e 's/\bLightningBoltIcon\b/BoltIcon/g' \
  -e 's/\bLogoutIcon\b/ArrowRightOnRectangleIcon/g' \
  -e 's/\bOfficeBuildingIcon\b/BuildingOfficeIcon/g' \
  -e 's/\bPencilAltIcon\b/PencilSquareIcon/g' \
  -e 's/\bPuzzleIcon\b/PuzzlePieceIcon/g' \
  -e 's/\bRefreshIcon\b/ArrowPathIcon/g' \
  -e 's/\bReplyIcon\b/ArrowUturnLeftIcon/g' \
  -e 's/\bSearchIcon\b/MagnifyingGlassIcon/g' \
  -e 's/\bTerminalIcon\b/CommandLineIcon/g' \
  -e 's/\bViewGridIcon\b/Squares2X2Icon/g' \
  -e 's/\bXIcon\b/XMarkIcon/g' \
  {} +
```

> **Warning:** The `sed` word-boundary (`\b`) expressions work on Linux (GNU sed). On macOS,
> use `gsed` (from Homebrew) instead of `sed`. The CI environment is Linux so the script above
> runs correctly in CI.

---

## Risk level

⚠️ **Medium.** The renames are mechanical and the build tool (tsc) will catch every missed one
as a type error. No runtime behaviour changes — icons are purely presentational SVGs.
