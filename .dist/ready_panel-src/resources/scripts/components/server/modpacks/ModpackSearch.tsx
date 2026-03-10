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

export default ({ onSearch, initialParams }: Props) => {
    const [searchFilter, setSearchFilter] = useState(initialParams.searchFilter || '');
    const [sortField, setSortField] = useState(initialParams.sortField || '2');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Build params object with only defined values (no empty strings)
        const searchParams: ModpackSearchParams = {
            pageSize: 20,
            index: 0,
        };

        if (searchFilter && searchFilter.trim()) {
            searchParams.searchFilter = searchFilter.trim();
        }

        if (sortField) {
            searchParams.sortField = sortField;
            searchParams.sortOrder = 'desc';
        }

        console.log('Searching modpacks with params:', searchParams);
        onSearch(searchParams);
    };

    const handleClear = () => {
        setSearchFilter('');
        setSortField('2');
        onSearch({
            sortField: '2',
            sortOrder: 'desc',
            pageSize: 20,
            index: 0,
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-6`}>
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
