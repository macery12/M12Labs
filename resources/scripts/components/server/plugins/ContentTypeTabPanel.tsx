import tw from 'twin.macro';
import { ProviderKey } from '@/api/routes/server/plugins';

interface Props {
    providers: ProviderKey[];
    activeProvider: ProviderKey | null;
    onChange: (provider: ProviderKey) => void;
    providerLabels: Record<ProviderKey, string>;
}

const ContentTypeTabPanel = ({ providers, activeProvider, onChange, providerLabels }: Props) => {
    if (!providers.length) return null;

    const resolvedActive = activeProvider ?? providers[0];

    return (
        <div css={tw`flex gap-2 flex-wrap`}>
            {providers.map(provider => {
                const active = provider === resolvedActive;
                return (
                    <button
                        key={provider}
                        css={[
                            tw`px-3 py-1.5 text-sm font-medium transition-colors rounded-full border`,
                            active
                                ? tw`bg-blue-600 text-white border-blue-500`
                                : tw`bg-neutral-800 text-neutral-300 border-neutral-700 hover:text-neutral-100 hover:bg-neutral-700`,
                        ]}
                        onClick={() => onChange(provider)}
                        type="button"
                    >
                        {providerLabels[provider]}
                    </button>
                );
            })}
        </div>
    );
};

export default ContentTypeTabPanel;
