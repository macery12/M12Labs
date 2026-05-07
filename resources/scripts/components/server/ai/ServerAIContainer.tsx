import stripAnsi from 'strip-ansi';
import { useEffect, useRef, useState, KeyboardEvent } from 'react';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import { SparklesIcon } from '@heroicons/react/outline';
import { SocketEvent } from '@server/events';
import { handleQueryStream } from '@/api/routes/server/ai';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import PageContentBlock from '@/elements/PageContentBlock';
import MessageBubble, { type Message } from '@/components/ai/MessageBubble';

const MAX_LOG_LINES = 100;
const MAX_LOG_CHARS = 12000;

const QUICK_ACTIONS = [
    { label: '🔍 Analyze recent logs', type: 'log_analysis' as const },
    { label: "❓ Why won't my server start?", query: "Why won't my server start? What should I check?", type: 'freeform' as const },
    { label: '⚡ How do I improve performance?', query: "How can I improve this server's performance and reduce lag?", type: 'freeform' as const },
    { label: '🔧 Common configuration tips', query: 'What are the most important configuration settings I should know about for this server type?', type: 'freeform' as const },
];

export default function ServerAIContainer() {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const serverName = ServerContext.useStoreState(state => state.server.data!.name);
    const egg = ServerContext.useStoreState(state => state.server.data!.egg);
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);

    const isEnabled = useStoreState(state => state.everest.data!.ai.enabled);
    const userAccess = useStoreState(state => state.everest.data!.ai.user_access);
    const user = useStoreState(state => state.user.data!);

    const [messages, setMessages] = useState<Message[]>([
        {
            role: 'assistant',
            content: `Hello! I'm your server assistant for **${serverName}**. I can help you diagnose crashes, answer configuration questions, and provide guidance specific to your server setup.\n\nYou can type a question below or use one of the quick actions to get started.`,
        },
    ]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [log, setLog] = useState<string[]>([]);

    const abortRef = useRef<AbortController | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    // Collect console logs for log analysis
    useEffect(() => {
        if (!connected || !instance) return;

        const listener = (line: string) => {
            setLog(prev => [...prev.slice(-MAX_LOG_LINES), line.startsWith('>') ? line.substring(1) : line]);
        };

        instance.addListener(SocketEvent.CONSOLE_OUTPUT, listener);
        return () => instance.removeListener(SocketEvent.CONSOLE_OUTPUT, listener);
    }, [connected, instance]);

    // Scroll to bottom when new messages arrive
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    // Cleanup on unmount
    useEffect(() => {
        return () => abortRef.current?.abort();
    }, []);

    const sendQuery = (query: string, queryType: 'log_analysis' | 'freeform') => {
        if (loading) return;

        const userMessage: Message = { role: 'user', content: query };
        const assistantMessage: Message = { role: 'assistant', content: '', streaming: true };

        // Capture current history (excluding the initial greeting, completed messages only)
        // before state update so we can send it to the backend for multi-turn context
        setMessages(prev => {
            const history = prev
                .filter(m => !m.streaming && m.content.length > 0)
                .slice(-10)
                .map(m => ({ role: m.role, content: m.content }));

            // Kick off the stream with the current history
            abortRef.current?.abort();
            abortRef.current = new AbortController();

            handleQueryStream(
                uuid,
                query,
                queryType,
                chunk => {
                    setMessages(curr => {
                        const next = [...curr];
                        const last = next[next.length - 1];
                        if (last && last.role === 'assistant') {
                            next[next.length - 1] = { ...last, content: last.content + chunk };
                        }
                        return next;
                    });
                },
                () => {
                    setMessages(curr => {
                        const next = [...curr];
                        const last = next[next.length - 1];
                        if (last && last.role === 'assistant') {
                            next[next.length - 1] = { ...last, streaming: false };
                        }
                        return next;
                    });
                    setLoading(false);
                    abortRef.current = null;
                },
                error => {
                    setMessages(curr => {
                        const next = [...curr];
                        const last = next[next.length - 1];
                        if (last && last.role === 'assistant') {
                            next[next.length - 1] = {
                                ...last,
                                content: `Error: ${error.message || 'Failed to get a response. Please try again.'}`,
                                streaming: false,
                            };
                        }
                        return next;
                    });
                    setLoading(false);
                    abortRef.current = null;
                },
                abortRef.current.signal,
                history,
            );

            return [...prev, userMessage, assistantMessage];
        });

        setInput('');
        setLoading(true);
    };

    const handleSend = () => {
        const q = input.trim();
        if (!q || loading) return;
        sendQuery(q, 'freeform');
    };

    const handleQuickAction = (action: (typeof QUICK_ACTIONS)[number]) => {
        if (loading) return;
        if (action.type === 'log_analysis') {
            let logData = stripAnsi(log.map(l => l.replace('\r', '')).join('\n'));
            if (!logData.trim()) {
                setMessages(prev => [
                    ...prev,
                    {
                        role: 'assistant',
                        content: 'No console output has been captured yet. Start or interact with your server first, then try again.',
                    },
                ]);
                return;
            }
            if (logData.length > MAX_LOG_CHARS) {
                logData = logData.slice(logData.length - MAX_LOG_CHARS);
            }
            sendQuery(logData, 'log_analysis');
        } else {
            sendQuery(action.query!, action.type);
        }
    };

    const handleKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend();
        }
    };

    const cancelRequest = () => {
        abortRef.current?.abort();
        abortRef.current = null;
        setLoading(false);
        setMessages(prev => {
            const next = [...prev];
            const last = next[next.length - 1];
            if (last?.role === 'assistant' && last.streaming) {
                next[next.length - 1] = { ...last, content: last.content + '\n\n*(cancelled)*', streaming: false };
            }
            return next;
        });
    };

    if (!isEnabled) {
        return (
            <PageContentBlock title={'AI Assistant'}>
                <div className={'flex flex-col items-center justify-center py-20 text-neutral-400'}>
                    <SparklesIcon className={'mb-4 h-12 w-12 opacity-30'} />
                    <p className={'text-lg font-medium'}>AI Assistant is not enabled</p>
                    <p className={'mt-1 text-sm'}>An administrator must enable Jexactyl AI from the admin panel.</p>
                </div>
            </PageContentBlock>
        );
    }

    if (!userAccess && !user.rootAdmin && !user.admin_role_id) {
        return (
            <PageContentBlock title={'AI Assistant'}>
                <div className={'flex flex-col items-center justify-center py-20 text-neutral-400'}>
                    <SparklesIcon className={'mb-4 h-12 w-12 opacity-30'} />
                    <p className={'text-lg font-medium'}>Access restricted</p>
                    <p className={'mt-1 text-sm'}>AI access for standard users has not been enabled by your administrator.</p>
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'AI Assistant'}>
            <div className={'flex h-[calc(100vh-14rem)] flex-col'}>
                {/* Quick action chips */}
                <div className={'mb-3 flex flex-wrap gap-2'}>
                    {QUICK_ACTIONS.map(action => (
                        <button
                            key={action.label}
                            onClick={() => handleQuickAction(action)}
                            disabled={loading}
                            className={
                                'rounded-full border border-neutral-700 bg-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-violet-500 hover:bg-neutral-700 hover:text-white disabled:cursor-not-allowed disabled:opacity-50'
                            }
                        >
                            {action.label}
                        </button>
                    ))}
                </div>

                {/* Messages */}
                <div className={'flex-1 overflow-y-auto rounded-xl bg-neutral-900 p-4'}>
                    {messages.map((msg, i) => (
                        <MessageBubble key={i} message={msg} />
                    ))}
                    <div ref={bottomRef} />
                </div>

                {/* Input area */}
                <div className={'mt-3 flex items-end gap-2 rounded-xl border border-neutral-700 bg-neutral-800 px-4 py-3'}>
                    <textarea
                        ref={inputRef}
                        className={'flex-1 resize-none bg-transparent text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none'}
                        placeholder={'Ask about your server... (Enter to send, Shift+Enter for new line)'}
                        rows={2}
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={handleKeyDown}
                        disabled={loading}
                        autoFocus
                    />
                    <div className={'flex flex-shrink-0 items-center gap-2'}>
                        {loading ? (
                            <>
                                <Spinner size={'small'} />
                                <Button variant={'secondary'} size={'sm'} onClick={cancelRequest}>
                                    Cancel
                                </Button>
                            </>
                        ) : (
                            <Button size={'sm'} onClick={handleSend} disabled={input.trim().length < 1}>
                                Send
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </PageContentBlock>
    );
}
