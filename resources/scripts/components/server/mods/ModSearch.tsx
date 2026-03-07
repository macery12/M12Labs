import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import { type ModSearchParams, getMinecraftVersions } from '@/api/routes/server/mods';
import { httpErrorToHuman } from '@/api/http';
import useFlash from '@/plugins/useFlash';

interface Props {
    onSearch: (params: ModSearchParams) => void;
    initialParams: ModSearchParams;
    source: string;
    contentType?: 'mods' | 'plugins';
    filtersMeta?: {
        options?: {
            categories?: Array<{ id: number; name: string }>;
            sortBy?: Array<{ id: string; label: string }>;
            minRating?: Array<{ id: number | null; label: string }>;
            platforms?: Array<{ id: string; name: string }>;
        };
        unsupported?: Record<string, string>;
    };
}

const DEFAULT_SORT_OPTIONS = [
    { value: '1', label: 'Featured' },
    { value: '2', label: 'Popularity' },
    { value: '3', label: 'Last Updated' },
    { value: '4', label: 'Name' },
    { value: '5', label: 'Author' },
    { value: '6', label: 'Total Downloads' },
];

export default ({ onSearch, initialParams, source, contentType = 'mods', filtersMeta }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addError } = useFlash();

    const [searchFilter, setSearchFilter] = useState(initialParams.searchFilter || '');
    const [sortField, setSortField] = useState(initialParams.sortField || (source === 'spigot' ? 'downloads' : '2'));
    const [gameVersion, setGameVersion] = useState(initialParams.gameVersion || '');
    const [modLoaderType, setModLoaderType] = useState<string>(initialParams.modLoaderType?.toString() || '');
    const [categoryId, setCategoryId] = useState<string>('');
    const [minRating, setMinRating] = useState<string>(initialParams.minRating?.toString() ?? '');
    const [platforms, setPlatforms] = useState<string[]>(
        Array.isArray(initialParams.platform)
            ? initialParams.platform
            : initialParams.platform
              ? [initialParams.platform]
              : [],
    );

    const [minecraftVersions, setMinecraftVersions] = useState<string[]>([]);
    useEffect(() => {
        if (contentType !== 'plugins' || source !== 'modrinth') {
            setPlatforms([]);
        }
    }, [contentType, source]);

    useEffect(() => {
        if (source === 'spigot') {
            setMinecraftVersions([]);
            setModLoaders([]);
            return;
        }

        getMinecraftVersions(uuid, source, contentType)
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
                addError({ key: 'mods', message: httpErrorToHuman(error) });
            });

    }, [uuid, source, contentType]);

    useEffect(() => {
        if (source !== 'spigot') return;
        const timer = setTimeout(() => {
            onSearch({
                searchFilter: searchFilter || undefined,
                sortField,
                sortOrder: 'desc',
                pageSize: 20,
                index: 0,
                categoryId: categoryId ? parseInt(categoryId, 10) : undefined,
                minRating: minRating ? parseFloat(minRating) : undefined,
                resource: contentType,
            });
        }, 400);

        return () => clearTimeout(timer);
    }, [searchFilter, sortField, categoryId, minRating, source, contentType]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSearch({
            searchFilter: searchFilter || undefined,
            sortField,
            sortOrder: 'desc',
            gameVersion: source === 'spigot' ? undefined : gameVersion || undefined,
            modLoaderType: source === 'spigot' ? undefined : modLoaderType ? parseInt(modLoaderType, 10) : undefined,
            categoryId: categoryId ? parseInt(categoryId, 10) : undefined,
            minRating: source === 'spigot' && minRating ? parseFloat(minRating) : undefined,
            platform: platforms.length ? platforms : undefined,
            resource: contentType,
            pageSize: 20,
            index: 0,
        });
    };

    const handleClear = () => {
        setSearchFilter('');
        setSortField(source === 'spigot' ? 'downloads' : '2');
        setGameVersion('');
        setModLoaderType('');
        setCategoryId('');
        setMinRating('');
        setPlatforms([]);
        onSearch({
            sortField: source === 'spigot' ? 'downloads' : '2',
            sortOrder: 'desc',
            platform: undefined,
            resource: contentType,
            pageSize: 20,
            index: 0,
        });
    };

    const sortOptions =
        filtersMeta?.options?.sortBy?.length
            ? filtersMeta.options.sortBy.map(option => ({ value: option.id, label: option.label }))
            : DEFAULT_SORT_OPTIONS;

    const categories = filtersMeta?.options?.categories || [];
    const minRatingOptions =
        source === 'spigot' && filtersMeta?.options?.minRating?.length
            ? filtersMeta.options.minRating
            : [
                  { id: null, label: 'Any Rating' },
                  { id: 4.5, label: '4.5+' },
                  { id: 4.0, label: '4.0+' },
                  { id: 3.5, label: '3.5+' },
                  { id: 3.0, label: '3.0+' },
              ];
    const showMinecraftVersion = source !== 'spigot';
    const showPlatformFilter = contentType === 'plugins' && source === 'modrinth';
    const platformOptions =
        filtersMeta?.options?.platforms?.map(option => ({ value: option.id, label: option.name })) ??
        [
            { value: 'paper', label: 'Paper' },
            { value: 'purpur', label: 'Purpur' },
            { value: 'spigot', label: 'Spigot' },
            { value: 'bukkit', label: 'Bukkit' },
            { value: 'folia', label: 'Folia' },
            { value: 'velocity', label: 'Velocity' },
            { value: 'waterfall', label: 'Waterfall' },
            { value: 'sponge', label: 'Sponge' },
            { value: 'bungeecord', label: 'BungeeCord' },
        ];
    const searchPlaceholder = contentType === 'plugins' ? 'Search plugins...' : 'Search mods...';

    return (
        <form onSubmit={handleSubmit}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6`}>
                <div>
                    <Label>Search</Label>
                    <Input
                        type={'text'}
                        placeholder={searchPlaceholder}
                        value={searchFilter}
                        onChange={e => setSearchFilter(e.target.value)}
                    />
                </div>

                {showMinecraftVersion ? (
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
                ) : (
                    <div>
                        <Label>Minimum Rating</Label>
                        <Select value={minRating} onChange={e => setMinRating(e.target.value)}>
                            {minRatingOptions.map(option => (
                                <option key={option.label} value={option.id ?? ''}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}

                <div>
                    <Label>Sort By</Label>
                    <Select value={sortField} onChange={e => setSortField(e.target.value)}>
                        {sortOptions.map(option => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </div>

                {showPlatformFilter && (
                    <div>
                        <Label>Platforms (any)</Label>
                        <Select
                            multiple
                            value={platforms}
                            onChange={e =>
                                setPlatforms(
                                    Array.from(e.target.selectedOptions)
                                        .map(option => option.value)
                                        .filter(Boolean),
                                )
                            }
                        >
                            <option value="">Any Platform</option>
                            {platformOptions.map(option => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}

                {source === 'spigot' && (
                    <div>
                        <Label>Category</Label>
                        <Select value={categoryId} onChange={e => setCategoryId(e.target.value)}>
                            <option value="">All Categories</option>
                            {categories.map(category => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}
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
