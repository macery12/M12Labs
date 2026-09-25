import http from '@/lib/http';

// Client file-manager endpoints (/api/client/servers/{uuid}/files/*), ported
// from V1's routes/server/{files,directories}.ts. All paths are relative to the
// server root; `directory`/`root` is the current folder, `file` the full path.

export interface FileObject {
    key: string;
    name: string;
    mode: string;
    modeBits: string;
    size: number;
    isFile: boolean;
    isSymlink: boolean;
    mimetype: string;
    createdAt: Date;
    modifiedAt: Date;
}

// Archive extensions/mimetypes — used to gate the "editable" and "archive"
// affordances (mirrors V1's FileObject.isArchiveType / isEditable helpers).
const ARCHIVE_EXTENSIONS = [
    '.zip', '.7z', '.ddup', '.rar', '.tar', '.tar.gz', '.tgz', '.tar.bz2', '.tbz2',
    '.tar.xz', '.txz', '.tar.zst', '.tzst', '.tar.lz4', '.tlz4', '.tar.br',
    '.gz', '.xz', '.zst', '.lz4', '.bz2',
];
const ARCHIVE_MIMETYPES = [
    'application/vnd.rar', 'application/x-rar-compressed', 'application/x-tar', 'application/x-br',
    'application/x-bzip2', 'application/gzip', 'application/x-gzip', 'application/x-lzip',
    'application/x-sz', 'application/x-xz', 'application/zstd', 'application/zip',
    'application/x-zip-compressed', 'application/x-7z-compressed',
];

export function isArchive(file: FileObject): boolean {
    if (!file.isFile) return false;
    const lower = file.name.toLowerCase();
    return ARCHIVE_EXTENSIONS.some(ext => lower.endsWith(ext)) || ARCHIVE_MIMETYPES.includes(file.mimetype);
}

export function isEditable(file: FileObject): boolean {
    if (!file.isFile || file.isSymlink) return false;
    return !isArchive(file);
}

// Archives the daemon can list *into* as if they were folders (wings-rs only).
// The daemon walks these transparently via the normal /files/list endpoint, so
// browsing is a pure navigation concern — the same "download instead" fallback
// applies to every other archive type and to plain Go daemons.
const VIRTUAL_ARCHIVE_EXTENSIONS = ['.zip', '.7z', '.ddup'];

export function isVirtualArchive(file: FileObject): boolean {
    if (!file.isFile) return false;
    const lower = file.name.toLowerCase();
    return VIRTUAL_ARCHIVE_EXTENSIONS.some(ext => lower.endsWith(ext));
}

// When the daemon lists inside an archive, the archive's filename stays in the
// path (e.g. /backups/world.zip/region). Returns the first such segment so the
// UI can warn that everything below it is read-only until extracted.
export function archivePathSegment(directory: string): string | null {
    const segments = directory.split('/').filter(Boolean);
    const seg = segments.find(s => {
        const lower = s.toLowerCase();
        return VIRTUAL_ARCHIVE_EXTENSIONS.some(ext => lower.endsWith(ext));
    });
    return seg ?? null;
}

interface FractalFile {
    attributes: {
        name: string;
        mode: string;
        mode_bits: string;
        size: number | string;
        is_file: boolean;
        is_symlink: boolean;
        mimetype: string;
        created_at: string;
        modified_at: string;
    };
}

function toFileObject({ attributes: a }: FractalFile): FileObject {
    return {
        key: `${a.is_file ? 'file' : 'dir'}_${a.name}`,
        name: a.name,
        mode: a.mode,
        modeBits: a.mode_bits,
        size: Number(a.size),
        isFile: a.is_file,
        isSymlink: a.is_symlink,
        mimetype: a.mimetype,
        createdAt: new Date(a.created_at),
        modifiedAt: new Date(a.modified_at),
    };
}

export async function loadDirectory(uuid: string, directory: string): Promise<FileObject[]> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/list`, {
        params: { directory: directory || '/' },
    });
    return (data.data ?? []).map(toFileObject);
}

export async function createDirectory(uuid: string, root: string, name: string): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/files/create-folder`, { root, name });
}

export async function renameFiles(
    uuid: string,
    root: string,
    files: { from: string; to: string }[],
): Promise<void> {
    await http.put(`/api/client/servers/${uuid}/files/rename`, { root, files });
}

export async function copyFile(uuid: string, location: string): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/files/copy`, { location });
}

// chmod goes through the shared daemon endpoint (`file.update`), so it works on
// both the Go daemon and wings-rs. `mode` is an octal string like "755".
export async function chmodFiles(
    uuid: string,
    root: string,
    files: { file: string; mode: string }[],
): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/files/chmod`, { root, files });
}

export async function deleteFiles(uuid: string, root: string, files: string[]): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/files/delete`, { root, files });
}

// Compress/extract go through the shared daemon endpoints, so a single action
// works against both the Go daemon and wings-rs. Format selection is wings-rs
// only and lives in `compressAdvanced` below.
export async function compressFiles(uuid: string, root: string, files: string[]): Promise<FileObject> {
    const { data } = await http.post(
        `/api/client/servers/${uuid}/files/compress`,
        { root, files },
        { timeout: 15000 },
    );
    return toFileObject(data);
}

export async function decompressFile(uuid: string, root: string, file: string): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/files/decompress`, { root, file }, { timeout: 15000 });
}

// Strips the archive extension so "Extract & open" knows where the daemon put
// the contents. Mirrors V1's getArchiveExtractedDirectory.
export function archiveContentsDirectory(name: string): string {
    const lower = name.toLowerCase();
    const extensions = [
        '.tar.gz', '.tar.xz', '.tar.bz2', '.tar.lz4', '.tar.zst', '.tar.zstd', '.tar.lzip', '.tar.br',
        '.tgz', '.txz', '.tbz2', '.tlz4', '.tzst', '.zip', '.7z', '.rar', '.ddup', '.tar',
    ];
    for (const ext of extensions) {
        if (lower.endsWith(ext)) return name.slice(0, name.length - ext.length);
    }
    return name;
}

export async function getFileContents(
    uuid: string,
    file: string,
    options?: { signal?: AbortSignal },
): Promise<string> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/contents`, {
        params: { file },
        transformResponse: res => res,
        responseType: 'text',
        headers: { Accept: 'text/plain' },
        signal: options?.signal,
    });
    return data;
}

// sha256 hex of a string, or null when the platform cannot produce one.
// SubtleCrypto is exposed only in secure contexts, and a self-hosted panel is
// commonly reached over plain http on a LAN address — so this has to be allowed
// to fail, with the caller falling back to sending the whole original.
async function sha256Hex(text: string): Promise<string | null> {
    if (!globalThis.crypto?.subtle) return null;
    try {
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
        return Array.from(new Uint8Array(digest))
            .map(byte => byte.toString(16).padStart(2, '0'))
            .join('');
    } catch {
        return null;
    }
}

export async function saveFileContents(
    uuid: string,
    file: string,
    content: string,
    originalContent?: string,
): Promise<void> {
    if (originalContent === undefined) {
        // No previous version to compare against — a new file.
        await http.post(`/api/client/servers/${uuid}/files/write`, content, {
            params: { file },
            headers: { 'Content-Type': 'text/plain' },
        });
        return;
    }

    // The original is only a compare-and-swap token: the server hashes the live
    // file and checks it against this. Sending the digest instead of a second
    // full copy keeps a ceiling-sized file inside the request body cap.
    const originalHash = await sha256Hex(originalContent);

    await http.post(`/api/client/servers/${uuid}/files/write-with-diff`, {
        file,
        content,
        ...(originalHash === null ? { original_content: originalContent } : { original_hash: originalHash }),
    });
}

// Does a path already exist in `directory`? Used to warn before a new file
// silently overwrites something, since the daemon's write has no
// exclusive-create mode to lean on.
export async function fileExists(uuid: string, directory: string, name: string): Promise<boolean> {
    try {
        const entries = await loadDirectory(uuid, directory);
        return entries.some(entry => entry.name === name);
    } catch {
        // A listing failure must not block the save; the confirm step is a
        // courtesy, not a guarantee.
        return false;
    }
}

export async function getFileDownloadUrl(uuid: string, file: string): Promise<string> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/download`, { params: { file } });
    return data.attributes.url;
}

// Streams a whole directory as an archive (tar.gz by default). The daemon builds
// the archive on the fly, so nothing is written to the server — unlike compress.
export async function getDirectoryDownloadUrl(
    uuid: string,
    directory: string,
    archiveFormat = 'tar_gz',
): Promise<string> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/download-directory`, {
        params: { file: directory, archive_format: archiveFormat },
    });
    return data.attributes.url;
}

// Asks the daemon to fetch a remote file straight into the server (file.create).
// `directory` is where it lands; `filename` overrides the name derived from the
// URL. Both daemons implement /files/pull.
export async function pullFile(
    uuid: string,
    params: { url: string; directory: string; filename?: string; useHeader?: boolean },
): Promise<string | null> {
    const { data } = await http.post(`/api/client/servers/${uuid}/files/pull`, {
        url: params.url,
        directory: params.directory,
        filename: params.filename || undefined,
        use_header: params.useHeader ?? true,
        foreground: false,
    });
    // Present for a background pull, absent on older daemons.
    return data?.attributes?.identifier ?? null;
}

// ── Remote pull progress ────────────────────────────────────────────────────
// A background pull is only acknowledged, so its identifier is followed up with
// the status endpoint below. Both legs stay inside the authenticated client API
// — the browser never addresses the daemon directly.
export interface FilePull {
    identifier: string;
    destination: string;
    progress: number;
    total: number;
}

export async function listPulls(uuid: string): Promise<FilePull[]> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/pull`);
    const rows: { attributes: FilePull }[] = data?.data ?? [];
    return rows.map(({ attributes }) => ({
        identifier: attributes.identifier,
        destination: attributes.destination,
        progress: Number(attributes.progress ?? 0),
        total: Number(attributes.total ?? 0),
    }));
}

export async function cancelPull(uuid: string, identifier: string): Promise<void> {
    await http.delete(`/api/client/servers/${uuid}/files/pull/${identifier}`);
}

export async function getFileUploadUrl(uuid: string): Promise<string> {
    const { data } = await http.get(`/api/client/servers/${uuid}/files/upload`);
    return data.attributes.url;
}

// ── Wings-RS (Supercharged nodes only) ──────────────────────────────────────
export interface SearchResult {
    path: string;
    name: string;
    size: number;
    modified: string;
    is_file: boolean;
    mime_type?: string;
}

export async function searchFiles(
    uuid: string,
    params: { root?: string; pattern: string; glob?: boolean; regex?: boolean; case_sensitive?: boolean },
): Promise<SearchResult[]> {
    const { data } = await http.post(`/api/client/servers/${uuid}/wings-rs/search`, params);
    return Array.isArray(data) ? data : [];
}

// Archive formats wings-rs can produce. The Go daemon has no format selection,
// so the picker is gated behind `isNodeSupercharged`.
export type ArchiveFormat =
    | 'tar'
    | 'tar_gz'
    | 'tar_xz'
    | 'tar_lzip'
    | 'tar_bz2'
    | 'tar_lz4'
    | 'tar_zstd'
    | 'zip'
    | 'seven_zip';

export async function compressAdvanced(
    uuid: string,
    params: { root: string; files: string[]; format: ArchiveFormat; name?: string },
): Promise<void> {
    await http.post(`/api/client/servers/${uuid}/wings-rs/compress`, params, { timeout: 15000 });
}

// ── Checksums (wings-rs only) ────────────────────────────────────────────────
// Algorithms the daemon's fingerprints endpoint accepts (its Algorithm enum).
export type FingerprintAlgorithm =
    | 'md5'
    | 'crc32'
    | 'sha1'
    | 'sha224'
    | 'sha256'
    | 'sha384'
    | 'sha512'
    | 'curseforge';

export interface FileFingerprint {
    path: string;
    algorithm: FingerprintAlgorithm;
    hash: string;
}

export async function getFingerprints(
    uuid: string,
    files: string[],
    algorithm: FingerprintAlgorithm,
): Promise<FileFingerprint[]> {
    const { data } = await http.post(`/api/client/servers/${uuid}/wings-rs/fingerprints`, {
        files,
        algorithm,
    });
    // The daemon answers with { fingerprints: { "<path>": "<hash>" } } — a map
    // keyed by the exact path string we sent. Fall back to a bare map if the
    // wrapper is ever dropped. Skip empty hashes (files it couldn't read).
    const map = (data?.fingerprints ?? data) as Record<string, unknown> | null;
    if (!map || typeof map !== 'object') return [];
    return Object.entries(map)
        .filter(([, hash]) => hash != null && hash !== '')
        .map(([path, hash]): FileFingerprint => ({ path, algorithm, hash: String(hash) }));
}

export interface SshInfo {
    host: string;
    port: number;
    username: string;
    command?: string;
    containerSupported: boolean;
}

export async function getSshInfo(uuid: string): Promise<SshInfo> {
    const { data } = await http.get(`/api/client/servers/${uuid}/wings-rs/ssh`);
    return {
        host: data?.host ?? data?.ip ?? '',
        port: Number(data?.port ?? 22),
        username: data?.username ?? '',
        command: data?.command,
        containerSupported: Boolean(data?.container_supported ?? data?.shell_available ?? false),
    };
}
