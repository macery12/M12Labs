import { m } from '@/i18n/messages';
import { Markdown } from '@/extensions-sdk';

export function ChatMarkdown({ content }: { content: string }) {
    return (
        <Markdown
            content={content}
            copyLabel={m['common.actions.copy']()}
            copiedLabel={m['common.states.copied']()}
        />
    );
}
