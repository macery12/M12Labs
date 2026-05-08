import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/ai/ToggleFeatureButton';
import { SparklesIcon, RefreshIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';
import { KeyboardEvent as ReactKeyboardEvent, useState, useRef, useEffect, useCallback } from 'react';
import { handleQueryStream } from '@/api/routes/admin/ai/handleQuery';
import { getStats, getRecentLogs, testConnection, type AIStats, type AILogEntry } from '@/api/routes/admin/ai/settings';
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
            <polyline fill={'none'} stroke={theme.colors.primary} strokeWidth={1.5} strokeLinecap={'round'} strokeLinejoin={'round'} points={points} />
        </svg>
    );
}

type ConnStatus = { state: 'idle' | 'testing' | 'ok' | 'error'; latency?: number; message?: string };

function ConnectionCard({ ai }: { ai: any }) {
    const theme = useStoreState(s => s.theme.data!);
    const [conn, setConn] = useState<ConnStatus>({ state: 'idle' });

    const runTest = useCallback(() => {
        setConn({ state: 'testing' });
        testConnection()
            .then(r => setConn(r.status === 'ok' ? { state: 'ok', latency: r.latency_ms } : { state: 'error', message: r.message }))
            .catch(() => setConn({ state: 'error', message: 'Request failed' }));
    }, []);

    useEffect(() => { runTest(); }, [runTest]);

    const icon = conn.state === 'ok'
        ? <CheckCircleIcon className={'h-5 w-5 text-green-400'} />
        : conn.state === 'error'
            ? <XCircleIcon className={'h-5 w-5 text-red-400'} />
            : <span className={'flex h-5 w-5 items-center justify-center'}><Spinner size={'xsmall'} /></span>;

    const label = conn.state === 'ok'
        ? <span className={'text-green-400'}>Connected{conn.latency !== undefined ? ` · ${conn.latency}ms` : ''}</span>
        : conn.state === 'error'
            ? <span className={'text-red-400'}>Error — {conn.message}</span>
            : <span className={'text-neutral-500'}>{conn.state === 'testing' ? 'Testing…' : 'Idle'}</span>;

    return (
        <div className={'flex h-full items-center justify-between rounded-xl border border-neutral-700/60 px-4 py-3'} style={{ backgroundColor: theme.colors.secondary }}>
            <div className={'flex items-center gap-3'}>
                {icon}
                <div>
                    <p className={'text-xs font-medium text-neutral-200'}>
                        {ai.mode === 'ollama' ? 'Ollama' : 'OpenAI'} · <span className={'font-mono'}>{ai.model || 'no model'}</span>
                    </p>
                    <p className={'truncate text-xs text-neutral-500'} style={{ maxWidth: '200px' }}>{ai.endpoint}</p>
                    <p className={'mt-0.5 text-xs'}>{label}</p>
                </div>
            </div>
            <button
                onClick={runTest}
                disabled={conn.state === 'testing'}
                className={'ml-4 flex-shrink-0 rounded-lg border border-neutral-700 p-1.5 text-neutral-400 transition-colors hover:border-neutral-500 hover:text-neutral-200 disabled:opacity-40'}
                title={'Re-test connection'}
            >
                <RefreshIcon className={'h-4 w-4' + (conn.state === 'testing' ? ' animate-spin' : '')} />
            </button>
        </div>
    );
}

function RecentLogsTable({ logs, loading }: { logs: AILogEntry[]; loading: boolean }) {
    const theme = useStoreState(s => s.theme.data!);
    return (
        <div className={'rounded-xl border border-neutral-700/60 overflow-hidden'} style={{ backgroundColor: theme.colors.secondary }}>
            <div className={'flex items-center justify-between border-b border-neutral-700/60 px-4 py-2.5'}>
                <p className={'text-xs font-medium text-neutral-300'}>Recent Requests</p>
                <p className={'text-xs text-neutral-600'}>Last 30</p>
            </div>
            {loading ? (
                <div className={'flex justify-center py-6'}><Spinner size={'small'} /></div>
            ) : logs.length === 0 ? (
                <p className={'px-4 py-6 text-center text-xs text-neutral-600'}>No requests logged yet</p>
            ) : (
                <div className={'overflow-x-auto'}>
                    <table className={'w-full text-xs'}>
                        <thead>
                            <tr className={'border-b border-neutral-700/40 text-left text-neutral-500'}>
                                <th className={'px-3 py-2 font-normal'}>Time</th>
                                <th className={'px-3 py-2 font-normal'}>User</th>
                                <th className={'px-3 py-2 font-normal'}>Server</th>
                                <th className={'px-3 py-2 font-normal'}>Model</th>
                                <th className={'px-3 py-2 font-normal'}>Src</th>
                                <th className={'px-3 py-2 font-normal'}>Tokens</th>
                                <th className={'px-3 py-2 font-normal'}>Latency</th>
                                <th className={'px-3 py-2 font-normal'}>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map(log => (
                                <tr key={log.id} className={'border-b border-neutral-700/20 hover:bg-neutral-800/30'}>
                                    <td className={'whitespace-nowrap px-3 py-1.5 font-mono text-neutral-500'}>
                                        {new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        <span className={'ml-1 text-neutral-700'}>{new Date(log.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' })}</span>
                                    </td>
                                    <td className={'px-3 py-1.5 text-neutral-300'}>{log.username}</td>
                                    <td className={'px-3 py-1.5 text-neutral-500'}>{log.server_name ?? <span className={'italic text-neutral-700'}>—</span>}</td>
                                    <td className={'px-3 py-1.5 font-mono text-neutral-400'}>{log.model}</td>
                                    <td className={'px-3 py-1.5'}>
                                        <span className={'rounded px-1.5 py-0.5 ' + (log.source === 'admin' ? 'bg-purple-900/40 text-purple-300' : 'bg-blue-900/40 text-blue-300')}>
                                            {log.source}
                                        </span>
                                    </td>
                                    <td className={'px-3 py-1.5 font-mono text-neutral-400'}>{log.total_tokens ?? '—'}</td>
                                    <td className={'px-3 py-1.5 font-mono text-neutral-400'}>{log.latency_ms != null ? `${log.latency_ms}ms` : '—'}</td>
                                    <td className={'px-3 py-1.5'}>
                                        {log.status === 'success'
                                            ? <span className={'text-green-400'}>✓</span>
                                            : <span className={'text-red-400'} title={log.error_message ?? undefined}>✗</span>
                                        }
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
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
    const [logs, setLogs] = useState<AILogEntry[]>([]);
    const [logsLoading, setLogsLoading] = useState(true);

    useEffect(() => {
        getStats().then(setStats).catch(() => undefined).finally(() => setStatsLoading(false));
        getRecentLogs().then(setLogs).catch(() => undefined).finally(() => setLogsLoading(false));
    }, []);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    useEffect(() => { return () => abortRef.current?.abort(); }, []);

    const sendQuery = useCallback((query: string) => {
        if (loading || !query.trim()) return;
        setMessages(prev => [...prev, { role: 'user', content: query }, { role: 'assistant', content: '', streaming: true }]);
        setInput('');
        setLoading(true);
        abortRef.current?.abort();
        abortRef.current = new AbortController();

        handleQueryStream(
            query,
            chunk => setMessages(prev => {
                const next = [...prev];
                const last = next[next.length - 1];
                if (last?.role === 'assistant') next[next.length - 1] = { ...last, content: last.content + chunk };
                return next;
            }),
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
                    if (last?.role === 'assistant') next[next.length - 1] = { ...last, content: `**Error:** ${error.message || 'Request failed.'}`, streaming: false };
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
            if (last?.role === 'assistant' && last.streaming) next[next.length - 1] = { ...last, content: last.content + '\n\n*(cancelled)*', streaming: false };
            return next;
        });
    };

    const successRate = stats?.all_time?.total_requests
        ? Math.round((stats.all_time.successful / stats.all_time.total_requests) * 100)
        : null;

    return (
        <div className={'space-y-4'}>
            {/* ── Connection status + stat cards ── */}
            <div className={'grid grid-cols-1 gap-3 sm:grid-cols-5'}>
                <div className={'sm:col-span-2'}>
                    <ConnectionCard ai={ai} />
                </div>
                <StatCard label={'Requests (24h)'} value={statsLoading ? '…' : (stats?.last_24h?.requests ?? 0)} sub={`${statsLoading ? '…' : (stats?.last_24h?.tokens ?? 0).toLocaleString()} tokens`} />
                <StatCard label={'Requests (7d)'} value={statsLoading ? '…' : (stats?.last_7d?.requests ?? 0)} sub={`${statsLoading ? '…' : (stats?.last_7d?.tokens ?? 0).toLocaleString()} tokens`} />
                <StatCard label={'All-time'} value={statsLoading ? '…' : (stats?.all_time?.total_requests ?? 0).toLocaleString()} sub={successRate !== null ? `${successRate}% success · avg ${stats?.all_time?.avg_latency_ms ?? '?'}ms` : undefined} />
            </div>

            {/* ── Activity sparkline + top users ── */}
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

            {/* ── Recent request log ── */}
            <RecentLogsTable logs={logs} loading={logsLoading} />

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
                    <div className={'flex-1 overflow-y-auto rounded-xl p-4'} style={{ minHeight: '28vh', maxHeight: '44vh', backgroundColor: theme.colors.secondary }}>
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
