import { useEffect, useState } from 'react';
import Input from '@/elements/Input';
import { Button } from '@/elements/button';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import {
    createCustomDomainApiKey,
    deleteCustomDomainApiKey,
    getCustomDomainApiKeys,
    updateCustomDomainApiKey,
    type CustomDomainApiKey,
} from '@/api/routes/admin/customDomains';

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);

    const [loading, setLoading] = useState(false);
    const [apiKeys, setApiKeys] = useState<CustomDomainApiKey[]>([]);
    const [name, setName] = useState('');
    const [token, setToken] = useState('');

    const loadApiKeys = async () => {
        const rows = await getCustomDomainApiKeys();
        setApiKeys(rows);
    };

    useEffect(() => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        loadApiKeys()
            .catch(error => clearAndAddHttpError({ key: 'admin:custom-domains', error }))
            .finally(() => setLoading(false));
    }, []);

    const onCreate = async () => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await createCustomDomainApiKey({ name: name.trim(), token: token.trim(), enabled: true });
            setName('');
            setToken('');
            await loadApiKeys();

            addFlash({
                key: 'admin:custom-domains',
                type: 'success',
                message: 'Cloudflare API key saved.',
            });
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    const onToggle = async (row: CustomDomainApiKey) => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await updateCustomDomainApiKey(row.id, { enabled: !row.enabled });
            await loadApiKeys();
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    const onDelete = async (id: number) => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await deleteCustomDomainApiKey(id);
            await loadApiKeys();
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <SpinnerOverlay visible={loading} />

            <div
                className={'rounded border p-6'}
                style={{ borderColor: `${colors.primary}40`, backgroundColor: colors.secondary }}
            >
                <h3 className={'mb-2 text-lg font-semibold text-neutral-100'}>Cloudflare API Keys</h3>
                <p className={'mb-4 text-sm text-neutral-400'}>
                    Add multiple Cloudflare API keys with human-readable names. Keys are encrypted at rest.
                </p>

                <div className={'grid grid-cols-1 gap-3 md:grid-cols-3'}>
                    <Input
                        value={name}
                        onChange={e => setName(e.currentTarget.value)}
                        placeholder={'Key name (e.g. Main CF Account)'}
                        autoComplete={'off'}
                    />
                    <Input
                        type={'password'}
                        value={token}
                        onChange={e => setToken(e.currentTarget.value)}
                        placeholder={'Cloudflare API token'}
                        autoComplete={'off'}
                    />
                    <Button onClick={onCreate} disabled={!name.trim() || !token.trim()}>
                        Add API Key
                    </Button>
                </div>

                <div className={'mt-6 space-y-2'}>
                    {apiKeys.length < 1 && (
                        <div
                            className={'rounded border p-3 text-sm text-neutral-300'}
                            style={{ borderColor: `${colors.primary}40`, backgroundColor: colors.background }}
                        >
                            No API keys configured yet.
                        </div>
                    )}

                    {apiKeys.map(row => (
                        <div
                            key={row.id}
                            className={'flex flex-col gap-3 rounded border p-3 md:flex-row md:items-center md:justify-between'}
                            style={{ borderColor: `${colors.primary}40`, backgroundColor: colors.background }}
                        >
                            <div>
                                <div className={'text-sm font-semibold text-neutral-100'}>{row.name}</div>
                                <div className={'text-xs text-neutral-400'}>{row.enabled ? 'Enabled' : 'Disabled'}</div>
                            </div>

                            <div className={'flex items-center gap-2'}>
                                <Button
                                    color={row.enabled ? 'green' : 'red'}
                                    variant={row.enabled ? 'filled' : 'outlined'}
                                    onClick={() => onToggle(row)}
                                >
                                    {row.enabled ? 'Enabled' : 'Disabled'}
                                </Button>
                                <Button color={'secondary'} onClick={() => onDelete(row.id)}>
                                    Delete
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
};
