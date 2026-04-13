import { useCallback, useEffect, useRef, useState } from 'react';
import {
    getEmailTemplates,
    getEmailTemplateSource,
    previewEmailTemplate,
    saveEmailTemplateSource,
    type EmailTemplate,
    type TemplateVariable,
} from '@/api/routes/admin/email';
import useFlash from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { useStoreState } from '@/state/hooks';
import tw from 'twin.macro';
import styled from 'styled-components';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faEnvelopeOpenText,
    faRedo,
    faPencilAlt,
    faSave,
    faTimes,
    faChevronDown,
    faChevronUp,
    faCheck,
    faExclamationTriangle,
    faUndo,
} from '@fortawesome/free-solid-svg-icons';
import { LanguageDescription } from '@codemirror/language';
import { html } from '@codemirror/lang-html';
import { Editor } from '@/elements/editor';

const Layout = styled.div`
    ${tw`flex gap-4`}
    min-height: 600px;
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

const MainArea = styled.div`
    ${tw`flex-1 flex flex-col gap-0 rounded-lg overflow-hidden border border-neutral-700`}
    min-height: 580px;
`;

const PaneHeader = styled.div`
    ${tw`flex items-center justify-between px-4 py-2 border-b border-neutral-700 text-xs`}
    color: #9ca3af;
    background-color: rgba(255, 255, 255, 0.04);
`;

const ActionButton = styled.button<{ $variant?: 'primary' | 'danger' | 'default' }>`
    ${tw`flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors`}
    background-color: ${({ $variant }) =>
        $variant === 'primary' ? '#2563eb' : $variant === 'danger' ? '#dc2626' : 'rgba(255,255,255,0.08)'};
    color: ${({ $variant }) => ($variant ? '#ffffff' : '#d1d5db')};
    &:hover {
        background-color: ${({ $variant }) =>
            $variant === 'primary' ? '#1d4ed8' : $variant === 'danger' ? '#b91c1c' : 'rgba(255,255,255,0.14)'};
    }
    &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
`;

const SplitBody = styled.div`
    ${tw`flex flex-1`}
    min-height: 0;
`;

const EditorPane = styled.div`
    ${tw`flex-1 flex flex-col border-r border-neutral-700 overflow-hidden`}
`;

const PreviewPane = styled.div`
    ${tw`flex-1 flex flex-col overflow-hidden`}
`;

const StyledIframe = styled.iframe`
    ${tw`flex-1 w-full border-0`}
    min-height: 500px;
`;

const EmptyPane = styled.div`
    ${tw`flex-1 flex flex-col items-center justify-center gap-3`}
    color: #6b7280;
`;

const VarsPanel = styled.div`
    ${tw`border-t border-neutral-700`}
    background-color: rgba(255, 255, 255, 0.02);
`;

const VarsPanelHeader = styled.button`
    ${tw`w-full flex items-center justify-between px-4 py-2 text-xs font-semibold uppercase tracking-wider transition-colors`}
    color: #6b7280;
    &:hover { color: #9ca3af; }
`;

const VarsGrid = styled.div`
    ${tw`px-4 pb-3 grid gap-2`}
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
`;

const VarCard = styled.div<{ $required: boolean }>`
    ${tw`rounded p-2.5 text-xs`}
    background-color: rgba(255, 255, 255, 0.04);
    border-left: 3px solid ${({ $required }) => ($required ? '#2563eb' : '#374151')};
`;

const SaveFeedback = styled.div<{ $ok: boolean }>`
    ${tw`flex items-center gap-1.5 text-xs px-2`}
    color: ${({ $ok }) => ($ok ? '#22c55e' : '#ef4444')};
`;

// HTML language for Blade (close enough — provides HTML + template-like highlighting)
const htmlLanguage = LanguageDescription.of({ name: 'html', support: html() });

export default () => {
    const [loading, setLoading] = useState(true);
    const [templates, setTemplates] = useState<EmailTemplate[]>([]);
    const [selected, setSelected] = useState<EmailTemplate | null>(null);

    // Preview state
    const [previewHtml, setPreviewHtml] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    // Editor state
    const [editMode, setEditMode] = useState(false);
    const [sourceContent, setSourceContent] = useState<string | null>(null);
    const [sourceLoading, setSourceLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveStatus, setSaveStatus] = useState<{ ok: boolean; msg: string } | null>(null);

    // Variable docs panel
    const [varsOpen, setVarsOpen] = useState(true);

    const { clearFlashes, addFlash } = useFlash();
    const { colors } = useStoreState((state) => state.theme.data!);

    // Ref to read current content from Editor
    const fetchEditorContent = useRef<null | (() => Promise<string>)>(null);

    useEffect(() => {
        clearFlashes('email:templates');
        getEmailTemplates()
            .then(({ templates: list }) => {
                setTemplates(list);
                if (list.length > 0) {
                    loadPreview(list[0]);
                    setSelected(list[0]);
                }
            })
            .catch(() =>
                addFlash({ key: 'email:templates', type: 'error', message: 'Failed to load email templates.' }),
            )
            .finally(() => setLoading(false));
    }, []);

    const loadPreview = (tpl: EmailTemplate) => {
        setPreviewHtml(null);
        setPreviewLoading(true);
        previewEmailTemplate(tpl.key)
            .then((html) => setPreviewHtml(html))
            .catch(() =>
                addFlash({ key: 'email:templates', type: 'error', message: `Failed to render preview for "${tpl.label}".` }),
            )
            .finally(() => setPreviewLoading(false));
    };

    const selectTemplate = (tpl: EmailTemplate) => {
        setSelected(tpl);
        setEditMode(false);
        setSourceContent(null);
        setSaveStatus(null);
        loadPreview(tpl);
    };

    const enterEditMode = () => {
        if (!selected) return;
        setSourceLoading(true);
        setSourceContent(null);
        setSaveStatus(null);
        getEmailTemplateSource(selected.key)
            .then(({ content }) => {
                setSourceContent(content);
                setEditMode(true);
            })
            .catch(() =>
                addFlash({ key: 'email:templates', type: 'error', message: 'Failed to load template source.' }),
            )
            .finally(() => setSourceLoading(false));
    };

    const cancelEdit = () => {
        setEditMode(false);
        setSourceContent(null);
        setSaveStatus(null);
    };

    const handleSave = useCallback(async () => {
        if (!selected || !fetchEditorContent.current) return;
        const content = await fetchEditorContent.current();
        setSaving(true);
        setSaveStatus(null);
        saveEmailTemplateSource(selected.key, content)
            .then(() => {
                setSaveStatus({ ok: true, msg: 'Saved successfully.' });
                setSourceContent(content);
                // Refresh preview after save
                loadPreview(selected);
            })
            .catch(() => {
                setSaveStatus({ ok: false, msg: 'Save failed. Check file permissions.' });
            })
            .finally(() => setSaving(false));
    }, [selected]);

    // Group templates by category for sidebar display.
    const grouped = templates.reduce<Record<string, EmailTemplate[]>>((acc, tpl) => {
        (acc[tpl.category] = acc[tpl.category] ?? []).push(tpl);
        return acc;
    }, {});

    const variables: TemplateVariable[] = selected?.variables ?? [];

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

            {/* Main area */}
            <MainArea>
                {/* Header */}
                <PaneHeader>
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
                    <div css={tw`flex items-center gap-2`}>
                        {saveStatus && (
                            <SaveFeedback $ok={saveStatus.ok}>
                                <FontAwesomeIcon icon={saveStatus.ok ? faCheck : faExclamationTriangle} />
                                {saveStatus.msg}
                            </SaveFeedback>
                        )}
                        {selected && !editMode && (
                            <>
                                <ActionButton onClick={() => loadPreview(selected)} title='Refresh preview' disabled={previewLoading}>
                                    <FontAwesomeIcon icon={faRedo} />
                                    Refresh
                                </ActionButton>
                                <ActionButton $variant='primary' onClick={enterEditMode} disabled={sourceLoading}>
                                    {sourceLoading ? <Spinner size='tiny' /> : <FontAwesomeIcon icon={faPencilAlt} />}
                                    Edit
                                </ActionButton>
                            </>
                        )}
                        {selected && editMode && (
                            <>
                                <ActionButton onClick={cancelEdit} title='Discard changes'>
                                    <FontAwesomeIcon icon={faUndo} />
                                    Discard
                                </ActionButton>
                                <ActionButton $variant='danger' onClick={cancelEdit} title='Close editor'>
                                    <FontAwesomeIcon icon={faTimes} />
                                </ActionButton>
                                <ActionButton $variant='primary' onClick={handleSave} disabled={saving} title='Save template'>
                                    {saving ? <Spinner size='tiny' /> : <FontAwesomeIcon icon={faSave} />}
                                    Save
                                </ActionButton>
                            </>
                        )}
                    </div>
                </PaneHeader>

                {/* Split body: editor (when in edit mode) + preview */}
                <SplitBody>
                    {editMode && sourceContent !== null && (
                        <EditorPane>
                            <div css={tw`text-xs px-3 py-1 border-b border-neutral-700`} style={{ color: '#6b7280', backgroundColor: 'rgba(255,255,255,0.02)' }}>
                                Blade source — {selected?.key}.blade.php
                            </div>
                            <div css={tw`flex-1 overflow-auto`}>
                                <Editor
                                    initialContent={sourceContent}
                                    language={htmlLanguage}
                                    fetchContent={(cb) => { fetchEditorContent.current = cb; }}
                                    onContentSaved={handleSave}
                                    style={{ minHeight: '460px' }}
                                />
                            </div>
                        </EditorPane>
                    )}

                    <PreviewPane>
                        {editMode && (
                            <div css={tw`text-xs px-3 py-1 border-b border-neutral-700`} style={{ color: '#6b7280', backgroundColor: 'rgba(255,255,255,0.02)' }}>
                                Rendered preview (last saved)
                            </div>
                        )}
                        {previewLoading ? (
                            <EmptyPane>
                                <Spinner size='large' />
                            </EmptyPane>
                        ) : previewHtml !== null ? (
                            <StyledIframe srcDoc={previewHtml} title={`Preview: ${selected?.label}`} sandbox='allow-same-origin' />
                        ) : (
                            <EmptyPane>
                                <FontAwesomeIcon icon={faEnvelopeOpenText} size='2x' />
                                <span css={tw`text-sm`}>Select a template to preview it here.</span>
                            </EmptyPane>
                        )}
                    </PreviewPane>
                </SplitBody>

                {/* Variable documentation panel */}
                {variables.length > 0 && (
                    <VarsPanel>
                        <VarsPanelHeader onClick={() => setVarsOpen((v) => !v)}>
                            <span>Template Variables ({variables.length})</span>
                            <FontAwesomeIcon icon={varsOpen ? faChevronUp : faChevronDown} />
                        </VarsPanelHeader>
                        {varsOpen && (
                            <VarsGrid>
                                {variables.map((v) => (
                                    <VarCard key={v.name} $required={v.required}>
                                        <div css={tw`font-mono font-semibold mb-0.5`} style={{ color: '#93c5fd' }}>
                                            {v.name}
                                            {v.required && (
                                                <span css={tw`ml-1.5 text-blue-400 text-xs font-normal`}>required</span>
                                            )}
                                        </div>
                                        <div style={{ color: '#d1d5db' }}>{v.description}</div>
                                        {v.example && (
                                            <div css={tw`mt-1 font-mono text-xs`} style={{ color: '#6b7280' }}>
                                                e.g. {v.example}
                                            </div>
                                        )}
                                    </VarCard>
                                ))}
                            </VarsGrid>
                        )}
                    </VarsPanel>
                )}
            </MainArea>
        </Layout>
    );
};
