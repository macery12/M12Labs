import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/ai/ToggleFeatureButton';
import { SparklesIcon } from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';
import { KeyboardEvent as ReactKeyboardEvent, useState, useRef, useEffect } from 'react';
import { handleQueryStream } from '@/api/routes/admin/ai/handleQuery';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import MessageBubble, { type Message } from '@/components/ai/MessageBubble';

const ADMIN_QUICK_ACTIONS = [
    { label: '🔍 Test with a simple question', query: 'Say "Connection successful! I am ready to help with your Jexactyl panel." and nothing else.' },
    { label: '⚙️ What models work best?', query: 'What AI models work best for game server log analysis and debugging? Compare OpenAI and Ollama options briefly.' },
    { label: '📋 System prompt tips', query: 'Give me 3 tips for writing an effective system prompt for a game server support assistant.' },
];

export default () => {
    const ai = useStoreState(s => s.everest.data!.ai);
    const [messages, setMessages] = useState<Message[]>([
        {
            role: 'assistant',
            content: `**Jexactyl AI Admin Console**\n\nUse this chat to test your AI configuration, ask questions about server management, or verify your setup is working correctly.\n\n_Provider: **${ai.mode === 'ollama' ? 'Ollama' : 'OpenAI'}** — Model: **${ai.model || 'default'}**_`,
        },
    ]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    useEffect(() => {
        return () => abortRef.current?.abort();
    }, []);

    const sendQuery = (query: string) => {
        if (loading || !query.trim()) return;

        const userMsg: Message = { role: 'user', content: query };
        const assistantMsg: Message = { role: 'assistant', content: '', streaming: true };

        setMessages(prev => [...prev, userMsg, assistantMsg]);
        setInput('');
        setLoading(true);

        abortRef.current?.abort();
        abortRef.current = new AbortController();

        handleQueryStream(
            query,
            chunk => {
                setMessages(prev => {
                    const next = [...prev];
                    const last = next[next.length - 1];
                    if (last?.role === 'assistant') {
                        next[next.length - 1] = { ...last, content: last.content + chunk };
                    }
                    return next;
                });
            },
            () => {
                setMessages(prev => {
                    const next = [...prev];
                    const last = next[next.length - 1];
                    if (last?.role === 'assistant') {
                        next[next.length - 1] = { ...last, streaming: false };
                    }
                    return next;
                });
                setLoading(false);
                abortRef.current = null;
            },
            (error: Error) => {
                setMessages(prev => {
                    const next = [...prev];
                    const last = next[next.length - 1];
                    if (last?.role === 'assistant') {
                        next[next.length - 1] = {
                            ...last,
                            content: `**Error:** ${error.message || 'Request failed. Please try again.'}`,
                            streaming: false,
                        };
                    }
                    return next;
                });
                setLoading(false);
                abortRef.current = null;
            },
            abortRef.current.signal,
        );
    };

    const handleKeyDown = (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendQuery(input);
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

    return (
        <div className={'grid gap-4 lg:grid-cols-5'}>
            {/* Chat panel */}
            <div className={'col-span-3 flex flex-col'}>
                {/* Quick actions */}
                <div className={'mb-3 flex flex-wrap gap-2'}>
                    {ADMIN_QUICK_ACTIONS.map(action => (
                        <button
                            key={action.label}
                            onClick={() => sendQuery(action.query)}
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
                <div className={'flex-1 overflow-y-auto rounded-xl bg-neutral-900 p-4'} style={{ minHeight: '40vh', maxHeight: '60vh' }}>
                    {messages.map((msg, i) => (
                        <MessageBubble key={i} message={msg} />
                    ))}
                    <div ref={bottomRef} />
                </div>

                {/* Input */}
                <div className={'mt-3 flex items-end gap-2 rounded-xl border border-neutral-700 bg-neutral-800 px-4 py-3'}>
                    <textarea
                        ref={inputRef}
                        className={'flex-1 resize-none bg-transparent text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none'}
                        placeholder={'Ask Jexactyl AI a question... (Enter to send, Shift+Enter for new line)'}
                        rows={2}
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={handleKeyDown}
                        disabled={loading}
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
                            <Button size={'sm'} onClick={() => sendQuery(input)} disabled={input.trim().length < 1}>
                                Send
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            {/* Sidebar */}
            <div className={'col-span-2 space-y-4'}>
                <div className={'rounded-xl border border-neutral-700 bg-neutral-800/50 p-4'}>
                    <div className={'mb-3 flex items-center gap-2 text-sm font-medium text-neutral-200'}>
                        <SparklesIcon className={'h-4 w-4 text-violet-400'} />
                        Current Configuration
                    </div>
                    <dl className={'space-y-1.5 text-xs'}>
                        <div className={'flex justify-between'}>
                            <dt className={'text-neutral-500'}>Provider</dt>
                            <dd className={'font-medium text-neutral-200'}>{ai.mode === 'ollama' ? 'Ollama' : 'OpenAI'}</dd>
                        </div>
                        <div className={'flex justify-between'}>
                            <dt className={'text-neutral-500'}>Model</dt>
                            <dd className={'font-mono text-neutral-200'}>{ai.model || 'not set'}</dd>
                        </div>
                        <div className={'flex justify-between'}>
                            <dt className={'text-neutral-500'}>Max tokens</dt>
                            <dd className={'text-neutral-200'}>{ai.max_tokens ?? 500}</dd>
                        </div>
                        <div className={'flex justify-between'}>
                            <dt className={'text-neutral-500'}>Temperature</dt>
                            <dd className={'text-neutral-200'}>{ai.temperature ?? 0.3}</dd>
                        </div>
                        <div className={'flex justify-between'}>
                            <dt className={'text-neutral-500'}>User access</dt>
                            <dd className={ai.user_access ? 'text-green-400' : 'text-neutral-400'}>
                                {ai.user_access ? 'Enabled' : 'Disabled'}
                            </dd>
                        </div>
                    </dl>
                </div>

                <AdminBox title={'Disable Jexactyl AI'}>
                    Clicking the button below will disable Jexactyl AI for both clients and administrators. Your API key
                    will remain in the database unless you choose to delete it manually.
                    <div className={'mt-2 text-right'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>

                <p className={'text-xs text-neutral-500'}>
                    AI responses may be inaccurate. API usage is subject to your provider's rate limits and billing.
                </p>
            </div>
        </div>
    );
};

interface Props {
    primary: string;
    loading: boolean;
    result: string | undefined;
}

function DisplayMessage({ primary, result, loading }: Props) {
    if (result && result !== 'error') {
        return (
            <>
                <SparklesIcon className={'inline-flex h-4 w-4'} style={{ color: primary }} />
                <div className={'whitespace-pre-wrap'}>{result}</div>
            </>
        );
    }

    if (result && result === 'error') {
        return (
            <>
                <XCircleIcon className={'inline-flex h-4 w-4 text-red-400'} /> An error occurred. Please try again
                later.
            </>
        );
    }

    if (loading) {
        return (
            <>
                <Spinner className={'my-auto inline-flex'} size={'small'} />
                <span className={'ml-2 animate-pulse'}>...</span>
            </>
        );
    }

    return (
        <>
            <SparklesIcon className={'inline-flex h-4 w-4'} style={{ color: primary }} /> waiting for query
        </>
    );
}

export default () => {
    const [result, setResult] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(false);
    const [query, setQuery] = useState<string>('');
    const { primary } = useStoreState(s => s.theme.data!.colors);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:ai');
    const abortControllerRef = useRef<AbortController | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    const cancelRequest = () => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
            abortControllerRef.current = null;
        }
        setLoading(false);
    };

    const submit = () => {
        if (query.trim().length < 1) return;

        clearFlashes();
        setLoading(true);
        setResult('');

        // Cancel any existing request
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        // Create new abort controller
        abortControllerRef.current = new AbortController();

        handleQueryStream(
            query,
            chunk => {
                setResult(prev => prev + chunk);
            },
            () => {
                setLoading(false);
                abortControllerRef.current = null;
            },
            error => {
                setResult('error');
                setLoading(false);
                clearAndAddHttpError(error);
                abortControllerRef.current = null;
            },
            abortControllerRef.current.signal,
        );
    };

    const handleKeyDown = (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    return (
        <div className={'grid gap-4 lg:grid-cols-5'}>
            <div className={'col-span-3'}>
                <div className={'relative h-full min-h-[50vh] overflow-auto rounded-t bg-black shadow-xl'}>
                    <div className={'absolute top-0 left-0 w-full p-2 font-mono text-sm'}>
                        <DisplayMessage primary={primary} loading={loading} result={result || undefined} />
                    </div>
                </div>
                <div className={'flex w-full flex-col rounded-b bg-zinc-800 px-4 py-2'}>
                    <div className={'flex items-start'}>
                        <FontAwesomeIcon icon={faChevronRight} className={'mt-2 mr-4 flex-shrink-0'} />
                        <textarea
                            ref={textareaRef}
                            className={
                                'flex-1 resize-none border-none bg-transparent font-mono text-sm focus:outline-none focus:ring-0'
                            }
                            placeholder={'Ask Jexactyl AI a question (Shift+Enter for new line, Enter to send)'}
                            rows={3}
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            onKeyDown={handleKeyDown}
                            disabled={loading}
                        />
                    </div>
                    <div className={'mt-2 flex justify-end space-x-2'}>
                        {loading && (
                            <Button variant={'secondary'} size={'sm'} onClick={cancelRequest}>
                                Cancel
                            </Button>
                        )}
                        <Button size={'sm'} onClick={submit} disabled={loading || query.trim().length < 1}>
                            Send
                        </Button>
                    </div>
                </div>
            </div>
            <div className={'col-span-2 space-y-4'}>
                <Alert type={'warning'} className={'mt-16 md:mt-0'}>
                    Jexactyl AI uses OpenAI-compatible endpoints. Information provided could be inaccurate or outdated.
                    Use with caution!
                </Alert>
                <Alert type={'info'}>
                    API request limits depend on your AI provider. Check with your provider for rate limiting details.
                </Alert>
                <Alert type={'info'}>
                    <div className={'text-sm'}>
                        <strong>Streaming enabled:</strong> Responses stream in real-time to prevent timeouts. You can
                        cancel ongoing requests at any time.
                    </div>
                </Alert>
                <AdminBox title={'Disable Jexactyl AI'} className={'col-span-2 h-min'}>
                    Clicking the button below will disable Jexactyl AI for both clients and administrators. Your API key
                    will remain in the database unless you choose to delete it manually.
                    <div className={'mt-2 text-right'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
