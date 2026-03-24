import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import ToggleFeatureButton from './ToggleFeatureButton';
import { faArrowsUpDown, faDollar, faExchange, faGavel, faKey, faPowerOff } from '@fortawesome/free-solid-svg-icons';
import { useStoreActions, useStoreState } from '@/state/hooks';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import currencyDictionary from '@/assets/currency';
import SetupStripe from './guides/SetupStripe';
import ExportConfigButton from './config/ExportConfigButton';
import FlashMessageRender from '@/elements/FlashMessageRender';
import ImportConfigButton from './config/ImportConfigButton';
import { deleteStripeKeys, updateSettings } from '@/api/routes/admin/billing';
import BillingLinksForm from '@admin/modules/billing/BillingLinksForm';

export type BillingSetupDialog = 'setup' | 'none';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const [open, setOpen] = useState<BillingSetupDialog>('none');

    const submit = async (key: string, value: boolean | string) => {
        await updateSettings(key, value).then(() => {
            updateEverest({ billing: { ...settings, [key]: value } });
        });
    };

    const handleCurrencyChange = async (event: any) => {
        const code: string = event.target.value.toUpperCase();
        const symbol: string = currencyDictionary[code]!.symbol;

        submit('currency:code', code).then(() => {
            submit('currency:symbol', symbol);
        });
    };

    const onDeleteKeys = () => {
        deleteStripeKeys()
            .then(() => window.location.reload())
            .catch(error => console.log(error));
    };

    return (
        <div className={'grid lg:grid-cols-3 gap-4'}>
            {open === 'setup' && <SetupStripe extOpen />}
            <AdminBox title={'Primary Currency'} icon={faDollar}>
                Choose a primary currency to charge users.
                <div className={'mt-4'}>
                    <Label>Currency Code / Name</Label>
                    <Select onChange={handleCurrencyChange}>
                        {Object.keys(currencyDictionary).map(code => (
                            <option
                                key={code}
                                value={code}
                                onChange={() => console.log('hello')}
                                selected={code === settings.currency.code.toUpperCase()}
                            >
                                {code} - {currencyDictionary[code]!.name}
                            </option>
                        ))}
                    </Select>
                </div>
            </AdminBox>
            <AdminBox title={'Allow Self Upgrades'} icon={faArrowsUpDown}>
                <p className={'text-sm'}>
                    Having this service enabled means users can upgrade and downgrade to different products within their
                    existing category. A bill will automatically be generated if users with to upgrade before their
                    renewal is due, to ensure that they will pay for the usage of the upgraded plan. The user will not
                    be able to then change their plan for another 30 days after a change to prevent abuse.
                </p>
                <p className={'text-gray-400 mt-2'}>
                    This service is currently&nbsp;
                    <span className={settings.allow_upgrades ? 'text-green-500' : 'text-red-500'}>
                        {settings.allow_upgrades ? 'enabled' : 'disabled'}
                    </span>
                    .
                </p>
                <div className={'text-right mt-2'}>
                    <Button.Text onClick={() => submit('allow_upgrades', !settings.allow_upgrades)}>
                        {settings.allow_upgrades ? 'Disable' : 'Enable'}
                    </Button.Text>
                </div>
            </AdminBox>
            <AdminBox title={'Import/Export Configuration'} icon={faExchange}>
                <FlashMessageRender byKey={'billing:config'} className={'mb-2'} />
                Use the below options to either export your current billing configurations, or use the Import button to
                import a pre-created set of categories and products to Jexactyl.
                <div className={'text-right mt-3'}>
                    <ExportConfigButton />
                    <ImportConfigButton />
                </div>
            </AdminBox>
            {!settings.keys.secret ? (
                <AdminBox title={'Input Stripe API Keys'} icon={faKey}>
                    Without Stripe API authentication, your billing system will not work. Customers may proceed to the
                    checkout area but will be met with errors unless you add valid API keys which can be obtained
                    through the Stripe dashboard.
                    <div className={'text-right mt-3'}>
                        <Button onClick={() => setOpen('setup')}>Add API keys</Button>
                    </div>
                </AdminBox>
            ) : (
                <AdminBox title={'Reset Stripe API keys'} icon={faKey}>
                    By resetting the Stripe API keys saved to the panel, all billing services (such as purchasing or
                    renewing a product) will stop working until new API keys are entered. Are you sure you wish to
                    continue?
                    <div className={'text-right mt-3'}>
                        <Button.Danger onClick={onDeleteKeys}>Yes, delete API keys</Button.Danger>
                    </div>
                </AdminBox>
            )}
            <AdminBox title={'Legal Document Links'} icon={faGavel}>
                Provide a link to your business&apos; ToS or privacy policy that users must accept before purchase.
                <BillingLinksForm />
            </AdminBox>
            <AdminBox title={'Disable Billing Module'} icon={faPowerOff}>
                Clicking the button below will disable all modules of the billing system - such as subscriptions, server
                purchasing and more. Make sure that this will not impact your users before disabling.
                <div className={'text-right mt-3'}>
                    <ToggleFeatureButton />
                </div>
            </AdminBox>
        </div>
    );
};
