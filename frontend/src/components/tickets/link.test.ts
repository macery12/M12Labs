import { describe, expect, it } from 'vitest';
import { newTicketLink, readTicketPrefill } from './link';

describe('newTicketLink', () => {
    it('round-trips through the Tickets page parameters', () => {
        const link = newTicketLink({ title: 'Add a database to my plan', message: 'Server: Paper & co', serverId: 7 });
        const params = new URL(link, 'https://panel.test').searchParams;
        expect(readTicketPrefill(params)).toEqual({ title: 'Add a database to my plan', message: 'Server: Paper & co', serverId: 7 });
    });

    it('ignores pages opened without the new flag, and junk server ids', () => {
        expect(readTicketPrefill(new URLSearchParams('title=x'))).toBeNull();
        expect(readTicketPrefill(new URLSearchParams('new=1&title=x&server=abc'))).toEqual({ title: 'x', message: undefined, serverId: undefined });
    });
});
