import { useStoreState } from '@/state/hooks';
import { faPuzzlePiece } from '@fortawesome/free-solid-svg-icons';
import FeatureContainer from '@/elements/FeatureContainer';
import ToggleExtensionsButton from './ToggleExtensionsButton';
import ExtensionsSvg from './ExtensionsSvg';

export default () => {
    const primary = useStoreState(state => state.theme.data!.colors.primary);

    return (
        <FeatureContainer image={<ExtensionsSvg color={primary} />} icon={faPuzzlePiece} title={'Extensions Module'}>
            The Extensions module allows you to enable powerful add-ons for your servers. Configure which nests and eggs
            can use each extension, giving server owners access to specialized tools like the Minecraft Player Manager.
            Extensions can be configured individually with their own settings and access controls.
            <p className={'text-right'}>
                <ToggleExtensionsButton />
            </p>
        </FeatureContainer>
    );
};
