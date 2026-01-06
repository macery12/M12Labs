import { Button } from '@/elements/button';
import { useStoreState } from '@/state/hooks';
import { updateSettings } from '@/api/routes/admin/ai/settings';

export default () => {
    const ai = useStoreState(state => state.everest.data!.ai);

    const submit = () => {
        updateSettings({ enabled: !ai.enabled }).then(() => {
            // @ts-expect-error this is fine
            window.location = '/admin/ai';
        });
    };

    return (
        <div className={'mr-4'} onClick={submit}>
            {!ai.enabled ? <Button>Enable Jexpanel AI</Button> : <Button.Danger>Disable Jexpanel AI</Button.Danger>}
        </div>
    );
};
