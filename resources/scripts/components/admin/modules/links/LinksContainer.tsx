import { useState } from 'react';
import { CustomLink } from '@/api/routes/admin/links';
import AdminContentBlock from '@/elements/AdminContentBlock';
import AlertRenderer from '@/components/AlertRenderer';
import { Button } from '@/elements/button';
import CreateLinkDialog from './CreateLinkDialog';
import DeleteLinkDialog from './DeleteLinkDialog';
import LinksTable from './LinksTable';

export type VisibleDialog = 'none' | 'create' | 'update' | 'delete';

export default () => {
    const [link, setLink] = useState<CustomLink | null>(null);
    const [open, setOpen] = useState<VisibleDialog>('none');

    return (
        <AdminContentBlock title={'Custom Links'}>
            {open === 'create' && <CreateLinkDialog setOpen={setOpen} />}
            {open === 'update' && link && <CreateLinkDialog link={link} setOpen={setOpen} />}
            {open === 'delete' && <DeleteLinkDialog id={link?.id} setOpen={setOpen} />}
            <AlertRenderer filterByKey={'admin:links'} className={'mb-4'} position="top-center" />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Custom Links</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Create custom links to external sites for clients.
                    </p>
                </div>
                <div className={'ml-auto flex pl-4'}>
                    <Button onClick={() => setOpen('create')}>New Link</Button>
                </div>
            </div>
            <LinksTable setLink={setLink} setOpen={setOpen} />
        </AdminContentBlock>
    );
};
