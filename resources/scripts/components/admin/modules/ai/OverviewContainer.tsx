import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/ai/ToggleFeatureButton';
import { SparklesIcon } from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';
import { KeyboardEvent as ReactKeyboardEvent, useState, useRef, useEffect, useCallback } from 'react';
import { handleQueryStream } from '@/api/routes/admin/ai/handleQuery';
import { getStats, type AIStats } from '@/api/routes/admin/ai/settings';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import MessageBubble, { type Message } from '@/components/ai/MessageBubble';

const ADMIN_QUICK_ACTIONS = [
    { label: '🔍 Test connection', query: 'Say "Connection successful! I am ready to help with your panel." and nothing else.' },
    { label: '⚙️ Best models?', query: 'What AI models work best for game server log analysis and debugging? Compare OpenAI and Ollama options briefly.' },
    { label: '📋 System prompt tips', query: 'Give me 3 tips for writing an effective system prompt for a game server support assistant.' },
];

function StatCard({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
    const theme = useStoreState(s => s.theme.data!);
    return (
        <div className={'flex flex-col gap-1 rounded-xl border border-neutral-700/60 px-4 py-3'} style={{ backgroundColor: theme.colors.secondary }}>
            <span className={'text-xs text-neutral-500'}>{label}</span>
            <span className={'text-xl font-semibold text-neutral-100'}>{value}</span>
            {sub && <span className={'text-xs text-neutral-500'}>{sub}</span>}
        </div>
    );
}

function Sparkline({ series }: { series: { date: string; requests: number }[] }) {
    const theme = useStoreState(s => s.theme.data!);
    const max = Math.max(...series.map(s => s.requests), 1);
    const H = 32;
    const W = 120;
    const step = W / Math.max(series.length - 1, 1);
    const points = series.map((s, i) => `${i * step},${H - (s.requests / max) * H}`).join(' ');
    return (
        <svg width={W} height={H} viewBox={`0 0 ${W} ${H}`} className={'overflow-visible'}>
            <polyline
                fill={'none'}
                stroke={theme.colors.primary}
                strokeWidth={1.5}
                strokeLinecap={'round'}
                strokeLinejoin={'round'}
                points={points}
            />
        </svg>
    );
}

export default () => {
    const ai = useStoreState(s => s.everest.data!.ai);
    const theme = useStoreState(s => s.theme.data!);

    const [messages, setMessages] = useState<Message[]>([
        {
            role: 'assistant',
            content: `**M12Labs-AI Admin Console**\n\nTest your AI configuration or ask questions about server management.\n\n_Provider: **${ai.mode === 'ollama' ? 'Ollama' : 'OpenAI'}** · Model: **${ai.model || 'default'}**_`,
        },
    ]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    const [stats, setStats] = useState<AIStats | null>(null);
    const [statsLoading, setStatsLoading] = useState(true);

    useEffect(() => {
        getStats()
            .then(setStats)
            .catch(() => {/* stats unavailable — table may not exist yet */})
            .finally(() => setStatsLoading(false));
    }, []);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    useEffect(() => {
        return () => abortRef.current?.abort();
    }, []);

    const sendQuery = useCallback((query: string) => {
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
                    if (last?.role === 'assistant') next[next.length - 1] = { ...last, content: last.content + chunk };
                    return next;
                });
            },
            () => {
                setMessages(prev => {
                    const next = [...prev];
                    const last = next[next.length - 1];
                    if (last?.role === 'assistant') next[next.length - 1] = { ...last, streaming: false };
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
                        next[next.length - 1] = { ...last, content: `**Error:** ${error.message || 'Request failed.'}`, streaming: false };
                    }
                    return next;
                });
                setLoading(false);
                abortRef.current = null;
            },
            abortRef.current.signal,
        );
    }, [loading]);

    const handleKeyDown = (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuery(input); }
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

    const successRate = stats?.all_time?.total_requests
        ? Math.round((stats.all_time.successful / stats.all_time.total_requests) * 100)
        : null;

    return (
        <div className={'space-y-4'}>
            {/* ── Stats row ── */}
            <div className={'grid grid-cols-2 gap-3 sm:grid-cols-4'}>
                <StatCard
                    label={'Requests (24h)'}
                    value={statsLoading ? '…' : (stats?.last_24h?.requests ?? 0)}
                    sub={`${statsLoading ? '…' : (stats?.last_24h?.tokens ?? 0).toLocaleString()} tokens`}
                />
                <StatCard
                    label={'Requests (7d)'}
                    value={statsLoading ? '…' : (stats?.last_7d?.requests ?? 0)}
                    sub={`${statsLoading ? '…' : (stats?.last_7d?.tokens ?? 0).toLocaleString()} tokens`}
                />
                <StatCard
                    label={'All-time requests'}
                    value={statsLoading ? '…' : (stats?.all_time?.total_requests ?? 0).toLocaleString()}
                    sub={successRate !== null ? `${successRate}% success rate` : undefined}
                />
                <StatCard
                    label={'Avg latency'}
                    value={statsLoading ? '…' : stats?.all_time?.avg_latency_ms ? `${stats.all_time.avg_latency_ms}ms` : 'N/A'}
                    sub={`${statsLoading ? '…' : (stats?.all_time?.total_tokens ?? 0).toLocaleString()} total tokens`}
                />
            </div>

            {/* ── 7-day activity + top users ── */}
            {!statsLoading && stats && (
                <div className={'grid gap-3 sm:grid-cols-2'}>
                    <div className={'flex items-center justify-between rounded-xl border border-neutral-700/60 px-4 py-3'} style={{ backgroundColor: theme.colors.secondary }}>
                        <div>
                            <p className={'mb-1 text-xs text-neutral-500'}>Activity — last 7 days</p>
                            <div className={'flex items-center gap-3'}>
                                <Sparkline series={stats.daily_series} />
                                <div className={'space-y-0.5 text-xs'}>
                                    <p><span className={'text-neutral-500'}>Client</span> <span className={'font-medium text-neutral-200'}>{stats.source_breakdown['client'] ?? 0}</span></p>
                                    <p><span className={'text-neutral-500'}>Admin</span> <span className={'font-medium text-neutral-200'}>{stats.source_breakdown['admin'] ?? 0}</span></p>
                                    <p><span className={'text-neutral-500'}>Errors</span> <span className={'font-medium text-red-400'}>{stats.all_time.errors}</span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className={'rounded-xl border border-neutral-700/60 px-4 py-3'} style={{ backgroundColor: theme.colors.secondary }}>
                        <p className={'mb-2 text-xs text-neutral-500'}>Top users (7d)</p>
                        {stats.top_users.length === 0 ? (
                            <p className={'text-xs text-neutral-600'}>No usage data yet</p>
                        ) : (
                            <div className={'space-y-1.5'}>
                                {stats.top_users.map((u, i) => (
                                    <div key={i} className={'flex items-center justify-between'}>
                                        <span className={'text-xs text-neutral-300'}>{u.username}</span>
                                        <span className={'font-mono text-xs text-neutral-500'}>{u.requests} req</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ── Chat + config sidebar ── */}
            <div className={'grid gap-4 lg:grid-cols-5'}>
                <div className={'col-span-3 flex flex-col'}>
                    <div className={'mb-2 flex flex-wrap gap-2'}>
                        {ADMIN_QUICK_ACTIONS.map(action => (
                            <button
                                key={action.label}
                                onClick={() => sendQuery(action.query)}
                                disabled={loading}
                                className={'rounded-full border border-neutral-700 bg-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:bg-neutral-700 hover:text-white disabled:cursor-not-allowed disabled:opacity-50'}
                                onMouseEnter={e => { (e.currentTarget as HTMLButtonElement).style.borderColor = theme.colors.primary; }}
                                onMouseLeave={e => { (e.currentTarget as HTMLButtonElement).style.borderColor = ''; }}
                            >
                                {action.label}
                            </button>
                        ))}
                    </div>

                    <div className={'flex-1 overflow-y-auto rounded-xl p-4'} style={{ minHeight: '32vh', maxHeight: '50vh', backgroundColor: theme.colors.secondary }}>
                        {messages.map((msg, i) => (
                            <MessageBubble key={i} message={msg} />
                        ))}
                        <div ref={bottomRef} />
                    </div>

                    <div className={'mt-3 flex items-end gap-2 rounded-xl border border-neutral-700 px-4 py-3'} style={{ backgroundColor: theme.colors.secondary }}>
                        <textarea
                            ref={inputRef}
                            className={'flex-1 resize-none bg-transparent text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none'}
                            placeholder={'Ask M12Labs-AI anything… (Enter to send, Shift+Enter for newline)'}
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
                                    <Button variant={'secondary'} size={'sm'} onClick={cancelRequest}>Cancel</Button>
                                </>
                            ) : (
                                <Button size={'sm'} onClick={() => sendQuery(input)} disabled={input.trim().length < 1}>Send</Button>
                            )}
                        </div>
                    </div>
                </div>

                <div className={'col-span-2 space-y-4'}>
                    <div className={'rounded-xl border border-neutral-700 p-4'} style={{ backgroundColor: theme.colors.secondary }}>
                        <div className={'mb-3 flex items-center gap-2 text-sm font-medium text-neutral-200'}>
                            <SparklesIcon className={'h-4 w-4'} style={{ color: theme.colors.primary }} />
                            Configuration
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

                    <AdminBox title={'Disable M12Labs-AI'}>
                        Clicking the button below will disable M12Labs-AI for both clients and administrators. Your API key
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
        </div>
    );
};
