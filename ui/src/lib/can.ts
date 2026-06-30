// Dotted-permission matcher, ported from V1's usePermissions logic.
//   '*'            -> all permissions
//   'namespace.*'  -> any permission under that namespace
//   exact string   -> direct match
// `held` is the set of permission strings the user/subuser owns.
export function can(held: string[], required: string | string[] | undefined): boolean {
    if (!required || (Array.isArray(required) && required.length === 0)) return true;
    if (held.includes('*')) return true;

    const wanted = Array.isArray(required) ? required : [required];
    return wanted.some(permission => {
        if (held.includes(permission)) return true;
        const namespace = permission.split('.')[0];
        return held.includes(`${namespace}.*`);
    });
}
