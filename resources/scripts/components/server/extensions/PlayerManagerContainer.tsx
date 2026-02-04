import { useEffect, useState, useCallback, useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ServerContext } from '@/state/server';
import {
    getPlayerManagerStatus,
    PlayerManagerStatus,
    setWhitelistEnabled,
    addToWhitelist,
    removeFromWhitelist,
    opPlayer,
    deopPlayer,
    banPlayer,
    unbanPlayer,
    banIp,
    unbanIp,
    kickPlayer,
    whisperPlayer,
    killPlayer,
} from '@/api/server/extensions/playerManager';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faUsers,
    faCircle,
    faUserShield,
    faListCheck,
    faBan,
    faSync,
    faCrown,
    faCommentDots,
    faSkull,
    faDoorOpen,
    faArrowLeft,
    faToggleOn,
    faToggleOff,
    faPlus,
    faNetworkWired,
    faEllipsisV,
    faGavel,
    faUserMinus,
    faUserPlus,
    faGamepad,
    faTimes,
} from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import PageContentBlock from '@/elements/PageContentBlock';
import { Button } from '@/elements/button';
import Modal from '@/elements/Modal';
import Field from '@/elements/Field';
import { Form, Formik } from 'formik';
import { object, string } from 'yup';
import classNames from 'classnames';

// Image cache for player avatars (5 minute cache)
// We add a cache key based on 5-min intervals so browser caches the image
const getCachedAvatarUrl = (identifier: string, size: number = 32): string => {
    // Round timestamp to 5-minute intervals for cache busting
    const cacheInterval = 5 * 60 * 1000; // 5 minutes
    const cacheKey = Math.floor(Date.now() / cacheInterval);
    return `https://mc-heads.net/avatar/${identifier}/${size}?v=${cacheKey}`;
};

interface PlayerCardProps {
    name: string;
    uuid?: string;
    isOnline: boolean;
    isOperator?: boolean;
    isBanned?: boolean;
    isWhitelisted?: boolean;
    banReason?: string;
    onClick: () => void;
    primary: string;
}

const PlayerCard = ({ 
    name, 
    uuid, 
    isOnline, 
    isOperator, 
    isBanned,
    isWhitelisted,
    banReason,
    onClick,
    primary,
}: PlayerCardProps) => {
    const avatarUrl = getCachedAvatarUrl(uuid || name, 48);
    
    return (
        <button
            onClick={onClick}
            className={classNames(
                'group relative flex w-full items-center gap-3 rounded-lg p-3 transition-all duration-200',
                'bg-neutral-800 hover:bg-neutral-750 hover:shadow-lg',
                isBanned && 'opacity-60'
            )}
        >
            <div className="relative">
                <img
                    src={avatarUrl}
                    alt={name}
                    className={classNames(
                        'h-12 w-12 rounded-lg',
                        !isOnline && !isBanned && 'grayscale'
                    )}
                />
                <div
                    className={classNames(
                        'absolute -bottom-1 -right-1 h-4 w-4 rounded-full border-2 border-neutral-800',
                        isOnline ? 'bg-green-500' : isBanned ? 'bg-red-500' : 'bg-neutral-500'
                    )}
                />
            </div>
            <div className="flex-1 text-left">
                <div className="flex items-center gap-2">
                    <span className="font-medium text-white">{name}</span>
                    {isOperator && (
                        <span 
                            className="rounded px-1.5 py-0.5 text-xs font-medium"
                            style={{ backgroundColor: `${primary}30`, color: primary }}
                            title="Operator"
                        >
                            <FontAwesomeIcon icon={faCrown} className="mr-1" />
                            OP
                        </span>
                    )}
                    {isWhitelisted && (
                        <span className="rounded bg-blue-500/20 px-1.5 py-0.5 text-xs font-medium text-blue-400">
                            <FontAwesomeIcon icon={faListCheck} className="mr-1" />
                            WL
                        </span>
                    )}
                    {isBanned && (
                        <span className="rounded bg-red-500/20 px-1.5 py-0.5 text-xs font-medium text-red-400">
                            <FontAwesomeIcon icon={faBan} className="mr-1" />
                            Banned
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-2 text-xs text-neutral-400">
                    <span className={isOnline ? 'text-green-400' : isBanned ? 'text-red-400' : 'text-neutral-500'}>
                        {isOnline ? 'Online' : isBanned ? 'Banned' : 'Offline'}
                    </span>
                    {banReason && <span className="truncate">• {banReason}</span>}
                </div>
            </div>
            <FontAwesomeIcon 
                icon={faEllipsisV} 
                className="text-neutral-500 transition-colors group-hover:text-white" 
            />
        </button>
    );
};

interface PlayerManageModalProps {
    visible: boolean;
    onDismissed: () => void;
    player: {
        name: string;
        uuid?: string;
        isOnline: boolean;
        isOperator: boolean;
        isBanned: boolean;
        isWhitelisted: boolean;
        banReason?: string;
    };
    serverUuid: string;
    onAction: () => void;
}

const PlayerManageModal = ({ visible, onDismissed, player, serverUuid, onAction }: PlayerManageModalProps) => {
    const [loading, setLoading] = useState(false);
    const [activeTab, setActiveTab] = useState<'actions' | 'op' | 'ban'>('actions');
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();
    const primary = useStoreState(state => state.theme.data!.colors.primary);
    const avatarUrl = getCachedAvatarUrl(player.uuid || player.name, 64);

    const handleAction = async (action: () => Promise<void>, actionName: string) => {
        setLoading(true);
        clearFlashes('server:player-manager:modal');
        
        try {
            await action();
            addFlash({ key: 'server:player-manager', type: 'success', message: `${actionName} successful` });
            onAction();
            onDismissed();
        } catch (error) {
            clearAndAddHttpError({ key: 'server:player-manager:modal', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal visible={visible} onDismissed={onDismissed} closeOnBackground showSpinnerOverlay={loading}>
            <FlashMessageRender byKey={'server:player-manager:modal'} className={'mb-4'} />
            
            {/* Player Header */}
            <div className="mb-6 flex items-center gap-4">
                <img src={avatarUrl} alt={player.name} className="h-16 w-16 rounded-lg" />
                <div>
                    <h2 className="text-xl font-semibold text-white">{player.name}</h2>
                    <div className="flex items-center gap-2 text-sm">
                        <span className={player.isOnline ? 'text-green-400' : 'text-neutral-400'}>
                            <FontAwesomeIcon icon={faCircle} className="mr-1 text-xs" />
                            {player.isOnline ? 'Online' : 'Offline'}
                        </span>
                        {player.isOperator && (
                            <span style={{ color: primary }}>
                                <FontAwesomeIcon icon={faCrown} className="mr-1" />
                                Operator
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* Tabs */}
            <div className="mb-4 flex gap-2 border-b border-neutral-700 pb-2">
                {['actions', 'op', 'ban'].map((tab) => (
                    <button
                        key={tab}
                        onClick={() => setActiveTab(tab as typeof activeTab)}
                        className={classNames(
                            'rounded-t px-4 py-2 text-sm font-medium transition-colors',
                            activeTab === tab 
                                ? 'bg-neutral-700 text-white' 
                                : 'text-neutral-400 hover:text-white'
                        )}
                    >
                        {tab === 'actions' && 'Quick Actions'}
                        {tab === 'op' && 'Operator'}
                        {tab === 'ban' && 'Ban / Whitelist'}
                    </button>
                ))}
            </div>

            {/* Actions Tab */}
            {activeTab === 'actions' && (
                <div className="space-y-4">
                    {player.isOnline ? (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Button
                                    onClick={() => handleAction(() => kickPlayer(serverUuid, player.name), 'Kick')}
                                    className="w-full justify-center"
                                    disabled={loading}
                                >
                                    <FontAwesomeIcon icon={faDoorOpen} className="mr-2" />
                                    Kick Player
                                </Button>
                                <Button
                                    onClick={() => handleAction(() => killPlayer(serverUuid, player.name), 'Kill')}
                                    className="w-full justify-center"
                                    disabled={loading}
                                >
                                    <FontAwesomeIcon icon={faSkull} className="mr-2" />
                                    Kill Player
                                </Button>
                            </div>

                            {/* Whisper */}
                            <div className="rounded-lg bg-neutral-750 p-4">
                                <h4 className="mb-2 text-sm font-medium text-neutral-300">
                                    <FontAwesomeIcon icon={faCommentDots} className="mr-2" />
                                    Send Private Message
                                </h4>
                                <Formik
                                    initialValues={{ message: '' }}
                                    validationSchema={object().shape({ message: string().required('Message is required').min(1) })}
                                    onSubmit={async (values, { resetForm }) => {
                                        await handleAction(
                                            () => whisperPlayer(serverUuid, player.name, values.message),
                                            'Message sent'
                                        );
                                        resetForm();
                                    }}
                                >
                                    <Form className="flex gap-2">
                                        <div className="flex-1">
                                            <Field name="message" placeholder="Enter message..." />
                                        </div>
                                        <Button type="submit" disabled={loading}>Send</Button>
                                    </Form>
                                </Formik>
                            </div>
                        </>
                    ) : (
                        <div className="rounded-lg bg-neutral-750 p-6 text-center">
                            <FontAwesomeIcon icon={faGamepad} className="mb-2 text-3xl text-neutral-500" />
                            <p className="text-neutral-400">Player is currently offline</p>
                            <p className="mt-1 text-sm text-neutral-500">
                                Quick actions like kick and kill are only available for online players
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* Operator Tab */}
            {activeTab === 'op' && (
                <div className="space-y-4">
                    {player.isOperator ? (
                        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-4">
                            <p className="mb-3 text-sm text-yellow-400">
                                <FontAwesomeIcon icon={faCrown} className="mr-2" />
                                {player.name} is currently an operator
                            </p>
                            <Button
                                onClick={() => handleAction(() => deopPlayer(serverUuid, player.name), 'Removed operator')}
                                className="w-full justify-center"
                                disabled={loading}
                            >
                                <FontAwesomeIcon icon={faUserMinus} className="mr-2" />
                                Remove Operator Status
                            </Button>
                        </div>
                    ) : (
                        <div className="rounded-lg bg-neutral-750 p-4">
                            <p className="mb-3 text-sm text-neutral-400">
                                {player.name} is not an operator. Operators have access to server commands.
                            </p>
                            <Button
                                onClick={() => handleAction(() => opPlayer(serverUuid, player.name), 'Made operator')}
                                className="w-full justify-center"
                                disabled={loading}
                            >
                                <FontAwesomeIcon icon={faCrown} className="mr-2" />
                                Make Operator
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {/* Ban/Whitelist Tab */}
            {activeTab === 'ban' && (
                <div className="space-y-4">
                    {/* Whitelist */}
                    <div className="rounded-lg bg-neutral-750 p-4">
                        <h4 className="mb-3 text-sm font-medium text-neutral-300">
                            <FontAwesomeIcon icon={faListCheck} className="mr-2" />
                            Whitelist
                        </h4>
                        {player.isWhitelisted ? (
                            <Button
                                onClick={() => handleAction(() => removeFromWhitelist(serverUuid, player.name), 'Removed from whitelist')}
                                className="w-full justify-center"
                                disabled={loading}
                            >
                                <FontAwesomeIcon icon={faUserMinus} className="mr-2" />
                                Remove from Whitelist
                            </Button>
                        ) : (
                            <Button
                                onClick={() => handleAction(() => addToWhitelist(serverUuid, player.name), 'Added to whitelist')}
                                className="w-full justify-center"
                                disabled={loading}
                            >
                                <FontAwesomeIcon icon={faUserPlus} className="mr-2" />
                                Add to Whitelist
                            </Button>
                        )}
                    </div>

                    {/* Ban */}
                    <div className="rounded-lg bg-neutral-750 p-4">
                        <h4 className="mb-3 text-sm font-medium text-neutral-300">
                            <FontAwesomeIcon icon={faBan} className="mr-2" />
                            Ban Status
                        </h4>
                        {player.isBanned ? (
                            <div>
                                {player.banReason && (
                                    <p className="mb-3 text-sm text-neutral-400">
                                        Reason: <span className="text-red-400">{player.banReason}</span>
                                    </p>
                                )}
                                <Button
                                    onClick={() => handleAction(() => unbanPlayer(serverUuid, player.name), 'Unbanned')}
                                    className="w-full justify-center"
                                    disabled={loading}
                                >
                                    <FontAwesomeIcon icon={faGavel} className="mr-2" />
                                    Unban Player
                                </Button>
                            </div>
                        ) : (
                            <Formik
                                initialValues={{ reason: '' }}
                                validationSchema={object().shape({ reason: string().min(3, 'Reason must be at least 3 characters').required('Reason is required') })}
                                onSubmit={async (values) => {
                                    await handleAction(
                                        () => banPlayer(serverUuid, player.name, values.reason),
                                        'Banned'
                                    );
                                }}
                            >
                                <Form className="space-y-3">
                                    <Field name="reason" label="Ban Reason" placeholder="Enter ban reason..." />
                                    <Button type="submit" className="w-full justify-center bg-red-600 hover:bg-red-700" disabled={loading}>
                                        <FontAwesomeIcon icon={faBan} className="mr-2" />
                                        Ban Player
                                    </Button>
                                </Form>
                            </Formik>
                        )}
                    </div>
                </div>
            )}

            <div className="mt-6 flex justify-end">
                <Button.Text onClick={onDismissed}>Close</Button.Text>
            </div>
        </Modal>
    );
};

interface BanIpModalProps {
    visible: boolean;
    onDismissed: () => void;
    serverUuid: string;
    onAction: () => void;
    ip?: string;
    isUnban?: boolean;
}

const BanIpModal = ({ visible, onDismissed, serverUuid, onAction, ip, isUnban }: BanIpModalProps) => {
    const [loading, setLoading] = useState(false);
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    const handleUnban = async () => {
        if (!ip) return;
        setLoading(true);
        clearFlashes('server:player-manager:ip');
        try {
            await unbanIp(serverUuid, ip);
            addFlash({ key: 'server:player-manager', type: 'success', message: 'IP unbanned successfully' });
            onAction();
            onDismissed();
        } catch (error) {
            clearAndAddHttpError({ key: 'server:player-manager:ip', error });
        } finally {
            setLoading(false);
        }
    };

    if (isUnban && ip) {
        return (
            <Modal visible={visible} onDismissed={onDismissed} closeOnBackground showSpinnerOverlay={loading}>
                <FlashMessageRender byKey={'server:player-manager:ip'} className={'mb-4'} />
                <h2 className="mb-4 text-xl font-semibold text-white">Unban IP Address</h2>
                <p className="mb-4 text-neutral-400">
                    Are you sure you want to unban <span className="font-mono text-white">{ip}</span>?
                </p>
                <div className="flex justify-end gap-3">
                    <Button.Text onClick={onDismissed}>Cancel</Button.Text>
                    <Button onClick={handleUnban} disabled={loading}>Unban IP</Button>
                </div>
            </Modal>
        );
    }

    return (
        <Modal visible={visible} onDismissed={onDismissed} closeOnBackground showSpinnerOverlay={loading}>
            <FlashMessageRender byKey={'server:player-manager:ip'} className={'mb-4'} />
            <h2 className="mb-4 text-xl font-semibold text-white">Ban IP Address</h2>
            <Formik
                initialValues={{ ip: '', reason: '' }}
                validationSchema={object().shape({
                    ip: string().required('IP address is required').matches(
                        /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/,
                        'Invalid IP address format'
                    ),
                    reason: string().min(3, 'Reason must be at least 3 characters').required('Reason is required'),
                })}
                onSubmit={async (values) => {
                    setLoading(true);
                    clearFlashes('server:player-manager:ip');
                    try {
                        await banIp(serverUuid, values.ip, values.reason);
                        addFlash({ key: 'server:player-manager', type: 'success', message: 'IP banned successfully' });
                        onAction();
                        onDismissed();
                    } catch (error) {
                        clearAndAddHttpError({ key: 'server:player-manager:ip', error });
                    } finally {
                        setLoading(false);
                    }
                }}
            >
                <Form className="space-y-4">
                    <Field name="ip" label="IP Address" placeholder="192.168.1.1" />
                    <Field name="reason" label="Ban Reason" placeholder="Enter ban reason..." />
                    <div className="flex justify-end gap-3">
                        <Button.Text onClick={onDismissed}>Cancel</Button.Text>
                        <Button type="submit" disabled={loading}>Ban IP</Button>
                    </div>
                </Form>
            </Formik>
        </Modal>
    );
};

interface AddPlayerModalProps {
    visible: boolean;
    onDismissed: () => void;
    type: 'whitelist' | 'op';
    serverUuid: string;
    onAction: () => void;
}

const AddPlayerModal = ({ visible, onDismissed, type, serverUuid, onAction }: AddPlayerModalProps) => {
    const [loading, setLoading] = useState(false);
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    return (
        <Modal visible={visible} onDismissed={onDismissed} closeOnBackground showSpinnerOverlay={loading}>
            <FlashMessageRender byKey={'server:player-manager:add'} className={'mb-4'} />
            <h2 className="mb-4 text-xl font-semibold text-white">
                {type === 'whitelist' ? 'Add to Whitelist' : 'Add Operator'}
            </h2>
            <Formik
                initialValues={{ name: '' }}
                validationSchema={object().shape({ 
                    name: string().required('Player name is required').min(3).max(16),
                })}
                onSubmit={async (values) => {
                    setLoading(true);
                    clearFlashes('server:player-manager:add');
                    try {
                        if (type === 'whitelist') {
                            await addToWhitelist(serverUuid, values.name);
                        } else {
                            await opPlayer(serverUuid, values.name);
                        }
                        addFlash({ key: 'server:player-manager', type: 'success', message: `Player ${type === 'whitelist' ? 'whitelisted' : 'opped'} successfully` });
                        onAction();
                        onDismissed();
                    } catch (error) {
                        clearAndAddHttpError({ key: 'server:player-manager:add', error });
                    } finally {
                        setLoading(false);
                    }
                }}
            >
                <Form className="space-y-4">
                    <Field name="name" label="Player Name" placeholder="Enter player name..." />
                    <div className="flex justify-end gap-3">
                        <Button.Text onClick={onDismissed}>Cancel</Button.Text>
                        <Button type="submit" disabled={loading}>
                            {type === 'whitelist' ? 'Add to Whitelist' : 'Make Operator'}
                        </Button>
                    </div>
                </Form>
            </Formik>
        </Modal>
    );
};

export default () => {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const [status, setStatus] = useState<PlayerManagerStatus | null>(null);
    const [loading, setLoading] = useState(true);
    const [selectedPlayer, setSelectedPlayer] = useState<{
        name: string;
        uuid?: string;
        isOnline: boolean;
        isOperator: boolean;
        isBanned: boolean;
        isWhitelisted: boolean;
        banReason?: string;
    } | null>(null);
    const [addModalType, setAddModalType] = useState<'whitelist' | 'op' | null>(null);
    const [banIpModal, setBanIpModal] = useState<{ visible: boolean; ip?: string; isUnban?: boolean }>({ visible: false });
    const [activeSection, setActiveSection] = useState<'online' | 'operators' | 'banned'>('online');
    const { clearAndAddHttpError } = useFlash();
    const primary = useStoreState(state => state.theme.data!.colors.primary);
    const uuid = ServerContext.useStoreState(state => state.server.data?.uuid);

    const fetchStatus = useCallback(() => {
        if (!uuid) {
            setLoading(false);
            return;
        }

        setLoading(true);
        getPlayerManagerStatus(uuid)
            .then(data => setStatus(data))
            .catch(error => clearAndAddHttpError({ key: 'server:player-manager', error }))
            .finally(() => setLoading(false));
    }, [uuid]);

    useEffect(() => {
        fetchStatus();
        const interval = setInterval(fetchStatus, 30000);
        return () => clearInterval(interval);
    }, [fetchStatus]);

    // Build unified player list with status info
    const allPlayers = useMemo(() => {
        if (!status) return [];

        const playerMap = new Map<string, {
            name: string;
            uuid?: string;
            isOnline: boolean;
            isOperator: boolean;
            isBanned: boolean;
            isWhitelisted: boolean;
            banReason?: string;
        }>();

        // Add online players
        status.server.players.list.forEach(p => {
            playerMap.set(p.name.toLowerCase(), {
                name: p.name,
                uuid: p.uuid,
                isOnline: true,
                isOperator: false,
                isBanned: false,
                isWhitelisted: false,
            });
        });

        // Add/update operators
        status.operators.forEach(op => {
            const key = op.name.toLowerCase();
            const existing = playerMap.get(key);
            if (existing) {
                existing.isOperator = true;
                existing.uuid = existing.uuid || op.uuid;
            } else {
                playerMap.set(key, {
                    name: op.name,
                    uuid: op.uuid,
                    isOnline: false,
                    isOperator: true,
                    isBanned: false,
                    isWhitelisted: false,
                });
            }
        });

        // Add/update banned players
        status.bannedPlayers.forEach(banned => {
            const key = banned.name.toLowerCase();
            const existing = playerMap.get(key);
            if (existing) {
                existing.isBanned = true;
                existing.banReason = banned.reason;
                existing.uuid = existing.uuid || banned.uuid;
            } else {
                playerMap.set(key, {
                    name: banned.name,
                    uuid: banned.uuid,
                    isOnline: false,
                    isOperator: false,
                    isBanned: true,
                    banReason: banned.reason,
                    isWhitelisted: false,
                });
            }
        });

        // Add/update whitelisted players
        status.whitelist.forEach(wl => {
            const key = wl.name.toLowerCase();
            const existing = playerMap.get(key);
            if (existing) {
                existing.isWhitelisted = true;
                existing.uuid = existing.uuid || wl.uuid;
            } else {
                playerMap.set(key, {
                    name: wl.name,
                    uuid: wl.uuid,
                    isOnline: false,
                    isOperator: false,
                    isBanned: false,
                    isWhitelisted: true,
                });
            }
        });

        return Array.from(playerMap.values());
    }, [status]);

    const onlinePlayers = useMemo(() => allPlayers.filter(p => p.isOnline), [allPlayers]);
    const operators = useMemo(() => allPlayers.filter(p => p.isOperator).sort((a, b) => {
        // Sort by online status first
        if (a.isOnline !== b.isOnline) return a.isOnline ? -1 : 1;
        return a.name.localeCompare(b.name);
    }), [allPlayers]);
    const bannedPlayers = useMemo(() => allPlayers.filter(p => p.isBanned), [allPlayers]);

    const handleToggleWhitelist = async () => {
        if (!uuid || !status) return;
        try {
            await setWhitelistEnabled(uuid, !status.whitelistEnabled);
            fetchStatus();
        } catch (error) {
            clearAndAddHttpError({ key: 'server:player-manager', error });
        }
    };

    if (!uuid || (loading && !status)) {
        return (
            <PageContentBlock title={'Player Manager'}>
                <div className={'flex items-center justify-center py-16'}>
                    <Spinner size={'large'} />
                </div>
            </PageContentBlock>
        );
    }

    if (!status || !status.server) {
        return (
            <PageContentBlock title={'Player Manager'}>
                <FlashMessageRender byKey={'server:player-manager'} className={'mb-4'} />
                <div className={'mb-6'}>
                    <button
                        onClick={() => navigate(`/server/${id}/extensions`)}
                        className={'flex items-center gap-2 text-neutral-400 transition-colors hover:text-white'}
                    >
                        <FontAwesomeIcon icon={faArrowLeft} />
                        Back to Extensions
                    </button>
                </div>
                <div className={'rounded-lg bg-neutral-800 p-8 text-center'}>
                    <FontAwesomeIcon icon={faUsers} className={'mb-4 text-4xl text-neutral-600'} />
                    <p className={'text-neutral-400'}>Unable to load player manager data.</p>
                    <Button className={'mt-4'} onClick={fetchStatus}>
                        <FontAwesomeIcon icon={faSync} className={'mr-2'} />
                        Try Again
                    </Button>
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'Player Manager'}>
            <FlashMessageRender byKey={'server:player-manager'} className={'mb-4'} />

            {/* Header */}
            <div className={'mb-6 flex flex-wrap items-center justify-between gap-4'}>
                <button
                    onClick={() => navigate(`/server/${id}/extensions`)}
                    className={'flex items-center gap-2 text-neutral-400 transition-colors hover:text-white'}
                >
                    <FontAwesomeIcon icon={faArrowLeft} />
                    Back to Extensions
                </button>
                <div className="flex items-center gap-3">
                    <button
                        onClick={handleToggleWhitelist}
                        className={'flex items-center gap-2 rounded-lg bg-neutral-800 px-4 py-2 text-sm transition-colors hover:bg-neutral-750'}
                    >
                        <FontAwesomeIcon
                            icon={status.whitelistEnabled ? faToggleOn : faToggleOff}
                            className={status.whitelistEnabled ? 'text-green-500' : 'text-neutral-500'}
                        />
                        Whitelist {status.whitelistEnabled ? 'On' : 'Off'}
                    </button>
                    <Button onClick={fetchStatus} disabled={loading}>
                        <FontAwesomeIcon icon={faSync} className={classNames('mr-2', loading && 'animate-spin')} />
                        Refresh
                    </Button>
                </div>
            </div>

            {/* Server Status Banner */}
            <div className={classNames(
                'mb-6 rounded-lg p-4',
                status.server.online ? 'bg-green-500/10 border border-green-500/30' : 'bg-red-500/10 border border-red-500/30'
            )}>
                <div className="flex items-center gap-3">
                    <FontAwesomeIcon 
                        icon={faCircle} 
                        className={status.server.online ? 'text-green-500' : 'text-red-500'} 
                    />
                    <div>
                        <span className={classNames('font-medium', status.server.online ? 'text-green-400' : 'text-red-400')}>
                            Server {status.server.online ? 'Online' : 'Offline'}
                        </span>
                        {status.server.online && (
                            <span className="ml-3 text-neutral-400">
                                {status.server.players.online}/{status.server.players.max} Players
                                {status.server.version && ` • ${status.server.version}`}
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* Section Tabs */}
            <div className="mb-6 flex gap-2 overflow-x-auto">
                {[
                    { key: 'online', label: 'Online Players', count: onlinePlayers.length, icon: faUsers },
                    { key: 'operators', label: 'Operators', count: operators.length, icon: faUserShield },
                    { key: 'banned', label: 'Banned', count: bannedPlayers.length + status.bannedIps.length, icon: faBan },
                ].map(({ key, label, count, icon }) => (
                    <button
                        key={key}
                        onClick={() => setActiveSection(key as typeof activeSection)}
                        className={classNames(
                            'flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-all',
                            activeSection === key
                                ? 'bg-neutral-700 text-white'
                                : 'bg-neutral-800 text-neutral-400 hover:bg-neutral-750 hover:text-white'
                        )}
                    >
                        <FontAwesomeIcon icon={icon} />
                        {label}
                        <span className={classNames(
                            'rounded px-2 py-0.5 text-xs',
                            activeSection === key ? 'bg-neutral-600' : 'bg-neutral-700'
                        )}>
                            {count}
                        </span>
                    </button>
                ))}
            </div>

            {/* Online Players Section */}
            {activeSection === 'online' && (
                <div>
                    {onlinePlayers.length === 0 ? (
                        <div className="rounded-lg bg-neutral-800 p-8 text-center">
                            <FontAwesomeIcon icon={faUsers} className="mb-3 text-4xl text-neutral-600" />
                            <p className="text-neutral-400">No players currently online</p>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {onlinePlayers.map(player => (
                                <PlayerCard
                                    key={player.name}
                                    {...player}
                                    onClick={() => setSelectedPlayer(player)}
                                    primary={primary}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Operators Section */}
            {activeSection === 'operators' && (
                <div>
                    <div className="mb-4 flex justify-end">
                        <Button.Text onClick={() => setAddModalType('op')}>
                            <FontAwesomeIcon icon={faPlus} className="mr-2" />
                            Add Operator
                        </Button.Text>
                    </div>
                    {operators.length === 0 ? (
                        <div className="rounded-lg bg-neutral-800 p-8 text-center">
                            <FontAwesomeIcon icon={faUserShield} className="mb-3 text-4xl text-neutral-600" />
                            <p className="text-neutral-400">No operators configured</p>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {operators.map(player => (
                                <PlayerCard
                                    key={player.name}
                                    {...player}
                                    onClick={() => setSelectedPlayer(player)}
                                    primary={primary}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Banned Section */}
            {activeSection === 'banned' && (
                <div className="space-y-6">
                    {/* Banned Players */}
                    <div>
                        <h3 className="mb-3 flex items-center gap-2 text-lg font-medium text-white">
                            <FontAwesomeIcon icon={faBan} style={{ color: primary }} />
                            Banned Players
                            <span className="rounded bg-neutral-700 px-2 py-0.5 text-xs text-neutral-400">
                                {bannedPlayers.length}
                            </span>
                        </h3>
                        {bannedPlayers.length === 0 ? (
                            <div className="rounded-lg bg-neutral-800 p-6 text-center">
                                <p className="text-neutral-500">No players banned</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {bannedPlayers.map(player => (
                                    <PlayerCard
                                        key={player.name}
                                        {...player}
                                        onClick={() => setSelectedPlayer(player)}
                                        primary={primary}
                                    />
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Banned IPs */}
                    <div>
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="flex items-center gap-2 text-lg font-medium text-white">
                                <FontAwesomeIcon icon={faNetworkWired} style={{ color: primary }} />
                                Banned IPs
                                <span className="rounded bg-neutral-700 px-2 py-0.5 text-xs text-neutral-400">
                                    {status.bannedIps.length}
                                </span>
                            </h3>
                            <Button.Text onClick={() => setBanIpModal({ visible: true })}>
                                <FontAwesomeIcon icon={faPlus} className="mr-2" />
                                Ban IP
                            </Button.Text>
                        </div>
                        {status.bannedIps.length === 0 ? (
                            <div className="rounded-lg bg-neutral-800 p-6 text-center">
                                <p className="text-neutral-500">No IPs banned</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {status.bannedIps.map((ip, index) => (
                                    <div
                                        key={`${ip.ip}-${index}`}
                                        className="flex items-center justify-between rounded-lg bg-neutral-800 p-4"
                                    >
                                        <div>
                                            <span className="font-mono text-white">{ip.ip}</span>
                                            {ip.reason && (
                                                <p className="mt-1 text-xs text-neutral-400">{ip.reason}</p>
                                            )}
                                        </div>
                                        <button
                                            onClick={() => setBanIpModal({ visible: true, ip: ip.ip, isUnban: true })}
                                            className="p-2 text-neutral-400 transition-colors hover:text-red-400"
                                            title="Unban IP"
                                        >
                                            <FontAwesomeIcon icon={faTimes} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Player Manage Modal */}
            {uuid && selectedPlayer && (
                <PlayerManageModal
                    key={selectedPlayer.name}
                    visible={true}
                    onDismissed={() => setSelectedPlayer(null)}
                    player={selectedPlayer}
                    serverUuid={uuid}
                    onAction={fetchStatus}
                />
            )}

            {/* Add Player Modal */}
            {uuid && (
                <AddPlayerModal
                    visible={addModalType !== null}
                    onDismissed={() => setAddModalType(null)}
                    type={addModalType || 'whitelist'}
                    serverUuid={uuid}
                    onAction={fetchStatus}
                />
            )}

            {/* Ban IP Modal */}
            {uuid && (
                <BanIpModal
                    visible={banIpModal.visible}
                    onDismissed={() => setBanIpModal({ visible: false })}
                    serverUuid={uuid}
                    onAction={fetchStatus}
                    ip={banIpModal.ip}
                    isUnban={banIpModal.isUnban}
                />
            )}
        </PageContentBlock>
    );
};
