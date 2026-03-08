import { useEffect, useMemo, useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import {
    getProviderRules,
    updateProviderRules,
    ProviderRulesResponse,
    ProviderRule,
} from '@/api/routes/admin/plugins/providers';
import useFlash, { useFlashKey } from '@/plugins/useFlash';
import classNames from 'classnames';
import { useStoreState } from '@/state/hooks';

const providers = [
    { key: 'modrinth.mods', label: 'Modrinth', description: 'Mods' },
    { key: 'modrinth.plugins', label: 'Modrinth (Plugins)', description: 'Plugins' },
    { key: 'curseforge', label: 'CurseForge', description: 'Mods + Modpacks' },
    { key: 'spigot.plugins', label: 'Spigot', description: 'Plugins' },
];

const STICKY_ACTION_BAR_HEIGHT_REM = 6;
const STICKY_ACTION_BAR_HEIGHT = `${STICKY_ACTION_BAR_HEIGHT_REM}rem`; // space reserved so content isn't hidden behind the sticky action bar

type SearchState = Record<string, { nests: string; eggs: string }>;

const defaultRule = (providerKey: string): ProviderRule => ({
    provider_key: providerKey,
    enabled_global: false,
    allowed_nest_ids: [],
    allowed_egg_ids: [],
});

const normalizeRule = (rule?: ProviderRule, providerKey?: string): ProviderRule => {
    const base = rule ?? defaultRule(providerKey ?? '');
    return {
        provider_key: base.provider_key ?? providerKey ?? '',
        enabled_global: base.enabled_global ?? false,
        allowed_nest_ids: [...(base.allowed_nest_ids ?? [])].sort((a, b) => a - b),
        allowed_egg_ids: [...(base.allowed_egg_ids ?? [])].sort((a, b) => a - b),
    };
};

export const rulesAreEqual = (current?: ProviderRule, initial?: ProviderRule, providerKey?: string) => {
    const next = normalizeRule(current, providerKey);
    const previous = normalizeRule(initial, providerKey ?? next.provider_key);
    const nextNests = next.allowed_nest_ids ?? [];
    const prevNests = previous.allowed_nest_ids ?? [];
    const nextEggs = next.allowed_egg_ids ?? [];
    const prevEggs = previous.allowed_egg_ids ?? [];

    return (
        next.enabled_global === previous.enabled_global &&
        nextNests.length === prevNests.length &&
        nextNests.every((id, index) => id === prevNests[index]) &&
        nextEggs.length === prevEggs.length &&
        nextEggs.every((id, index) => id === prevEggs[index])
    );
};

const hydrateResponse = (response: ProviderRulesResponse): ProviderRulesResponse => ({
    ...response,
    rules: providers.reduce(
        (rules, provider) => {
            rules[provider.key] = normalizeRule(response.rules[provider.key], provider.key);
            return rules;
        },
        { ...response.rules },
    ),
});

const ensureSearchState = (current: SearchState = {}): SearchState => {
    const next = { ...current };
    providers.forEach(provider => {
        if (!next[provider.key]) next[provider.key] = { nests: '', eggs: '' };
    });
    return next;
};

export const saveAllProviders = async (data: ProviderRulesResponse) => {
    const updatePromises = providers.map(provider => {
        const rule = normalizeRule(data.rules[provider.key], provider.key);
        return updateProviderRules({
            provider_key: provider.key,
            enabled_global: rule.enabled_global,
            allowed_nest_ids: rule.allowed_nest_ids ?? [],
            allowed_egg_ids: rule.allowed_egg_ids ?? [],
        });
    });

    await Promise.all(updatePromises);
    const refreshed = await getProviderRules();
    return hydrateResponse(refreshed);
};

export default function AccessControlContainer() {
    const [data, setData] = useState<ProviderRulesResponse | null>(null);
    const [initialData, setInitialData] = useState<ProviderRulesResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState<SearchState>({});

    const { clearAndAddHttpError, clearFlashes: clearPluginFlashes } = useFlashKey('admin:plugins');
    const { addFlash } = useFlash();
    const theme = useStoreState(state => state.theme.data!);
    const surfaceColor = theme.colors.secondary;
    const headerColor = theme.colors.headers;

    useEffect(() => {
        clearPluginFlashes();
        setLoading(true);
        getProviderRules()
            .then(response => {
                const hydrated = hydrateResponse(response);
                setInitialData(hydrated);
                setData(hydrated);
                setSearch(state => ensureSearchState(state));
            })
            .catch(clearAndAddHttpError)
            .finally(() => setLoading(false));
    }, []);

    const hasChanges = useMemo(() => {
        if (!data || !initialData) return false;
        return providers.some(
            provider =>
                !rulesAreEqual(
                    data.rules[provider.key] ?? defaultRule(provider.key),
                    initialData.rules[provider.key] ?? defaultRule(provider.key),
                    provider.key,
                ),
        );
    }, [data, initialData]);

    const updateRule = (providerKey: string, updater: (rule: ProviderRule) => ProviderRule) => {
        if (saving) return;
        setData(current => {
            if (!current) return current;
            const existing = normalizeRule(current.rules[providerKey], providerKey);
            const updated = normalizeRule(updater(existing), providerKey);
            return { ...current, rules: { ...current.rules, [providerKey]: updated } };
        });
    };

    const toggleNest = (providerKey: string, id: number) => {
        updateRule(providerKey, rule => {
            const list = new Set(rule.allowed_nest_ids ?? []);
            list.has(id) ? list.delete(id) : list.add(id);
            return { ...rule, allowed_nest_ids: Array.from(list).sort((a, b) => a - b) };
        });
    };

    const toggleEgg = (providerKey: string, id: number) => {
        updateRule(providerKey, rule => {
            const list = new Set(rule.allowed_egg_ids ?? []);
            list.has(id) ? list.delete(id) : list.add(id);
            return { ...rule, allowed_egg_ids: Array.from(list).sort((a, b) => a - b) };
        });
    };

    const toggleEnabled = (providerKey: string) => {
        updateRule(providerKey, rule => ({ ...rule, enabled_global: !rule.enabled_global }));
    };

    const selectAllNests = (providerKey: string, ids: number[]) => {
        updateRule(providerKey, rule => ({ ...rule, allowed_nest_ids: ids }));
    };

    const clearNests = (providerKey: string) => {
        updateRule(providerKey, rule => ({ ...rule, allowed_nest_ids: [] }));
    };

    const selectAllEggs = (providerKey: string, ids: number[]) => {
        updateRule(providerKey, rule => ({ ...rule, allowed_egg_ids: ids }));
    };

    const clearEggs = (providerKey: string) => {
        updateRule(providerKey, rule => ({ ...rule, allowed_egg_ids: [] }));
    };

    const handleResetAll = () => {
        if (saving || !initialData) return;
        setData(initialData);
    };

    const handleSaveAll = () => {
        if (!data || saving) return;

        setSaving(true);
        saveAllProviders(data)
            .then(hydrated => {
                setData(hydrated);
                setInitialData(hydrated);
                addFlash({
                    key: 'admin:plugins',
                    type: 'success',
                    title: 'Saved',
                    message: 'Access control rules updated.',
                });
            })
            .catch(clearAndAddHttpError)
            .finally(() => setSaving(false));
    };

    if (loading || !data || !initialData) {
        return (
            <div className={'flex flex-col items-center gap-3 py-10'}>
                <Spinner size={'large'} />
                <p className={'text-sm text-neutral-300'}>Loading access rules…</p>
            </div>
        );
    }

    return (
        <div className={'space-y-5'} style={{ paddingBottom: STICKY_ACTION_BAR_HEIGHT }}>
            <Alert type={'info'}>
                Default policy: All providers are denied unless explicitly enabled and allowed for specific nests/eggs.
                Selecting eggs overrides nest rules.
            </Alert>

            {providers.map(provider => {
                const rule = data.rules[provider.key] ?? defaultRule(provider.key);
                const providerSearch = search[provider.key] ?? { nests: '', eggs: '' };
                const controlsDisabled = !rule.enabled_global || saving;

                const nestQuery = providerSearch.nests.toLowerCase();
                const eggQuery = providerSearch.eggs.toLowerCase();

                const filteredNests = data.nests.filter(nest => nest.name.toLowerCase().includes(nestQuery));

                const allEggs = data.nests.flatMap(nest => nest.eggs ?? []);
                const filteredEggs = allEggs.filter(egg => egg.name.toLowerCase().includes(eggQuery));

                return (
                    <AdminBox
                        key={provider.key}
                        title={
                            <div className={'flex flex-col gap-1'}>
                                <p className={'text-lg font-semibold text-neutral-50'}>{provider.label}</p>
                                <p className={'text-sm text-neutral-300'}>{provider.description}</p>
                            </div>
                        }
                        className={'shadow-lg'}
                    >
                        <div className={'flex flex-col gap-6'}>
                            <div className={'flex items-center justify-between gap-4'}>
                                <div className={'text-sm text-neutral-300'}>Provider Enabled</div>
                                <button
                                    type={'button'}
                                    onClick={() => toggleEnabled(provider.key)}
                                    disabled={saving}
                                    aria-label={`Toggle ${provider.label} provider`}
                                    className={classNames(
                                        'relative inline-flex h-8 w-16 items-center rounded-full border transition focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-neutral-900',
                                        rule.enabled_global ? 'bg-primary-500/80' : '',
                                        saving && 'opacity-50 cursor-not-allowed',
                                    )}
                                    style={{
                                        borderColor: headerColor,
                                        backgroundColor: rule.enabled_global ? undefined : surfaceColor,
                                    }}
                                >
                                    <span
                                        className={classNames(
                                            'inline-block h-6 w-6 transform rounded-full bg-neutral-100 shadow transition',
                                            rule.enabled_global ? 'translate-x-8' : 'translate-x-2',
                                        )}
                                    />
                                </button>
                            </div>

                            <div
                                className={classNames(
                                    'grid gap-5 lg:grid-cols-2',
                                    !rule.enabled_global && 'opacity-60',
                                    controlsDisabled && 'pointer-events-none',
                                )}
                            >
                                <div className={'flex flex-col gap-3'}>
                                    <div className={'flex items-center justify-between'}>
                                        <div>
                                            <p className={'text-base font-semibold text-neutral-50'}>Nests Allowed</p>
                                            <p className={'text-xs text-neutral-400'}>Select nests this provider can use.</p>
                                        </div>
                                        <div className={'flex items-center gap-3 text-xs text-neutral-300'}>
                                            <button
                                                type={'button'}
                                                className={'hover:text-primary-400'}
                                                onClick={() =>
                                                    selectAllNests(
                                                        provider.key,
                                                        filteredNests.map(n => n.id),
                                                    )
                                                }
                                                disabled={controlsDisabled}
                                            >
                                                Select all visible
                                            </button>
                                            <span className={'text-neutral-600'}>|</span>
                                            <button
                                                type={'button'}
                                                className={'hover:text-primary-400'}
                                                onClick={() => clearNests(provider.key)}
                                                disabled={controlsDisabled}
                                            >
                                                Clear
                                            </button>
                                        </div>
                                    </div>
                                    <input
                                        type={'search'}
                                        placeholder={'Search nests'}
                                        value={providerSearch.nests}
                                        disabled={controlsDisabled}
                                        onChange={e =>
                                            setSearch(state =>
                                                ensureSearchState({
                                                    ...state,
                                                    [provider.key]: { ...providerSearch, nests: e.target.value },
                                                }),
                                            )
                                        }
                                        className={
                                            'w-full rounded-md border px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed'
                                        }
                                        style={{
                                            backgroundColor: surfaceColor,
                                            borderColor: headerColor,
                                        }}
                                    />
                                    <div className={'grid gap-2 md:grid-cols-2 xl:grid-cols-3'}>
                                        {filteredNests.length === 0 && (
                                            <div className={'text-sm text-neutral-400'}>No nests match this search.</div>
                                        )}
                                        {filteredNests.map(nest => {
                                            const selected = (rule.allowed_nest_ids ?? []).includes(nest.id);

                                            return (
                                                <label
                                                    key={nest.id}
                                                    className={classNames(
                                                        // NOTE: minimal diff — just added a card-like background + checked highlight bg
                                                        'flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 transition hover:border-primary-500 bg-neutral-900/40 hover:bg-neutral-900/60',
                                                        selected && 'border-primary-500 bg-primary-500/10',
                                                    )}
                                                    style={{
                                                        // IMPORTANT: only set borderColor when NOT selected so `border-primary-500` can show
                                                        borderColor: selected ? undefined : headerColor,
                                                    }}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className={
                                                            'h-5 w-5 rounded border-neutral-600 bg-neutral-900 text-primary-500 focus:ring-primary-500'
                                                        }
                                                        checked={selected}
                                                        onChange={() => toggleNest(provider.key, nest.id)}
                                                        disabled={controlsDisabled}
                                                    />
                                                    <span className={'text-sm text-neutral-100'}>{nest.name}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>

                                <div className={'flex flex-col gap-3'}>
                                    <div className={'flex items-center justify-between'}>
                                        <div>
                                            <p className={'text-base font-semibold text-neutral-50'}>Eggs Allowed</p>
                                            <p className={'text-xs text-neutral-400'}>
                                                Eggs override nest rules for finer control.
                                            </p>
                                        </div>
                                        <div className={'flex items-center gap-3 text-xs text-neutral-300'}>
                                            <button
                                                type={'button'}
                                                className={'hover:text-primary-400'}
                                                onClick={() =>
                                                    selectAllEggs(
                                                        provider.key,
                                                        filteredEggs.map(egg => egg.id),
                                                    )
                                                }
                                                disabled={controlsDisabled}
                                            >
                                                Select all visible
                                            </button>
                                            <span className={'text-neutral-600'}>|</span>
                                            <button
                                                type={'button'}
                                                className={'hover:text-primary-400'}
                                                onClick={() => clearEggs(provider.key)}
                                                disabled={controlsDisabled}
                                            >
                                                Clear
                                            </button>
                                        </div>
                                    </div>
                                    <input
                                        type={'search'}
                                        placeholder={'Search eggs'}
                                        value={providerSearch.eggs}
                                        disabled={controlsDisabled}
                                        onChange={e =>
                                            setSearch(state =>
                                                ensureSearchState({
                                                    ...state,
                                                    [provider.key]: { ...providerSearch, eggs: e.target.value },
                                                }),
                                            )
                                        }
                                        className={
                                            'w-full rounded-md border px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed'
                                        }
                                        style={{
                                            backgroundColor: surfaceColor,
                                            borderColor: headerColor,
                                        }}
                                    />
                                    <div className={'grid gap-2 md:grid-cols-2 xl:grid-cols-3'}>
                                        {filteredEggs.length === 0 && (
                                            <div className={'text-sm text-neutral-400'}>No eggs match this search.</div>
                                        )}
                                        {filteredEggs.map(egg => {
                                            const selected = (rule.allowed_egg_ids ?? []).includes(egg.id);

                                            return (
                                                <label
                                                    key={egg.id}
                                                    className={classNames(
                                                        // NOTE: minimal diff — same styling as nests
                                                        'flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 transition hover:border-primary-500 bg-neutral-900/40 hover:bg-neutral-900/60',
                                                        selected && 'border-primary-500 bg-primary-500/10',
                                                    )}
                                                    style={{
                                                        borderColor: selected ? undefined : headerColor,
                                                    }}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className={
                                                            'h-5 w-5 rounded border-neutral-600 bg-neutral-900 text-primary-500 focus:ring-primary-500'
                                                        }
                                                        checked={selected}
                                                        onChange={() => toggleEgg(provider.key, egg.id)}
                                                        disabled={controlsDisabled}
                                                    />
                                                    <div className={'flex flex-col leading-tight'}>
                                                        <span className={'text-sm text-neutral-100'}>{egg.name}</span>
                                                    </div>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </AdminBox>
                );
            })}

            <div
                className={
                    'sticky bottom-0 z-10 mt-4 flex flex-col gap-3 border-t border-neutral-800 bg-neutral-900/90 px-4 py-3 backdrop-blur'
                }
                style={{
                    minHeight: STICKY_ACTION_BAR_HEIGHT,
                    backgroundColor: surfaceColor,
                    borderColor: headerColor,
                }}
            >
                <div className={'flex items-center justify-between'}>
                    <div className={'flex items-center gap-3'}>
                        <div aria-live={'polite'}>
                            {hasChanges ? (
                                <div className={'flex items-center gap-2 text-sm text-amber-400'}>
                                    <span className={'h-2 w-2 animate-pulse rounded-full bg-amber-400'} />
                                    Unsaved changes
                                </div>
                            ) : (
                                <div className={'text-sm text-neutral-400'}>All changes saved</div>
                            )}
                        </div>
                        {saving && (
                            <div className={'flex items-center gap-2 text-sm text-neutral-300'}>
                                <Spinner size={'small'} />
                                Saving…
                            </div>
                        )}
                    </div>
                    <div className={'flex items-center gap-2'}>
                        <Button.Text onClick={handleResetAll} disabled={!hasChanges || saving}>
                            Reset changes
                        </Button.Text>
                        <Button disabled={!hasChanges || saving} onClick={handleSaveAll}>
                            {saving ? 'Saving…' : 'Save All'}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
