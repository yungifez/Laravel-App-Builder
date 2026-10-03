import type { AppFault } from '@/types';

// What the owner can pretend is down while they try the app. The app's
// pages then show what a visitor would see. Each comes with a line that
// says what it means, since an option itself cannot explain.
export const faults: { key: AppFault; label: string; hint: string }[] = [
    { key: 'none', label: 'All works', hint: '' },
    {
        key: 'mail',
        label: 'Email is down',
        hint: 'Your app cannot reach the mail server, so nothing it tries to email goes out.',
    },
    {
        key: 'http',
        label: 'Outside services do not answer',
        hint: 'Other services your app asks, such as payments or maps, do not answer.',
    },
    {
        key: 'file',
        label: 'Storage is full',
        hint: 'Your app cannot save any file, such as an upload or a PDF.',
    },
    {
        key: 'cache',
        label: 'The cache is down',
        hint: 'The cache is where your app keeps answers it needs again soon, so pages load fast. Without it, pages that lean on it fail.',
    },
    {
        key: 'notification',
        label: 'Notices do not go out',
        hint: 'Notices your app leaves for people, by email, text or inside the app, cannot go out.',
    },
];
