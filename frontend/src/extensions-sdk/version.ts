// Versioned contract between the panel and installed extension packages.
//
// Bump the MAJOR when an existing export changes shape or is removed; a package
// built against an older major must be rejected rather than silently broken.
// Bump the MINOR when adding a new export. Packages declare the major they were
// built against; the installer compares it against this value.
export const SDK_VERSION = '1.5.0';
export const SDK_MAJOR = 1;
