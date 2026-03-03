import { useFormikContext } from 'formik';
import { useEffect, useState } from 'react';
import classNames from 'classnames';

import type { Node } from '@/api/routes/admin/node';
import { searchNodes } from '@/api/routes/admin/node';
import Label from '@/elements/Label';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';

export const fetchAllNodes = () => searchNodes({ filters: {} });

export default ({ node, setNode }: { node: Node | null; setNode: (_: Node | null) => void }) => {
    const { setFieldValue } = useFormikContext();

    const [nodes, setNodes] = useState<Node[]>([]);
    const [loading, setLoading] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setLoading(true);
        fetchAllNodes()
            .then(setNodes)
            .catch(() => setError('Unable to load nodes. Please try again.'))
            .finally(() => setLoading(false));
    }, []);

    const onSelect = (selected: Node) => {
        if (node?.id === selected.id) {
            setNode(null);
            setFieldValue('nodeId', null);
            return;
        }

        setNode(selected);
        setFieldValue('nodeId', selected.id);
    };

    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <Label>Node</Label>
                {node && (
                    <span className="text-xs text-primary-300 bg-primary-500/10 px-2 py-1 rounded">Selected</span>
                )}
            </div>

            {loading ? (
                <div className="flex items-center justify-center h-24">
                    <Spinner size={'small'} />
                </div>
            ) : error ? (
                <Alert type={'danger'}>{error}</Alert>
            ) : nodes.length === 0 ? (
                <Alert type={'warning'}>No nodes available. Create a node first.</Alert>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {nodes.map(n => (
                        <button
                            key={n.id}
                            type="button"
                            className={classNames(
                                'w-full rounded border p-4 text-left transition-colors duration-150',
                                node?.id === n.id
                                    ? 'border-primary-500 bg-primary-500/10'
                                    : 'border-neutral-700 bg-neutral-800 hover:border-primary-400',
                            )}
                            onClick={() => onSelect(n)}
                            aria-label={`Select node ${n.name} (${n.fqdn})`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-semibold text-neutral-100">{n.name}</p>
                                    <p className="text-xs text-neutral-400 break-all">{n.fqdn}</p>
                                </div>
                                {node?.id === n.id && (
                                    <span className="text-xs bg-primary-500/20 text-primary-200 px-2 py-1 rounded">
                                        Selected
                                    </span>
                                )}
                            </div>
                            <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-neutral-400">
                                <div>
                                    <span className="text-neutral-300 font-semibold">HTTP</span> {n.ports.http.public}
                                </div>
                                <div>
                                    <span className="text-neutral-300 font-semibold">SFTP</span> {n.ports.sftp.public}
                                </div>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};
