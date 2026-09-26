import { describe, expect, it } from 'vitest';
import { isUnreachableHost, joinableAddress } from './address';

describe('joinableAddress', () => {
    const fqdn = 'play.example.com';

    it('uses the alias first', () => {
        expect(joinableAddress({ ip: '0.0.0.0', port: 25565, alias: 'mc.example.com' }, fqdn)).toBe('mc.example.com:25565');
    });

    it('never shows a wildcard bind to players; the node FQDN stands in', () => {
        expect(joinableAddress({ ip: '0.0.0.0', port: 35001 }, fqdn)).toBe('play.example.com:35001');
        expect(joinableAddress({ ip: '::', port: 35001 }, fqdn)).toBe('play.example.com:35001');
    });

    it('replaces a LAN address behind NAT with the FQDN', () => {
        expect(joinableAddress({ ip: '192.168.1.122', port: 25565 }, fqdn)).toBe('play.example.com:25565');
        expect(joinableAddress({ ip: '10.0.0.5', port: 25565 }, fqdn)).toBe('play.example.com:25565');
    });

    it('keeps a specific public IP, which is more precise than the node name', () => {
        expect(joinableAddress({ ip: '203.0.113.7', port: 25565 }, fqdn)).toBe('203.0.113.7:25565');
    });

    it('falls back to a private IP only when there is no FQDN', () => {
        expect(joinableAddress({ ip: '192.168.1.122', port: 25565 }, null)).toBe('192.168.1.122:25565');
    });

    it('returns null rather than 0.0.0.0 when nothing is reachable', () => {
        expect(joinableAddress({ ip: '0.0.0.0', port: 25565 }, null)).toBeNull();
        expect(joinableAddress({ ip: '0.0.0.0', port: 25565 }, '0.0.0.0')).toBeNull();
    });

    it('brackets IPv6 literals', () => {
        expect(joinableAddress({ ip: '2001:db8::1', port: 25565 }, fqdn)).toBe('[2001:db8::1]:25565');
    });
});

describe('isUnreachableHost', () => {
    it('knows private, loopback, link-local and CGNAT ranges', () => {
        for (const host of ['0.0.0.0', '127.0.0.1', '172.16.0.1', '172.31.255.255', '169.254.1.1', '100.64.0.1', '::1', 'fd00::1', 'fe80::1', 'localhost']) {
            expect(isUnreachableHost(host), host).toBe(true);
        }
    });

    it('leaves public addresses and hostnames alone', () => {
        for (const host of ['203.0.113.7', '172.32.0.1', '100.128.0.1', '2001:db8::1', 'fdn.example.com']) {
            expect(isUnreachableHost(host), host).toBe(false);
        }
    });
});
