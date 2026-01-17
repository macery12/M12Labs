import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import { type ModpackSearchParams } from '@/api/routes/server/modpacks';
import { getMinecraftVersions, getModLoaderTypes } from '@/api/routes/account/modpacks';
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

const MOD_LOADER_TYPES = [
    { id: 1, name: 'Forge' },
    { id: 4, name: 'Fabric' },
    { id: 5, name: 'Quilt' },
    { id: 6, name: 'NeoForge' },
];

export default ({ onSearch, initialParams }: Props) => {
    const { addError } = useFlash();

    const [searchFilter, setSearchFilter] = useState(initialParams.searchFilter || '');
    const [sortField, setSortField] = useState(initialParams.sortField || '2');
    const [gameVersion, setGameVersion] = useState(initialParams.gameVersion || '');
    const [modLoaderType, setModLoaderType] = useState<string>(initialParams.modLoaderType?.toString() || '');

    const [minecraftVersions, setMinecraftVersions] = useState<string[]>([]);

    useEffect(() => {
        getMinecraftVersions()
            .then(response => {
                // Filter to only valid Minecraft versions
                // Less restrictive filter - include any version with a versionString
                const versions = response.data
                    .filter(v => v.versionString && v.versionString.trim() !== '')
                    .map(v => v.versionString)
                    // Remove duplicates
                    .filter((v, i, arr) => arr.indexOf(v) === i)
                    // Sort by version number (descending) - handle various formats
                    .sort((a, b) => {
                        // Extract version numbers, handling formats like "1.20.1", "1.12.2-Snapshot", etc.
                        const aMatch = a.match(/^(\d+)\.(\d+)(?:\.(\d+))?/);
                        const bMatch = b.match(/^(\d+)\.(\d+)(?:\.(\d+))?/);

                        if (!aMatch || !bMatch) {
                            return a.localeCompare(b);
                        }

                        const aParts = [
                            parseInt(aMatch[1] || '0', 10),
                            parseInt(aMatch[2] || '0', 10),
                            parseInt(aMatch[3] || '0', 10),
                        ];
                        const bParts = [
                            parseInt(bMatch[1] || '0', 10),
                            parseInt(bMatch[2] || '0', 10),
                            parseInt(bMatch[3] || '0', 10),
                        ];

                        for (let i = 0; i < 3; i++) {
                            if (aParts[i] !== bParts[i]) {
                                return bParts[i] - aParts[i];
                            }
                        }
                        return 0;
                    });
                setMinecraftVersions(versions);
            })
            .catch(error => {
                console.error('Error fetching Minecraft versions:', error);
                addError({ key: 'account:modpacks', message: httpErrorToHuman(error) });
            });
    }, []);

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
