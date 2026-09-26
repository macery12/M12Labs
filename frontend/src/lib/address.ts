export interface AllocationAddress {
    ip: string;
    port: number;
    alias?: string | null;
}

/**
 * The address a player types into their game client for an allocation.
 *
 * Allocations are usually bound to 0.0.0.0 (or a LAN address behind NAT), and
 * the panel used to print that bind address as the server's address, which no
 * player can connect to. Order: the allocation's alias, which an admin sets
 * precisely for this; then the bound IP when it's a real public address, since
 * on a multi-IP node that's more precise than the node's name; otherwise the
 * node's FQDN. Returns null when there is nothing a player could reach, rather
 * than showing 0.0.0.0.
 */
export function joinableAddress(allocation: AllocationAddress, nodeFqdn?: string | null): string | null {
    const host = joinableHost(allocation, nodeFqdn);
    return host === null ? null : `${host.includes(':') ? `[${host}]` : host}:${allocation.port}`;
}

export function joinableHost(allocation: AllocationAddress, nodeFqdn?: string | null): string | null {
    const alias = allocation.alias?.trim();
    if (alias) return alias;

    const ip = allocation.ip.trim();
    if (ip && !isUnreachableHost(ip)) return ip;

    const fqdn = nodeFqdn?.trim();
    if (fqdn && !isWildcard(fqdn)) return fqdn;

    return ip && !isWildcard(ip) ? ip : null;
}

function isWildcard(host: string): boolean {
    const h = stripBrackets(host);
    return h === '' || h === '0.0.0.0' || h === '::';
}

/** Wildcard, loopback, private, link-local or CGNAT: not something to hand a player. */
export function isUnreachableHost(host: string): boolean {
    const h = stripBrackets(host).toLowerCase();
    if (isWildcard(h) || h === 'localhost' || h === '::1') return true;

    const v4 = h.match(/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/);
    if (v4) {
        const a = Number(v4[1]);
        const b = Number(v4[2]);
        return (
            a === 10 ||
            a === 127 ||
            (a === 172 && b >= 16 && b <= 31) ||
            (a === 192 && b === 168) ||
            (a === 169 && b === 254) ||
            (a === 100 && b >= 64 && b <= 127)
        );
    }

    // IPv6 unique-local (fc00::/7) and link-local (fe80::/10). Only for literals:
    // a hostname may well start with "fd".
    if (h.includes(':')) return /^f[cd]/.test(h) || /^fe[89ab]/.test(h);

    return false;
}

function stripBrackets(host: string): string {
    return host.trim().replace(/^\[(.*)\]$/, '$1');
}
