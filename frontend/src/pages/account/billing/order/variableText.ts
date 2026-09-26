// Egg variable descriptions are written for a server's Startup page, and some
// end by saying how to apply a change there ("Go to Settings > Reinstall Server
// to apply."). Before the server exists that's noise, so checkout drops any
// sentence about reinstalling and keeps the rest.
export function checkoutDescription(description: string): string {
    return description
        .split(/(?<=[.!?])\s+/)
        .filter(sentence => !/\breinstall/i.test(sentence))
        .join(' ')
        .trim();
}
