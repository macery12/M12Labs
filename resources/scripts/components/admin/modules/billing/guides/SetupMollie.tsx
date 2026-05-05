import Modal from '@/elements/Modal';
import { Form, Formik, FormikHelpers } from 'formik';
import FlashMessageRender from '@/elements/FlashMessageRender';
import Field from '@/elements/Field';
import { Button } from '@/elements/button';
import { updateSettings } from '@/api/routes/admin/billing';
import { useStoreActions, useStoreState } from '@/state/hooks';

interface Values {
    apiKey: string;
}

interface Props {
    extOpen?: boolean;
}

export default ({}: Props) => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);

    const submit = async (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        await updateSettings('mollie:api_key', values.apiKey)
            .then(() => {
                updateEverest({
                    billing: {
                        ...settings,
                        mollie: {
                            api_key: values.apiKey,
                        },
                    },
                });
                window.location.reload();
            })
            .finally(() => setSubmitting(false));
    };

    return (
        <Formik onSubmit={submit} initialValues={{ apiKey: '' }}>
            {({ isSubmitting }) => (
                <Modal visible={true} onDismissed={() => window.location.reload()}>
                    <FlashMessageRender byKey={'billing:mollie'} />
                    <Form>
                        <h2 className={'mb-6 text-2xl'}>Setup Mollie</h2>
                        <p className={'mb-4 text-sm text-gray-400'}>
                            To use Mollie as a payment processor, you need to create an account at{' '}
                            <a
                                href="https://www.mollie.com"
                                target="_blank"
                                rel="noreferrer"
                                className="text-blue-500 hover:underline"
                            >
                                mollie.com
                            </a>
                            . After creating an account:
                        </p>
                        <ol className={'mb-4 list-inside list-decimal text-sm text-gray-400'}>
                            <li>Log in to your Mollie Dashboard</li>
                            <li>Navigate to Developers → API keys</li>
                            <li>Copy your Live API key (starts with "live_") for production</li>
                            <li>Or use Test API key (starts with "test_") for testing</li>
                            <li>Paste the API key below</li>
                        </ol>
                        <Field
                            name={'apiKey'}
                            label={'Mollie API Key'}
                            description={'The API key from your Mollie dashboard'}
                        />
                        <div className={'mt-6 text-right'}>
                            <Button loading={isSubmitting}>Save API Key</Button>
                        </div>
                    </Form>
                </Modal>
            )}
        </Formik>
    );
};
