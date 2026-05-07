import Field, { TextareaField } from '@/elements/Field';
import { Field as FormikField, Form, Formik, useFormikContext } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faTrash, faWifi, faMicrochip, faShieldAlt, faSliders, faRobot } from '@fortawesome/free-solid-svg-icons';
import { AISettings, updateSettings, testConnection, type ConnectionTestResult } from '@/api/routes/admin/ai/settings';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

// --- Model preset chips ---------------------------------------------------

const OPENAI_PRESETS = [
    { label: 'GPT-4.1 mini', value: 'gpt-4.1-mini', note: 'Fast & cheap, great for most tasks' },
    { label: 'GPT-4.1', value: 'gpt-4.1', note: 'Most capable OpenAI model' },
    { label: 'GPT-4o mini', value: 'gpt-4o-mini', note: 'Good balance of speed and quality' },
    { label: 'GPT-3.5 Turbo', value: 'gpt-3.5-turbo', note: 'Older, very cheap' },
];

const OLLAMA_PRESETS = [
    { label: 'phi3:mini', value: 'phi3:mini', note: '~2GB, fast, great for log reading' },
    { label: 'qwen2.5:3b', value: 'qwen2.5:3b', note: '~2GB, strong reasoning' },
    { label: 'gemma2:2b', value: 'gemma2:2b', note: '~1.6GB, good all-rounder' },
    { label: 'llama3.2:3b', value: 'llama3.2:3b', note: '~2GB, Meta latest small model' },
    { label: 'deepseek-coder:1.3b', value: 'deepseek-coder:1.3b', note: '~1GB, code & config focused' },
    { label: 'mistral:7b', value: 'mistral:7b', note: '~4GB, strong general purpose' },
];

// --- Temperature slider ---------------------------------------------------

const TEMP_LABELS: [number, string, string][] = [
    [0.0, 'Deterministic', 'Exact, repeatable answers. Best for log analysis.'],
    [0.3, 'Focused', 'Mostly factual with slight variation. Recommended.'],
    [0.7, 'Balanced', 'More varied responses, still coherent.'],
    [1.0, 'Creative', 'Highly varied. Good for brainstorming, not debugging.'],
];

function tempLabel(val: number): { label: string; hint: string } {
    const closest = TEMP_LABELS.reduce((a, b) => (Math.abs(b[0] - val) < Math.abs(a[0] - val) ? b : a));
    return { label: closest[1], hint: closest[2] };
}

// --- Inner form component (uses useFormikContext) -------------------------

function SettingsForm({
    ai,
    deletingKey,
    testing,
    testResult,
    onDeleteKey,
    onTestConnection,
}: {
    ai: ReturnType<typeof useStoreState<any>>;
    deletingKey: boolean;
    testing: boolean;
    testResult: ConnectionTestResult | null;
    onDeleteKey: () => void;
    onTestConnection: () => void;
}) {
    const { values, setFieldValue } = useFormikContext<AISettings>();
    const presets = values.mode === 'ollama' ? OLLAMA_PRESETS : OPENAI_PRESETS;
    const tempInfo = tempLabel(Number(values.temperature ?? 0.3));
    const systemPromptLen = String(values.system_prompt ?? '').length;
    const MAX_PROMPT = 1000;

    return (
        <Form>
            <AdminBox title={'Provider'} icon={faMicrochip} className={'mb-4'}>
                <div className={'grid gap-4 md:grid-cols-3'}>
                    <div>
                        <label className={'mb-1.5 block text-xs font-medium text-neutral-300'}>Provider Mode</label>
                        <FormikField
                            as="select"
                            id={'mode'}
                            name={'mode'}
                            className={'w-full rounded border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 focus:border-cyan-400 focus:outline-none'}
                        >
                            <option value="openai">OpenAI / Standard (HTTPS)</option>
                            <option value="ollama">Ollama (Local / HTTPS)</option>
                        </FormikField>
                        <p className={'mt-1 text-xs text-neutral-500'}>
                            {values.mode === 'ollama'
                                ? 'HTTP allowed for local Ollama. No API key required.'
                                : 'Requires HTTPS endpoint and an API key.'}
                        </p>
                    </div>

                    <div>
                        <label className={'mb-1.5 block text-xs font-medium text-neutral-300'}>API Endpoint</label>
                        <Field
                            id={'endpoint'}
                            name={'endpoint'}
                            type={'input'}
                            placeholder={values.mode === 'ollama' ? 'http://localhost:11434/v1' : 'https://api.openai.com/v1'}
                        />
                        <p className={'mt-1 text-xs text-neutral-500'}>
                            {values.mode === 'ollama'
                                ? 'Default: http://localhost:11434/v1'
                                : 'Must end in /v1'}
                        </p>
                    </div>

                    {values.mode === 'openai' ? (
                        <div>
                            <label className={'mb-1.5 block text-xs font-medium text-neutral-300'}>
                                <FontAwesomeIcon icon={faKey} className={'mr-1.5 opacity-60'} />
                                API Key
                            </label>
                            <Field
                                id={'key'}
                                name={'key'}
                                type={'input'}
                                placeholder={ai.key ? '•••••••• (leave blank to keep current)' : 'sk-...'}
                            />
                            {ai.key && (
                                <button
                                    type="button"
                                    onClick={onDeleteKey}
                                    disabled={deletingKey}
                                    className={'mt-1.5 inline-flex items-center gap-1.5 rounded bg-red-600/80 px-2.5 py-1 text-xs font-medium text-white transition-colors hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50'}
                                >
                                    <FontAwesomeIcon icon={faTrash} className={'h-3 w-3'} />
                                    {deletingKey ? 'Deleting...' : 'Remove saved key'}
                                </button>
                            )}
                        </div>
                    ) : (
                        <div className={'flex items-start rounded border border-neutral-700/50 bg-neutral-800/30 px-3 py-2.5'}>
                            <p className={'text-xs text-neutral-500'}>
                                Ollama does not require an API key. Leave the key field blank.
                            </p>
                        </div>
                    )}
                </div>
            </AdminBox>

            <AdminBox title={'Model & Performance'} icon={faSliders} className={'mb-4'}>
                <div className={'grid gap-6 md:grid-cols-2'}>
                    <div>
                        <label className={'mb-1.5 block text-xs font-medium text-neutral-300'}>Model Name</label>
                        <Field id={'model'} name={'model'} type={'input'} />
                        <div className={'mt-2 flex flex-wrap gap-1.5'}>
                            {presets.map(p => (
                                <button
                                    key={p.value}
                                    type="button"
                                    onClick={() => setFieldValue('model', p.value)}
                                    title={p.note}
                                    className={`rounded-full border px-2.5 py-0.5 text-xs transition-colors ${
                                        values.model === p.value
                                            ? 'border-violet-500 bg-violet-500/20 text-violet-300'
                                            : 'border-neutral-600 bg-neutral-800 text-neutral-400 hover:border-neutral-400 hover:text-neutral-200'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                        <p className={'mt-2 text-xs text-neutral-500'}>
                            Click a preset or type a custom model name. Hover presets for details.
                        </p>
                    </div>

                    <div className={'space-y-4'}>
                        <div>
                            <div className={'mb-1.5 flex items-center justify-between'}>
                                <label className={'text-xs font-medium text-neutral-300'}>Max Response Tokens</label>
                                <span className={'font-mono text-xs text-violet-300'}>{values.max_tokens ?? 500}</span>
                            </div>
                            <input
                                type="range"
                                min={50}
                                max={4000}
                                step={50}
                                value={values.max_tokens ?? 500}
                                onChange={e => setFieldValue('max_tokens', Number(e.target.value))}
                                className={'h-1.5 w-full cursor-pointer appearance-none rounded-full bg-neutral-700 accent-violet-500'}
                            />
                            <div className={'mt-1 flex justify-between text-xs text-neutral-600'}>
                                <span>50 - brief</span>
                                <span>4000 - detailed</span>
                            </div>
                        </div>

                        <div>
                            <div className={'mb-1.5 flex items-center justify-between'}>
                                <label className={'text-xs font-medium text-neutral-300'}>Temperature</label>
                                <span className={'font-mono text-xs text-violet-300'}>{Number(values.temperature ?? 0.3).toFixed(1)}</span>
                            </div>
                            <input
                                type="range"
                                min={0}
                                max={1}
                                step={0.05}
                                value={values.temperature ?? 0.3}
                                onChange={e => setFieldValue('temperature', parseFloat(e.target.value))}
                                className={'h-1.5 w-full cursor-pointer appearance-none rounded-full bg-neutral-700 accent-violet-500'}
                            />
                            <div className={'mt-1.5 flex items-center gap-2'}>
                                <span className={'rounded bg-violet-500/20 px-2 py-0.5 text-xs font-medium text-violet-300'}>
                                    {tempInfo.label}
                                </span>
                                <span className={'text-xs text-neutral-500'}>{tempInfo.hint}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminBox>

            <AdminBox title={'Access & Behavior'} icon={faShieldAlt} className={'mb-4'}>
                <div className={'grid gap-6 md:grid-cols-2'}>
                    <div>
                        <label className={'mb-1.5 block text-xs font-medium text-neutral-300'}>User Access</label>
                        <label className={'flex cursor-pointer items-center gap-3 rounded border border-neutral-700 bg-neutral-800/40 px-3 py-2.5 transition-colors hover:bg-neutral-800/70'}>
                            <FormikField
                                type="checkbox"
                                name="user_access"
                                className={'h-4 w-4 cursor-pointer rounded accent-violet-500'}
                            />
                            <div>
                                <p className={'text-sm font-medium text-neutral-200'}>Allow standard users</p>
                                <p className={'text-xs text-neutral-500'}>
                                    Standard users can chat with M12Labs-AI from their server page. Admins always have access.
                                </p>
                            </div>
                        </label>
                        <div className={'mt-3 rounded border border-neutral-700/50 bg-neutral-800/30 px-3 py-2'}>
                            <p className={'text-xs text-neutral-500'}>
                                <span className={'font-medium text-neutral-400'}>Rate limits:</span> Users - 15 requests / 10 min &nbsp;·&nbsp; Admins - 60 requests / 10 min
                            </p>
                        </div>
                    </div>

                    <div>
                        <div className={'mb-1.5 flex items-center justify-between'}>
                            <label className={'text-xs font-medium text-neutral-300'}>
                                <FontAwesomeIcon icon={faRobot} className={'mr-1.5 opacity-60'} />
                                System Prompt
                            </label>
                            <span className={`text-xs ${systemPromptLen > MAX_PROMPT ? 'text-red-400' : 'text-neutral-500'}`}>
                                {systemPromptLen} / {MAX_PROMPT}
                            </span>
                        </div>
                        <TextareaField
                            id={'system_prompt'}
                            name={'system_prompt'}
                            rows={5}
                            placeholder="You are an expert game server technician specializing in crash analysis and debugging..."
                        />
                        <p className={'mt-1 text-xs text-neutral-500'}>
                            Sent with every request to define the AI's role and tone. Keep it concise - it counts toward your token budget.
                        </p>
                    </div>
                </div>
            </AdminBox>

            <div className={'flex items-center justify-between'}>
                <p className={'text-xs text-neutral-600'}>Changes take effect on the next request after saving.</p>
                <div className={'flex items-center gap-3'}>
                    {testResult && (
                        <span className={`text-xs font-medium ${testResult.status === 'ok' ? 'text-green-400' : 'text-red-400'}`}>
                            {testResult.status === 'ok'
                                ? `✓ Connected (${testResult.latency_ms}ms)`
                                : `✗ ${testResult.message}`}
                        </span>
                    )}
                    <Button type={'button'} variant={'secondary'} onClick={onTestConnection} disabled={testing}>
                        <FontAwesomeIcon icon={faWifi} className={'mr-1.5 h-3 w-3'} />
                        {testing ? 'Testing...' : 'Test Connection'}
                    </Button>
                    <Button type={'submit'}>Save Changes</Button>
                </div>
            </div>
        </Form>
    );
}

// --- Default export -------------------------------------------------------

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const ai = useStoreState(s => s.everest.data!.ai);
    const [deletingKey, setDeletingKey] = useState(false);
    const [testResult, setTestResult] = useState<ConnectionTestResult | null>(null);
    const [testing, setTesting] = useState(false);

    const handleTestConnection = () => {
        setTesting(true);
        setTestResult(null);
        testConnection()
            .then(setTestResult)
            .catch(() => setTestResult({ status: 'error', message: 'Request failed. Check endpoint and try again.' }))
            .finally(() => setTesting(false));
    };

    const handleDeleteKey = () => {
        if (!confirm('Remove the saved API key? AI will stop working until you add a new one.')) return;
        setDeletingKey(true);
        clearFlashes();
        updateSettings({ key: '' })
            .then(() => {
                addFlash({ type: 'success', key: 'admin:ai:settings', message: 'API key removed.' });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                setDeletingKey(false);
                clearAndAddHttpError({ key: 'admin:ai:settings', error });
            });
    };

    const submit = (values: AISettings) => {
        clearFlashes();
        updateSettings(values)
            .then(() => {
                addFlash({ type: 'success', key: 'admin:ai:settings', message: 'Settings saved successfully.' });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:ai:settings', error }));
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                user_access: ai.user_access,
                endpoint: ai.endpoint || (ai.mode === 'ollama' ? 'http://localhost:11434/v1' : 'https://api.openai.com/v1'),
                model: ai.model || (ai.mode === 'ollama' ? 'phi3:mini' : 'gpt-4.1-mini'),
                mode: ai.mode || 'openai',
                max_tokens: ai.max_tokens || 500,
                temperature: ai.temperature ?? 0.3,
                system_prompt: ai.system_prompt || 'You are an expert game server technician specializing in crash analysis and debugging. When given server logs, identify the root cause concisely and list specific actionable steps to resolve it. Format responses as: Cause: [what went wrong]. Fix: [numbered steps]. For general questions, give direct technical answers. Be concise.',
            }}
        >
            <SettingsForm
                ai={ai}
                deletingKey={deletingKey}
                testing={testing}
                testResult={testResult}
                onDeleteKey={handleDeleteKey}
                onTestConnection={handleTestConnection}
            />
        </Formik>
    );
};
