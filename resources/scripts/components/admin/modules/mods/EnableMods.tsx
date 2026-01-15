import { useStoreState } from '@/state/hooks';
import FeatureContainer from '@/elements/FeatureContainer';
import { faCube } from '@fortawesome/free-solid-svg-icons';
import ToggleFeatureButton from '@admin/modules/mods/ToggleFeatureButton';

export default () => {
    const primary = useStoreState(state => state.theme.data!.colors.primary);

    return (
        <FeatureContainer icon={faCube} title={'Mods Module'}>
            Enable the Mods module to integrate CurseForge with Jexactyl, allowing users to search for and install
            Minecraft mods directly from the panel. You&apos;ll need a CurseForge API key to use this feature, which
            can be obtained from the CurseForge Console.
            <p className={'mt-2 text-right'}>
                <ToggleFeatureButton />
            </p>
        </FeatureContainer>
    );
};
