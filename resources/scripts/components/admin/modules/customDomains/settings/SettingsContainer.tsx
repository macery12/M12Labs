import { useEffect, useState } from 'react';
import Input from '@/elements/Input';
import { Button } from '@/elements/button';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import { getCustomDomainSettings, updateCustomDomainSettings } from '@/api/routes/admin/customDomains';

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();

    const [loading, setLoading] = useState(false);
    const [token, setToken] = useState('');

    useEffect(() => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        getCustomDomainSettings()
            .then(data => setToken(data.cloudflare_token || ''))
            .catch(error => clearAndAddHttpError({ key: 'admin:custom-domains', error }))
            .finally(() => setLoading(false));
    }, []);

    const onSave = async () => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await updateCustomDomainSettings({ cloudflare_token: token.trim() });

            addFlash({
                key: 'admin:custom-domains',
                type: 'success',
                message: 'Custom domain settings saved successfully.',
            });
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <SpinnerOverlay visible={loading} />

            <div className={'rounded border border-neutral-700 bg-neutral-800 p-6'}>
                <h3 className={'mb-2 text-lg font-semibold text-neutral-100'}>Cloudflare API</h3>
                <p className={'mb-4 text-sm text-neutral-400'}>
                    Store your Cloudflare API token directly in the panel for DNS automation.
                </p>

                <div className={'max-w-3xl'}>
                    <Input
                        type={'password'}
                        value={token}
                        onChange={e => setToken(e.currentTarget.value)}
                        placeholder={'Cloudflare API token'}
                        autoComplete={'off'}
                    />
                </div>

                <div className={'mt-4'}>
                    <Button onClick={onSave} disabled={!token.trim()}>
                        Save Settings
                    </Button>
                </div>
            </div>
        </>
    );
};
