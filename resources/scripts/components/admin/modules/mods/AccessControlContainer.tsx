import { useEffect, useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import { getProviderRules, updateProviderRules, ProviderRulesResponse } from '@/api/routes/admin/plugins/providers';
import { useFlashKey } from '@/plugins/useFlash';

const providers = [
    { key: 'modrinth.mods', label: 'Modrinth Mods' },
    { key: 'curseforge', label: 'CurseForge (Mods + Modpacks)' },
    { key: 'spigot.plugins', label: 'Spigot Plugins' },
];

export default function AccessControlContainer() {
    const [data, setData] = useState<ProviderRulesResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('admin:plugins');

    useEffect(() => {
        clearFlashes();
        getProviderRules()
            .then(setData)
            .catch(clearAndAddHttpError)
            .finally(() => setLoading(false));
    }, []);

    const toggleNest = (providerKey: string, id: number) => {
        setData(current => {
            if (!current) return current;
            const rule = { ...current.rules[providerKey] };
            const list = new Set(rule.allowed_nest_ids ?? []);
            list.has(id) ? list.delete(id) : list.add(id);
            rule.allowed_nest_ids = Array.from(list);
            return { ...current, rules: { ...current.rules, [providerKey]: rule } };
        });
    };

    const toggleEgg = (providerKey: string, id: number) => {
        setData(current => {
            if (!current) return current;
            const rule = { ...current.rules[providerKey] };
            const list = new Set(rule.allowed_egg_ids ?? []);
            list.has(id) ? list.delete(id) : list.add(id);
            rule.allowed_egg_ids = Array.from(list);
            return { ...current, rules: { ...current.rules, [providerKey]: rule } };
        });
    };

    const toggleEnabled = (providerKey: string) => {
        setData(current => {
            if (!current) return current;
            const rule = { ...current.rules[providerKey] };
            rule.enabled_global = !rule.enabled_global;
            return { ...current, rules: { ...current.rules, [providerKey]: rule } };
        });
    };

    const handleSave = (providerKey: string) => {
        if (!data) return;
        const rule = data.rules[providerKey];
        updateProviderRules({
            provider_key: providerKey,
            enabled_global: rule.enabled_global,
            allowed_nest_ids: rule.allowed_nest_ids ?? [],
            allowed_egg_ids: rule.allowed_egg_ids ?? [],
        })
            .then(() => getProviderRules().then(setData))
            .catch(clearAndAddHttpError);
    };

    const handleReset = (providerKey: string) => {
        setData(current => {
            if (!current) return current;
            return {
                ...current,
                rules: {
                    ...current.rules,
                    [providerKey]: {
                        provider_key: providerKey,
                        enabled_global: false,
                        allowed_nest_ids: [],
                        allowed_egg_ids: [],
                    },
                },
            };
        });
    };

    if (loading || !data) return <Spinner size={'large'} centered />;

    return (
        <div className={'space-y-4'}>
            <Alert type={'info'}>
                Selecting eggs overrides nest rules. Default policy is deny-all unless explicitly allowed.
            </Alert>
            {providers.map(provider => {
                const rule = data.rules[provider.key] ?? {
                    provider_key: provider.key,
                    enabled_global: false,
                    allowed_nest_ids: [],
                    allowed_egg_ids: [],
                };
                return (
                    <AdminBox key={provider.key} title={provider.label}>
                        <div className={'flex flex-col gap-4'}>
                            <label className={'flex items-center gap-2'}>
                                <input
                                    type="checkbox"
                                    checked={rule.enabled_global}
                                    onChange={() => toggleEnabled(provider.key)}
                                />
                                <span>Enabled (global)</span>
                            </label>
                            <div className={'grid gap-4 lg:grid-cols-2'}>
                                <div>
                                    <div className={'font-semibold mb-2'}>Allowed Nests</div>
                                    <div className={'grid grid-cols-2 gap-2'}>
                                        {data.nests.map(nest => (
                                            <label key={nest.id} className={'flex items-center gap-2'}>
                                                <input
                                                    type="checkbox"
                                                    checked={(rule.allowed_nest_ids ?? []).includes(nest.id)}
                                                    onChange={() => toggleNest(provider.key, nest.id)}
                                                />
                                                <span>{nest.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                                <div>
                                    <div className={'font-semibold mb-2'}>Allowed Eggs</div>
                                    <div className={'grid grid-cols-2 gap-2'}>
                                        {data.nests.flatMap(nest => nest.eggs ?? []).map(egg => (
                                            <label key={egg.id} className={'flex items-center gap-2'}>
                                                <input
                                                    type="checkbox"
                                                    checked={(rule.allowed_egg_ids ?? []).includes(egg.id)}
                                                    onChange={() => toggleEgg(provider.key, egg.id)}
                                                />
                                                <span>{egg.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            </div>
                            <div className={'flex justify-end gap-2'}>
                                <Button.Text onClick={() => handleReset(provider.key)}>Clear / Reset</Button.Text>
                                <Button onClick={() => handleSave(provider.key)}>Save Changes</Button>
                            </div>
                        </div>
                    </AdminBox>
                );
            })}
        </div>
    );
}
