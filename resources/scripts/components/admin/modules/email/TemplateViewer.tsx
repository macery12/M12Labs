import { useEffect, useRef, useState } from 'react';
import { getEmailTemplates, previewEmailTemplate, type EmailTemplate } from '@/api/routes/admin/email';
import useFlash from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { useStoreState } from '@/state/hooks';
import tw from 'twin.macro';
import styled from 'styled-components';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEnvelopeOpenText, faRedo } from '@fortawesome/free-solid-svg-icons';

const Layout = styled.div`
    ${tw`flex gap-4`}
    min-height: 520px;
`;

const Sidebar = styled.div`
    ${tw`flex-none w-56 flex flex-col gap-1`}
`;

const CategoryLabel = styled.div`
    ${tw`text-xs font-semibold uppercase tracking-wider px-2 pt-3 pb-1`}
    color: #6b7280;
`;

const TemplateItem = styled.button<{ $active: boolean; $bg: string; $activeBg: string }>`
    ${tw`w-full text-left rounded px-3 py-2 text-sm transition-colors`}
    background-color: ${({ $active, $activeBg }) => ($active ? $activeBg : 'transparent')};
    color: ${({ $active }) => ($active ? '#ffffff' : '#d1d5db')};
    &:hover {
        background-color: ${({ $active, $activeBg }) => ($active ? $activeBg : 'rgba(255,255,255,0.06)')};
    }
`;

const PreviewPane = styled.div`
    ${tw`flex-1 flex flex-col rounded-lg overflow-hidden border border-neutral-700`}
    min-height: 480px;
`;

const PreviewHeader = styled.div`
    ${tw`flex items-center justify-between px-4 py-2 border-b border-neutral-700 text-xs`}
    color: #9ca3af;
    background-color: rgba(255, 255, 255, 0.04);
`;

const StyledIframe = styled.iframe`
    ${tw`flex-1 w-full border-0`}
    min-height: 460px;
`;

const EmptyPane = styled.div`
    ${tw`flex-1 flex flex-col items-center justify-center gap-3`}
    color: #6b7280;
`;

export default () => {
    const [loading, setLoading] = useState(true);
    const [templates, setTemplates] = useState<EmailTemplate[]>([]);
    const [selected, setSelected] = useState<EmailTemplate | null>(null);
    const [previewHtml, setPreviewHtml] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const { clearFlashes, addFlash } = useFlash();
    const { colors } = useStoreState((state) => state.theme.data!);

    useEffect(() => {
        clearFlashes('email:templates');
        getEmailTemplates()
            .then(({ templates: list }) => {
                setTemplates(list);
                if (list.length > 0) {
                    selectTemplate(list[0]);
                }
            })
            .catch(() =>
                addFlash({ key: 'email:templates', type: 'error', message: 'Failed to load email templates.' }),
            )
            .finally(() => setLoading(false));
    }, []);

    const selectTemplate = (tpl: EmailTemplate) => {
        setSelected(tpl);
        setPreviewHtml(null);
        setPreviewLoading(true);
        previewEmailTemplate(tpl.key)
            .then((html) => setPreviewHtml(html))
            .catch(() =>
                addFlash({ key: 'email:templates', type: 'error', message: `Failed to render preview for "${tpl.label}".` }),
            )
            .finally(() => setPreviewLoading(false));
    };

    // Write the preview HTML into the iframe document for isolated rendering.
    useEffect(() => {
        if (!iframeRef.current || previewHtml === null) return;
        const doc = iframeRef.current.contentDocument;
        if (!doc) return;
        doc.open();
        doc.write(previewHtml);
        doc.close();
    }, [previewHtml]);

    // Group templates by category for sidebar display.
    const grouped = templates.reduce<Record<string, EmailTemplate[]>>((acc, tpl) => {
        (acc[tpl.category] = acc[tpl.category] ?? []).push(tpl);
        return acc;
    }, {});

    if (loading) {
        return (
            <div css={tw`flex justify-center py-8`}>
                <Spinner size='large' />
            </div>
        );
    }

    return (
        <Layout>
            {/* Sidebar — template list */}
            <Sidebar>
                {Object.entries(grouped).map(([category, items]) => (
                    <div key={category}>
                        <CategoryLabel>{category}</CategoryLabel>
                        {items.map((tpl) => (
                            <TemplateItem
                                key={tpl.key}
                                $active={selected?.key === tpl.key}
                                $bg={colors.secondary}
                                $activeBg={colors.primary}
                                onClick={() => selectTemplate(tpl)}
                            >
                                {tpl.label}
                            </TemplateItem>
                        ))}
                    </div>
                ))}
            </Sidebar>

            {/* Preview pane */}
            <PreviewPane>
                <PreviewHeader>
                    <span>
                        {selected ? (
                            <>
                                <FontAwesomeIcon icon={faEnvelopeOpenText} css={tw`mr-2`} />
                                {selected.label}
                                <span css={tw`ml-2 opacity-50`}>({selected.key})</span>
                            </>
                        ) : (
                            'Select a template'
                        )}
                    </span>
                    {selected && (
                        <button
                            css={tw`flex items-center gap-1 hover:text-white transition-colors`}
                            onClick={() => selectTemplate(selected)}
                            title='Refresh preview'
                        >
                            <FontAwesomeIcon icon={faRedo} />
                        </button>
                    )}
                </PreviewHeader>

                {previewLoading ? (
                    <EmptyPane>
                        <Spinner size='large' />
                    </EmptyPane>
                ) : previewHtml !== null ? (
                    <StyledIframe ref={iframeRef} title={`Preview: ${selected?.label}`} sandbox='allow-same-origin' />
                ) : (
                    <EmptyPane>
                        <FontAwesomeIcon icon={faEnvelopeOpenText} size='2x' />
                        <span css={tw`text-sm`}>Select a template to preview it here.</span>
                    </EmptyPane>
                )}
            </PreviewPane>
        </Layout>
    );
};
