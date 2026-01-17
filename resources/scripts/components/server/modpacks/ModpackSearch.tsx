import { useState } from 'react';
import tw from 'twin.macro';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import { type ModpackSearchParams } from '@/api/routes/server/modpacks';

interface Props {
    onSearch: (params: ModpackSearchParams) => void;
    initialParams: ModpackSearchParams;
}

const SORT_OPTIONS = [
    { value: '1', label: 'Featured' },
    { value: '2', label: 'Popularity' },
    { value: '3', label: 'Last Updated' },
    { value: '4', label: 'Name' },
    { value: '5', label: 'Author' },
    { value: '6', label: 'Total Downloads' },
];

// Minecraft version groups mapped to their gameVersionTypeId
// These are used to filter modpacks by major version ranges
const MINECRAFT_VERSION_GROUPS = [
    { typeId: '804', label: '1.21.x' },
    { typeId: '775', label: '1.20.x' },
    { typeId: '736', label: '1.19.x' },
    { typeId: '732', label: '1.18.x' },
    { typeId: '729', label: '1.17.x' },
    { typeId: '708', label: '1.16.x' },
    { typeId: '687', label: '1.15.x' },
    { typeId: '648', label: '1.14.x' },
    { typeId: '637', label: '1.13.x' },
    { typeId: '628', label: '1.12.x' },
    { typeId: '599', label: '1.11.x' },
    { typeId: '572', label: '1.10.x' },
    { typeId: '552', label: '1.9.x' },
    { typeId: '445', label: '1.8.x' },
    { typeId: '444', label: '1.7.10' },
];

const MOD_LOADER_TYPES = [
    { id: 1, name: 'Forge' },
    { id: 4, name: 'Fabric' },
    { id: 5, name: 'Quilt' },
    { id: 6, name: 'NeoForge' },
];

export default ({ onSearch, initialParams }: Props) => {
    const [searchFilter, setSearchFilter] = useState(initialParams.searchFilter || '');
    const [sortField, setSortField] = useState(initialParams.sortField || '2');
    const [gameVersionTypeId, setGameVersionTypeId] = useState(initialParams.gameVersionTypeId || '');
    const [modLoaderType, setModLoaderType] = useState<string>(initialParams.modLoaderType?.toString() || '');

    // No need to fetch versions from API - using predefined version groups instead

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSearch({
            searchFilter: searchFilter || undefined,
            sortField,
            sortOrder: 'desc',
            gameVersionTypeId: gameVersionTypeId || undefined,
            modLoaderType: modLoaderType ? parseInt(modLoaderType, 10) : undefined,
            pageSize: 20,
            index: 0,
        });
    };

    const handleClear = () => {
        setSearchFilter('');
        setSortField('2');
        setGameVersionTypeId('');
        setModLoaderType('');
        onSearch({
            sortField: '2',
            sortOrder: 'desc',
            pageSize: 20,
            index: 0,
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6`}>
                <div>
                    <Label>Search</Label>
                    <Input
                        type={'text'}
                        placeholder={'Search modpacks...'}
                        value={searchFilter}
                        onChange={e => setSearchFilter(e.target.value)}
                    />
                </div>

                <div>
                    <Label>Minecraft Version</Label>
                    <Select value={gameVersionTypeId} onChange={e => setGameVersionTypeId(e.target.value)}>
                        <option value="">All Versions</option>
                        {MINECRAFT_VERSION_GROUPS.map(versionGroup => (
                            <option key={versionGroup.typeId} value={versionGroup.typeId}>
                                {versionGroup.label}
                            </option>
                        ))}
                    </Select>
                </div>

                <div>
                    <Label>Mod Loader</Label>
                    <Select value={modLoaderType} onChange={e => setModLoaderType(e.target.value)}>
                        <option value="">All Loaders</option>
                        {MOD_LOADER_TYPES.map(loader => (
                            <option key={loader.id} value={loader.id}>
                                {loader.name}
                            </option>
                        ))}
                    </Select>
                </div>

                <div>
                    <Label>Sort By</Label>
                    <Select value={sortField} onChange={e => setSortField(e.target.value)}>
                        {SORT_OPTIONS.map(option => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>

            <div css={tw`flex gap-2`}>
                <Button type={'submit'}>Search</Button>
                <Button.Text type={'button'} onClick={handleClear}>
                    Clear Filters
                </Button.Text>
            </div>
        </form>
    );
};
