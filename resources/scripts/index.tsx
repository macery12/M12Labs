import { render } from 'preact';
import { App } from '@/components/App';
import '@/i18n';

render(<App />, document.getElementById('app')!);
