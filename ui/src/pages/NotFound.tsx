import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

export default function NotFound() {
    const { t } = useTranslation();
    return (
        <div className="bg-aurora flex min-h-screen flex-col items-center justify-center px-6 text-center">
            <p className="text-6xl font-bold text-[var(--brand)]">{t('notFound.code')}</p>
            <h1 className="mt-4 text-2xl font-semibold">{t('notFound.title')}</h1>
            <p className="mt-2 text-sm text-[var(--color-ink-muted)]">
                {t('notFound.body')}
            </p>
            <Link
                to="/v2"
                className="mt-8 inline-flex h-11 items-center rounded-xl bg-[var(--brand)] px-6 text-sm font-medium text-[var(--color-brand-ink)] hover:bg-[var(--brand-hover)]"
            >
                {t('notFound.back')}
            </Link>
        </div>
    );
}
