import FeatureContainer from '@/elements/FeatureContainer';
import { faCube } from '@fortawesome/free-solid-svg-icons';
import ToggleFeatureButton from '@admin/modules/mods/ToggleFeatureButton';

export default () => {
    return (
        <FeatureContainer icon={faCube} title={'Marketplace Module'}>
            Enable the Marketplace module to integrate Modrinth, CurseForge, and Spiget with M12Labs, allowing users to
            search for and install mods, modpacks, and plugins directly from the panel. You&apos;ll need a CurseForge
            API key to use CurseForge content, obtainable from the CurseForge Console.
            <p className={'mt-2 text-right'}>
                <ToggleFeatureButton />
            </p>
        </FeatureContainer>
    );
};
