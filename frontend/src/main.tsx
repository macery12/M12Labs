import { startApplication } from '@/app/startApplication';
import { App } from '@/app/App';

// Players get the app tier; AdminLayout loads the admin copy when an admin page
// opens. A visit that starts in the admin area loads the full catalog up front
// (the page preloads it too) rather than fetching the app tier first.
const startsInAdmin = /^\/admin(\/|$)/.test(window.location.pathname);

void startApplication(App, startsInAdmin ? 'full' : 'app');
