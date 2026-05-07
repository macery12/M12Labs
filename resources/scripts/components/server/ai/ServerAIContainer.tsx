import stripAnsi from 'strip-ansi';
import { useEffect, useRef, useState, KeyboardEvent, useCallback } from 'react';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import { PlusIcon, SparklesIcon, TrashIcon, MenuIcon, BookmarkIcon } from '@heroicons/react/outline';
import { SocketEvent } from '@server/events';
import { handleQueryStream } from '@/api/routes/server/ai';
import {
    listConversations,
    createConversation,
    loadConversation,
    deleteConversation as apiDeleteConversation,
    toggleSaveConversation,
    appendMessages,
    type Conversation,
} from '@/api/routes/server/aiConversations';
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

const GREETING = (name: string) =>
    `Hello! I'm your server assistant for **${name}**. I can help you diagnose crashes, answer configuration questions, and provide guidance specific to your server setup.\n\nYou can type a question below or use one of the quick actions to get started.`;

export default function ServerAIContainer() {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const serverName = ServerContext.useStoreState(state => state.server.data!.name);
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);

    const isEnabled = useStoreState(state => state.everest.data!.ai.enabled);
    const userAccess = useStoreState(state => state.everest.data!.ai.user_access);
    const user = useStoreState(state => state.user.data!);
    const theme = useStoreState(state => state.theme.data!);

    const [messages, setMessages] = useState<Message[]>([
        { role: 'assistant', content: GREETING(serverName) },
    ]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [log, setLog] = useState<string[]>([]);

    // Conversation sidebar state
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [activeConversationId, setActiveConversationId] = useState<number | null>(null);
    const [conversationsLoading, setConversationsLoading] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(true);

    const abortRef = useRef<AbortController | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const pendingExchangeRef = useRef<{ userContent: string; assistantContent: string } | null>(null);

    // Load conversation list
    const refreshConversations = useCallback(() => {
        setConversationsLoading(true);
        listConversations(uuid)
            .then(setConversations)
            .catch(() => {/* silent */})
            .finally(() => setConversationsLoading(false));
    }, [uuid]);

    useEffect(() => {
        refreshConversations();
    }, [refreshConversations]);

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

    const startNewChat = () => {
        if (loading) return;
        setActiveConversationId(null);
        setMessages([{ role: 'assistant', content: GREETING(serverName) }]);
        setInput('');
        inputRef.current?.focus();
    };

    const openConversation = (conv: Conversation) => {
        if (loading) return;
        setActiveConversationId(conv.id);
        setMessages([]);
        loadConversation(uuid, conv.id)
            .then(({ messages: msgs }) => {
                setMessages(
                    msgs.length === 0
                        ? [{ role: 'assistant', content: GREETING(serverName) }]
                        : msgs.map(m => ({ role: m.role, content: m.content })),
                );
            })
            .catch(() => {
                setMessages([{ role: 'assistant', content: 'Failed to load conversation.' }]);
            });
    };

    const removeConversation = (e: React.MouseEvent, conv: Conversation) => {
        e.stopPropagation();
        apiDeleteConversation(uuid, conv.id).then(() => {
            if (activeConversationId === conv.id) startNewChat();
            setConversations(prev => prev.filter(c => c.id !== conv.id));
        });
    };

    const handleToggleSave = (e: React.MouseEvent, conv: Conversation) => {
        e.stopPropagation();
        toggleSaveConversation(uuid, conv.id).then(updated => {
            setConversations(prev =>
                prev.map(c =>
                    c.id === updated.id
                        ? { ...c, is_saved: updated.is_saved, expires_at: updated.expires_at }
                        : c,
                ),
            );
        });
    };

    const persistExchange = (conversationId: number) => {
        const exchange = pendingExchangeRef.current;
        if (!exchange || !exchange.assistantContent) return;
        appendMessages(uuid, conversationId, [
            { role: 'user', content: exchange.userContent },
            { role: 'assistant', content: exchange.assistantContent },
        ]).catch(() => undefined);
        refreshConversations();
        pendingExchangeRef.current = null;
    };

    const sendQuery = (query: string, queryType: 'log_analysis' | 'freeform') => {
        if (loading) return;

        const userMessage: Message = { role: 'user', content: query };
        const assistantMessage: Message = { role: 'assistant', content: '', streaming: true };

        pendingExchangeRef.current = { userContent: query, assistantContent: '' };

        // Capture current activeConversationId synchronously for the closure
        const currentConvId = activeConversationId;

        setMessages(prev => {
            const history = prev
                .filter(m => !m.streaming && m.content.length > 0)
                .slice(-10)
                .map(m => ({ role: m.role, content: m.content }));

            abortRef.current?.abort();
            abortRef.current = new AbortController();

            handleQueryStream(
                uuid,
                query,
                queryType,
                chunk => {
                    if (pendingExchangeRef.current) {
                        pendingExchangeRef.current.assistantContent += chunk;
                    }
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

                    // Persist to DB
                    if (currentConvId !== null) {
                        persistExchange(currentConvId);
                    } else {
                        createConversation(uuid, query.slice(0, 80))
                            .then(conv => {
                                setActiveConversationId(conv.id);
                                persistExchange(conv.id);
                            })
                            .catch(() => { pendingExchangeRef.current = null; });
                    }
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
                    pendingExchangeRef.current = null;
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
        pendingExchangeRef.current = null;
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
                    <p className={'mt-1 text-sm'}>An administrator must enable M12Labs-AI from the admin panel.</p>
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
            <div className={'flex h-[calc(100vh-14rem)] gap-3'}>
                {/* Conversation sidebar */}
                {sidebarOpen && (
                    <div
                        className={'flex w-52 flex-shrink-0 flex-col overflow-hidden rounded-xl'}
                        style={{ backgroundColor: theme.colors.secondary }}
                    >
                        <button
                            onClick={startNewChat}
                            className={'flex items-center gap-2 border-b border-neutral-700/50 px-3 py-3 text-sm font-medium text-neutral-200 transition-colors hover:bg-white/5'}
                        >
                            <PlusIcon className={'h-4 w-4 flex-shrink-0'} />
                            New Chat
                        </button>

                        <div className={'flex-1 overflow-y-auto py-1'}>
                            {conversationsLoading && (
                                <div className={'flex justify-center py-4'}>
                                    <Spinner size={'small'} />
                                </div>
                            )}
                            {!conversationsLoading && conversations.length === 0 && (
                                <p className={'px-3 py-4 text-center text-xs text-neutral-500'}>No saved chats yet</p>
                            )}
                            {conversations.map(conv => (
                                <div
                                    key={conv.id}
                                    onClick={() => openConversation(conv)}
                                    className={`group flex cursor-pointer flex-col px-3 py-2 text-xs transition-colors hover:bg-white/5 ${activeConversationId === conv.id ? 'bg-white/10 text-white' : 'text-neutral-400 hover:text-neutral-200'}`}
                                >
                                    <div className={'flex items-center gap-1'}>
                                        <span className={'min-w-0 flex-1 truncate'}>{conv.title}</span>
                                        <button
                                            onClick={e => handleToggleSave(e, conv)}
                                            className={`ml-1 flex-shrink-0 transition-opacity hover:text-violet-400 ${conv.is_saved ? 'text-violet-400 opacity-100' : 'opacity-0 group-hover:opacity-100'}`}
                                            title={conv.is_saved ? 'Unsave chat' : 'Save chat'}
                                        >
                                            <BookmarkIcon className={'h-3 w-3'} />
                                        </button>
                                        <button
                                            onClick={e => removeConversation(e, conv)}
                                            className={'ml-0.5 flex-shrink-0 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-400'}
                                            title={'Delete conversation'}
                                        >
                                            <TrashIcon className={'h-3 w-3'} />
                                        </button>
                                    </div>
                                    {!conv.is_saved && conv.expires_at && (
                                        <span className={'mt-0.5 text-neutral-600'}>
                                            expires {new Date(conv.expires_at).toLocaleDateString()}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Main chat */}
                <div className={'flex min-w-0 flex-1 flex-col'}>
                    {/* Top bar: sidebar toggle + quick actions */}
                    <div className={'mb-3 flex flex-wrap items-center gap-2'}>
                        <button
                            onClick={() => setSidebarOpen(o => !o)}
                            className={'rounded-full border border-neutral-700 bg-neutral-800 p-1.5 text-neutral-400 transition-colors hover:bg-neutral-700 hover:text-white'}
                            title={sidebarOpen ? 'Hide history' : 'Show history'}
                        >
                            <MenuIcon className={'h-3.5 w-3.5'} />
                        </button>
                        {QUICK_ACTIONS.map(action => (
                            <button
                                key={action.label}
                                onClick={() => handleQuickAction(action)}
                                disabled={loading}
                                className={'rounded-full border border-neutral-700 bg-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-violet-500 hover:bg-neutral-700 hover:text-white disabled:cursor-not-allowed disabled:opacity-50'}
                            >
                                {action.label}
                            </button>
                        ))}
                    </div>

                    {/* Messages */}
                    <div
                        className={'flex-1 overflow-y-auto rounded-xl p-4'}
                        style={{ backgroundColor: theme.colors.secondary }}
                    >
                        {messages.map((msg, i) => (
                            <MessageBubble key={i} message={msg} />
                        ))}
                        <div ref={bottomRef} />
                    </div>

                    {/* Input area */}
                    <div
                        className={'mt-3 flex items-end gap-2 rounded-xl border border-neutral-700 px-4 py-3'}
                        style={{ backgroundColor: theme.colors.secondary }}
                    >
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
            </div>
        </PageContentBlock>
    );
}
