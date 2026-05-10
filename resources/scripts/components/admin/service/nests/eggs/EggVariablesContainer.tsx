import { ChevronDownIcon, ChevronUpIcon, TrashIcon } from '@heroicons/react/outline';
import type { FormikHelpers } from 'formik';
import { Form, Formik, useFormikContext } from 'formik';
import { useState } from 'react';
import tw from 'twin.macro';
import { array, boolean, object, string } from 'yup';

import deleteEggVariable from '@/api/routes/admin/eggs/deleteEggVariable';
import updateEggVariables from '@/api/routes/admin/eggs/updateEggVariables';
import { NoItems } from '@/elements/AdminTable';
import ConfirmationModal from '@/elements/ConfirmationModal';
import type { EggVariable } from '@/api/routes/admin/egg';
import { useEggFromRoute } from '@/api/routes/admin/egg';
import NewVariableButton from '@admin/service/nests/eggs/NewVariableButton';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import Field, { FieldRow, TextareaField } from '@/elements/Field';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import Label from '@/elements/Label';
import { useStoreState } from '@/state/hooks';
import Checkbox from '@/elements/inputs/Checkbox';

const RULE_SUGGESTIONS = ['required', 'nullable', 'string', 'numeric', 'boolean', 'ip', 'alpha_num'];

export const validationSchema = object().shape({
    name: string().required().min(1).max(191),
    description: string(),
    environmentVariable: string().required().min(1).max(191),
    defaultValue: string(),
    isUserViewable: boolean().required(),
    isUserEditable: boolean().required(),
    fieldType: string().oneOf(['text', 'password', 'number', 'boolean']).required(),
    rules: string().required(),
});

function parseRules(input: string): string[] {
    return (input || '')
        .split('|')
        .map(rule => rule.trim())
        .filter(rule => rule.length > 0);
}

function typeBadgeStyles(type: EggVariable['fieldType']) {
    switch (type) {
        case 'password':
            return tw`bg-red-500/10 text-red-300 border border-red-500/40`;
        case 'number':
            return tw`bg-blue-500/10 text-blue-300 border border-blue-500/40`;
        case 'boolean':
            return tw`bg-yellow-500/10 text-yellow-300 border border-yellow-500/40`;
        case 'text':
        default:
            return tw`bg-green-500/10 text-green-300 border border-green-500/40`;
    }
}

function RulesBuilder({ prefix }: { prefix: string }) {
    const { values, setFieldValue } = useFormikContext<any>();
    const current = parseRules(values[`${prefix}rules`] || '');
    const [draft, setDraft] = useState('');

    const setRules = (next: string[]) => {
        setFieldValue(`${prefix}rules`, next.join('|'));
    };

    return (
        <div css={tw`mb-2`}>
            <Label>Validation Rules</Label>

            <div css={tw`mt-2 flex flex-wrap gap-2`}>
                {current.map(rule => (
                    <button
                        type={'button'}
                        key={rule}
                        onClick={() => setRules(current.filter(item => item !== rule))}
                        css={tw`text-xs rounded px-2 py-1 bg-neutral-900 border border-neutral-700 hover:border-neutral-500`}
                    >
                        {rule} x
                    </button>
                ))}
            </div>

            <div css={tw`grid grid-cols-1 md:grid-cols-[1fr_auto] gap-2 mt-3`}>
                <input
                    value={draft}
                    onChange={e => setDraft(e.currentTarget.value)}
                    placeholder={'Add a rule, example: max:255'}
                    css={tw`w-full rounded px-3 py-2 bg-neutral-900 border border-neutral-700 text-neutral-100`}
                />
                <Button
                    type={'button'}
                    onClick={() => {
                        const next = draft.trim();
                        if (!next || current.includes(next)) {
                            return;
                        }
                        setRules([...current, next]);
                        setDraft('');
                    }}
                >
                    Add Rule
                </Button>
            </div>

            <div css={tw`mt-2 flex flex-wrap gap-2`}>
                {RULE_SUGGESTIONS.map(rule => (
                    <Button.Text
                        key={rule}
                        type={'button'}
                        css={tw`px-2 py-1 text-xs`}
                        onClick={() => {
                            if (!current.includes(rule)) {
                                setRules([...current, rule]);
                            }
                        }}
                    >
                        {rule}
                    </Button.Text>
                ))}
            </div>
        </div>
    );
}

export function EggVariableForm({ prefix, variable }: { prefix: string; variable?: EggVariable }) {
    return (
        <>
            <Field id={`${prefix}name`} name={`${prefix}name`} label={'Name'} type={'text'} css={tw`mb-6`} />

            <TextareaField
                id={`${prefix}description`}
                name={`${prefix}description`}
                label={'Description'}
                rows={3}
                css={tw`mb-4`}
            />

            <FieldRow>
                <Field
                    id={`${prefix}environmentVariable`}
                    name={`${prefix}environmentVariable`}
                    label={'Environment Variable'}
                    type={'text'}
                />

                <Field
                    id={`${prefix}defaultValue`}
                    name={`${prefix}defaultValue`}
                    label={'Default Value'}
                    type={'text'}
                />
            </FieldRow>

            <div css={tw`mb-4`}>
                <Label htmlFor={`${prefix}fieldType`}>Field Type</Label>
                <Field as={'select'} id={`${prefix}fieldType`} name={`${prefix}fieldType`} css={tw`w-full mt-2`}>
                    <option value={'text'}>Text</option>
                    <option value={'password'}>Password</option>
                    <option value={'number'}>Number</option>
                    <option value={'boolean'}>Boolean</option>
                </Field>
            </div>

            <div css={tw`flex flex-row mb-6`}>
                <div className="ml-auto flex flex-row items-center">
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        defaultChecked={variable?.isUserViewable}
                        id={`${prefix}isUserViewable`}
                        name={`${prefix}isUserViewable`}
                    />
                    <div css={tw`flex-1 ml-4`}>
                        <Label>User Viewable</Label>
                    </div>
                </div>

                <div className="ml-auto flex flex-row items-center">
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        defaultChecked={variable?.isUserEditable}
                        id={`${prefix}isUserEditable`}
                        name={`${prefix}isUserEditable`}
                    />
                    <div css={tw`flex-1 ml-4`}>
                        <Label>User Editable</Label>
                    </div>
                </div>
            </div>

            <RulesBuilder prefix={prefix} />
        </>
    );
}

function EggVariableDeleteButton({ onClick }: { onClick: (success: () => void) => void }) {
    const [visible, setVisible] = useState(false);
    const [loading, setLoading] = useState(false);

    const onDelete = () => {
        setLoading(true);

        onClick(() => {
            setLoading(false);
            setVisible(false);
        });
    };

    return (
        <>
            <ConfirmationModal
                visible={visible}
                title={'Delete variable?'}
                buttonText={'Yes, delete variable'}
                onConfirmed={onDelete}
                showSpinnerOverlay={loading}
                onModalDismissed={() => setVisible(false)}
            >
                Are you sure you want to delete this variable? Deleting this variable will delete it from every server
                using this egg.
            </ConfirmationModal>

            <button
                type={'button'}
                css={tw`ml-auto text-neutral-500 hover:text-neutral-300`}
                onClick={() => setVisible(true)}
            >
                <TrashIcon className="h-5 w-5" />
            </button>
        </>
    );
}

function EggVariableBox({
    onDeleteClick,
    variable,
    prefix,
    index,
    expanded,
    onToggle,
    onDragStart,
    onDrop,
}: {
    onDeleteClick: (success: () => void) => void;
    variable: EggVariable;
    prefix: string;
    index: number;
    expanded: boolean;
    onToggle: () => void;
    onDragStart: (index: number) => void;
    onDrop: (index: number) => void;
}) {
    const { isSubmitting } = useFormikContext();
    const rules = parseRules(variable.rules || '');
    const isRequired = rules.includes('required');
    const defaultValue = variable.defaultValue?.trim() || 'none';

    return (
        <div draggable onDragStart={() => onDragStart(index)} onDragOver={e => e.preventDefault()} onDrop={() => onDrop(index)}>
            <AdminBox
                css={tw`relative w-full`}
                title={
                    <button type={'button'} onClick={onToggle} css={tw`w-full flex items-start text-left`}>
                        <div css={tw`min-w-0`}>
                            <div css={tw`text-sm uppercase font-semibold truncate`}>
                                {variable.environmentVariable || variable.name}
                            </div>
                            <div css={tw`mt-2 flex flex-wrap gap-2 text-xs`}>
                                <span css={[tw`px-2 py-0.5 rounded`, typeBadgeStyles(variable.fieldType)]}>
                                    {variable.fieldType}
                                </span>
                                <span css={tw`px-2 py-0.5 rounded bg-neutral-800 text-neutral-300 border border-neutral-700`}>
                                    default: {defaultValue}
                                </span>
                                {isRequired ? (
                                    <span css={tw`px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/40`}>
                                        Required
                                    </span>
                                ) : null}
                                {variable.isUserViewable ? (
                                    <span css={tw`px-2 py-0.5 rounded bg-purple-500/10 text-purple-300 border border-purple-500/40`}>
                                        Viewable
                                    </span>
                                ) : null}
                                {variable.isUserEditable ? (
                                    <span css={tw`px-2 py-0.5 rounded bg-green-500/10 text-green-300 border border-green-500/40`}>
                                        Editable
                                    </span>
                                ) : null}
                            </div>
                        </div>
                        <div css={tw`ml-auto mt-1`}>
                            {expanded ? <ChevronUpIcon className={'h-4 w-4'} /> : <ChevronDownIcon className={'h-4 w-4'} />}
                        </div>
                    </button>
                }
                button={<EggVariableDeleteButton onClick={onDeleteClick} />}
            >
                <SpinnerOverlay visible={isSubmitting} />
                {expanded ? <EggVariableForm prefix={prefix} variable={variable} /> : null}
            </AdminBox>
        </div>
    );
}

export default function EggVariablesContainer() {
    const { clearAndAddHttpError } = useFlash();
    const { data: egg, mutate } = useEggFromRoute();
    const { secondary } = useStoreState(state => state.theme.data!.colors);
    const [expanded, setExpanded] = useState<number[]>([]);
    const [dragging, setDragging] = useState<number | null>(null);

    if (!egg) {
        return null;
    }

    const submit = (values: EggVariable[], { setSubmitting }: FormikHelpers<EggVariable[]>) => {
        updateEggVariables(egg.id, values)
            .then(async () => await mutate())
            .catch(error => clearAndAddHttpError({ key: 'egg', error }))
            .then(() => setSubmitting(false));
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={egg.relationships.variables}
            validationSchema={array().of(validationSchema)}
        >
            {({ isSubmitting, isValid, values, setValues }) => (
                <Form>
                    <div css={tw`flex flex-col mb-16`}>
                        {values?.length === 0 ? (
                            <NoItems css={tw`bg-neutral-700 rounded-md shadow-md`} />
                        ) : (
                            <div css={tw`space-y-4`}>
                                {values.map((v, i) => (
                                    <EggVariableBox
                                        key={v.id || i}
                                        index={i}
                                        prefix={`[${i}].`}
                                        variable={v}
                                        expanded={expanded.includes(i)}
                                        onToggle={() =>
                                            setExpanded(list =>
                                                list.includes(i) ? list.filter(item => item !== i) : [...list, i],
                                            )
                                        }
                                        onDragStart={index => setDragging(index)}
                                        onDrop={index => {
                                            if (dragging === null || dragging === index) {
                                                return;
                                            }

                                            const next = [...values];
                                            const [moved] = next.splice(dragging, 1);
                                            next.splice(index, 0, moved);
                                            setValues(next);
                                            setDragging(null);
                                        }}
                                        onDeleteClick={success => {
                                            deleteEggVariable(egg.id, v.id)
                                                .then(async () => {
                                                    await mutate(current => ({
                                                        ...current!,
                                                        relationships: {
                                                            ...current!.relationships,
                                                            variables: current!.relationships.variables!.filter(
                                                                variable => variable.id !== v.id,
                                                            ),
                                                        },
                                                    }));
                                                    success();
                                                })
                                                .catch(error => clearAndAddHttpError({ key: 'egg', error }));
                                        }}
                                    />
                                ))}
                            </div>
                        )}

                        <div css={tw`rounded shadow-md py-2 px-4 mt-6`} style={{ backgroundColor: secondary }}>
                            <div css={tw`flex flex-row gap-3 flex-wrap`}>
                                <NewVariableButton />

                                <Button.Text type={'button'} onClick={() => setExpanded(values.map((_, i) => i))}>
                                    Expand All
                                </Button.Text>

                                <Button.Text type={'button'} onClick={() => setExpanded([])}>
                                    Collapse All
                                </Button.Text>

                                <Button type="submit" className="ml-auto" disabled={isSubmitting || !isValid}>
                                    Save Changes
                                </Button>
                            </div>
                        </div>
                    </div>
                </Form>
            )}
        </Formik>
    );
}
