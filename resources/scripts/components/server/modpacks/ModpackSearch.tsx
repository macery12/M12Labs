import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import { type ModpackSearchParams, getMinecraftVersions, getModLoaderTypes } from '@/api/routes/server/modpacks';
import { httpErrorToHuman } from '@/api/http';
import useFlash from '@/plugins/useFlash';

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

export default ({ onSearch, initialParams }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addError } = useFlash();

    const [searchFilter, setSearchFilter] = useState(initialParams.searchFilter || '');
    const [sortField, setSortField] = useState(initialParams.sortField || '2');
    const [gameVersion, setGameVersion] = useState(initialParams.gameVersion || '');
    const [modLoaderType, setModLoaderType] = useState<string>(
        initialParams.modLoaderType?.toString() || ''
    );

    const [minecraftVersions, setMinecraftVersions] = useState<string[]>([]);
    const [modLoaders, setModLoaders] = useState<Array<{ id: number; name: string }>>([]);

    useEffect(() => {
        getMinecraftVersions(uuid)
            .then(response => {
                const versions = response.data
                    .filter(v => v.versionString && v.gameVersionTypeId === 1)
                    .map(v => v.versionString)
                    .filter((v, i, arr) => arr.indexOf(v) === i)
                    .sort((a, b) => {
                        const aParts = a.split('.').map(Number);
                        const bParts = b.split('.').map(Number);
                        for (let i = 0; i < Math.max(aParts.length, bParts.length); i++) {
                            const aNum = aParts[i] || 0;
                            const bNum = bParts[i] || 0;
                            if (aNum !== bNum) return bNum - aNum;
                        }
                        return 0;
                    });
                setMinecraftVersions(versions);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
            });

        getModLoaderTypes(uuid)
            .then(response => {
                // CurseForge mod loader API returns different structure
                const loaders = response.data
                    .filter((ml) => ml.name && (
                        ml.name.toLowerCase().includes('forge') ||
                        ml.name.toLowerCase().includes('fabric') ||
                        ml.name.toLowerCase().includes('quilt') ||
                        ml.name.toLowerCase() === 'neoforge'
                    ))
                    .map((ml) => ({
                        id: ml.id,
                        name: ml.name
                    }));
                setModLoaders(loaders);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
            });
    }, [uuid]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSearch({
            searchFilter: searchFilter || undefined,
            sortField,
            sortOrder: 'desc',
            gameVersion: gameVersion || undefined,
            modLoaderType: modLoaderType ? parseInt(modLoaderType, 10) : undefined,
            pageSize: 20,
            index: 0,
        });
    };

    const handleClear = () => {
        setSearchFilter('');
        setSortField('2');
        setGameVersion('');
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
                    <Select value={gameVersion} onChange={e => setGameVersion(e.target.value)}>
                        <option value="">All Versions</option>
                        {minecraftVersions.map(version => (
                            <option key={version} value={version}>
                                {version}
                            </option>
                        ))}
                    </Select>
                </div>

                <div>
                    <Label>Mod Loader</Label>
                    <Select value={modLoaderType} onChange={e => setModLoaderType(e.target.value)}>
                        <option value="">All Loaders</option>
                        {modLoaders.map(loader => (
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
