import { useField } from 'formik';
import { memo } from 'react';
import tw, { styled } from 'twin.macro';
import Checkbox from '@/elements/Checkbox';

const authScopes = {
    control: ['console', 'start', 'stop', 'restart'],
    user: ['create', 'read', 'update', 'delete'],
    file: ['create', 'read', 'update', 'delete', 'read-content', 'archive', 'sftp'],
    backup: ['create', 'read', 'delete', 'download', 'restore'],
    allocation: ['read', 'create', 'update', 'delete'],
    startup: ['read', 'update', 'docker-image'],
    database: ['create', 'read', 'update', 'delete', 'view_password'],
    schedule: ['create', 'read', 'update', 'delete'],
    settings: ['rename', 'reinstall'],
    activity: ['read'],
    billing: ['read', 'renew'],
};

const opsList = [
    'read', 'create', 'update', 'delete', 'console', 'start', 'stop', 'restart',
    'read-content', 'archive', 'sftp', 'download', 'restore', 'docker-image', 
    'view_password', 'rename', 'reinstall', 'renew',
];

const warningOps = new Set(['delete', 'stop', 'download', 'restore', 'reinstall', 'view_password']);

const Wrapper = styled.div`${tw`overflow-auto max-h-[600px] border border-neutral-700 rounded`}`;
const Grid = styled.table`${tw`w-full border-collapse text-sm`}`;
const ColHeader = styled.th<{ stick?: boolean }>`
    ${tw`bg-neutral-800 border border-neutral-700 px-3 py-2 text-left font-semibold text-neutral-200`}
    ${p => p.stick && tw`sticky top-0 z-10`}
    &:first-of-type { ${tw`sticky left-0 z-20`} }
`;
const Cell = styled.td<{ stick?: boolean; warn?: boolean }>`
    ${tw`border border-neutral-700 px-3 py-2 text-center`}
    ${p => p.stick && tw`sticky left-0 bg-neutral-900 font-medium`}
    ${p => p.warn && tw`bg-red-900 bg-opacity-10`}
`;
const Btn = styled.button`${tw`text-xs px-2 py-1 bg-neutral-700 hover:bg-neutral-600 rounded transition-colors`}`;

interface Props {
    editablePermissions: string[];
    isEditable: boolean;
}

const AccessControlGrid = ({ editablePermissions, isEditable }: Props) => {
    const [fieldValue, , helpers] = useField<string[]>('permissions');
    
    const combineKey = (scope: string, op: string) => [scope, op].join('.');
    
    const scopeHasOp = (scope: string, op: string) => authScopes[scope]?.includes(op);
    
    const checkActive = (scope: string, op: string) => fieldValue.value.includes(combineKey(scope, op));
    
    const checkModifiable = (scope: string, op: string) => editablePermissions.includes(combineKey(scope, op));
    
    const toggle = (scope: string, op: string) => {
        const key = combineKey(scope, op);
        const current = new Set(fieldValue.value);
        current.has(key) ? current.delete(key) : current.add(key);
        helpers.setValue(Array.from(current));
    };
    
    const bulkToggleScope = (scope: string) => {
        const keys = authScopes[scope]
            .map(op => combineKey(scope, op))
            .filter(k => editablePermissions.includes(k));
        
        const everythingOn = keys.every(k => fieldValue.value.includes(k));
        const current = new Set(fieldValue.value);
        
        keys.forEach(k => everythingOn ? current.delete(k) : current.add(k));
        helpers.setValue(Array.from(current));
    };
    
    const bulkToggleOp = (op: string) => {
        const keys = Object.keys(authScopes)
            .filter(scope => scopeHasOp(scope, op))
            .map(scope => combineKey(scope, op))
            .filter(k => editablePermissions.includes(k));
        
        const everythingOn = keys.every(k => fieldValue.value.includes(k));
        const current = new Set(fieldValue.value);
        
        keys.forEach(k => everythingOn ? current.delete(k) : current.add(k));
        helpers.setValue(Array.from(current));
    };
    
    const computeStats = (scope: string) => {
        const ops = authScopes[scope];
        const on = ops.filter(op => checkActive(scope, op)).length;
        return { on, all: ops.length };
    };
    
    return (
        <Wrapper>
            <Grid>
                <thead>
                    <tr>
                        <ColHeader stick>Category</ColHeader>
                        {opsList.map(op => (
                            <ColHeader key={op} stick>
                                <div css={tw`flex flex-col items-center gap-1`}>
                                    <span css={tw`capitalize`}>{op.replace('-', ' ').replace('_', ' ')}</span>
                                    {isEditable && (
                                        <Btn type="button" onClick={() => bulkToggleOp(op)} aria-label={`Select all ${op}`}>
                                            All
                                        </Btn>
                                    )}
                                </div>
                            </ColHeader>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {Object.keys(authScopes).map(scope => {
                        const stats = computeStats(scope);
                        return (
                            <tr key={scope}>
                                <Cell stick>
                                    <div css={tw`flex flex-col gap-1`}>
                                        <span css={tw`capitalize`}>{scope}</span>
                                        <span css={tw`text-xs text-neutral-400`}>
                                            {stats.on} / {stats.all} enabled
                                        </span>
                                        {isEditable && (
                                            <Btn type="button" onClick={() => bulkToggleScope(scope)} aria-label={`Select all for ${scope}`}>
                                                Select All
                                            </Btn>
                                        )}
                                    </div>
                                </Cell>
                                {opsList.map(op => {
                                    const available = scopeHasOp(scope, op);
                                    const warning = warningOps.has(op);
                                    const canModify = checkModifiable(scope, op);

                                    if (!available) {
                                        return (
                                            <Cell key={op}>
                                                <span css={tw`text-neutral-600`}>—</span>
                                            </Cell>
                                        );
                                    }

                                    return (
                                        <Cell key={op} warn={warning && isEditable}>
                                            <Checkbox
                                                checked={checkActive(scope, op)}
                                                onChange={() => toggle(scope, op)}
                                                disabled={!isEditable || !canModify}
                                                aria-label={`${scope} ${op}`}
                                                css={tw`w-5 h-5`}
                                            />
                                        </Cell>
                                    );
                                })}
                            </tr>
                        );
                    })}
                </tbody>
            </Grid>
        </Wrapper>
    );
};

export default memo(AccessControlGrid);
