import { Button } from '@/elements/button';
import { useStoreState } from '@/state/hooks';
import { updateSettings } from '@/api/routes/admin/mods/settings';

export default () => {
    const mods = useStoreState(state => state.everest.data!.mods);

    const submit = () => {
        updateSettings({ enabled: !mods.enabled }).then(() => {
            // @ts-expect-error this is fine
            window.location = '/admin/plugins';
        });
    };

    return (
        <div className={'mr-4'} onClick={submit}>
            {!mods.enabled ? <Button>Enable Plugins</Button> : <Button.Danger>Disable Plugins</Button.Danger>}
        </div>
    );
};
