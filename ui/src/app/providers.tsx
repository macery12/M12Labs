import { QueryClientProvider } from '@tanstack/react-query';
import { I18nextProvider } from 'react-i18next';
import { queryClient } from '@/lib/query';
import { FlashHost } from '@/components/shell/FlashHost';
import i18n from '@/i18n';

export function Providers({ children }: { children: React.ReactNode }) {
    return (
        <I18nextProvider i18n={i18n}>
            <QueryClientProvider client={queryClient}>
                {children}
                <FlashHost />
            </QueryClientProvider>
        </I18nextProvider>
    );
}
