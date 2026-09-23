import type {
    ExtensionSettingField,
    ExtensionVisibilityCondition,
    ExtensionVisibilityPredicate,
} from '@/api/extensions';

// Whether a manifest field or credential should be shown, given the settings
// form as it stands right now (unsaved edits included).
//
// Mirrors `ExtensionFrontendFlagService::predicate()` on the server, so a
// `visibleWhen` and a flag written with the same predicate agree. It is
// presentation only: a hidden field is not cleared and still saves.

function predicatePasses(
    predicate: ExtensionVisibilityPredicate,
    schema: Map<string, ExtensionSettingField>,
    settings: Record<string, unknown>,
): boolean {
    const field = schema.get(predicate.setting);
    // The condition names a field the drawer does not render (internal, or
    // dropped by an update). Show the dependent rather than hide it forever.
    if (!field) return true;

    const present = Object.prototype.hasOwnProperty.call(settings, predicate.setting);
    const value = present ? settings[predicate.setting] : field.default;

    if (predicate.configured !== undefined) {
        const configured = (present || field.default != null) && value != null && value !== '';

        return configured === predicate.configured;
    }

    switch (field.type) {
        case 'boolean':
            return (value === true || value === 1 || value === '1' || value === 'true') === predicate.equals;
        case 'number':
            return value !== '' && value != null && Number(value) === Number(predicate.equals);
        default:
            return typeof value === 'string' && value === predicate.equals;
    }
}

export function isVisible(
    condition: ExtensionVisibilityCondition | null | undefined,
    schema: ExtensionSettingField[],
    settings: Record<string, unknown>,
): boolean {
    if (!condition) return true;

    const byKey = new Map(schema.map(field => [field.key, field]));
    const all = condition.all ?? [];
    const any = condition.any ?? [];

    if (!all.every(predicate => predicatePasses(predicate, byKey, settings))) return false;

    return any.length === 0 || any.some(predicate => predicatePasses(predicate, byKey, settings));
}
