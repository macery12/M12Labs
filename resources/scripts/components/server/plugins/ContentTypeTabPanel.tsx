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
        <div css={tw`mb-6 flex gap-2 border-b border-neutral-700`}>
            {providers.map(provider => {
                const active = provider === resolvedActive;
                return (
                    <button
                        key={provider}
                        css={[
                            tw`px-3 py-2 text-sm font-medium transition-colors rounded-t`,
                            active ? tw`text-blue-300 border-b-2 border-blue-300` : tw`text-neutral-400 hover:text-neutral-200`,
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
