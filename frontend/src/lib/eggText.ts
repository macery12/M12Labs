// Egg variable descriptions are written by egg authors for a generic panel,
// and some end by saying how to apply a change ("Go to Settings > Reinstall
// Server to apply."). Before the server exists (checkout) that's noise, and on
// the Startup page the version card has its own Change version button, which
// reinstalls. Both drop any sentence about reinstalling and keep the rest.
export function withoutReinstallHint(description: string): string {
    return description
        .split(/(?<=[.!?])\s+/)
        .filter(sentence => !/\breinstall/i.test(sentence))
        .join(' ')
        .trim();
}
