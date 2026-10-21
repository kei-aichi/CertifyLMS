import { getJson, postJson } from '../utils/fetch-json';

const API_URL = '/api/v1/notifications';

export function initNotificationPopovers() {
    document.querySelectorAll('[data-notification-popover-root]').forEach((root) => {
        const trigger = root.querySelector('[data-notification-popover-trigger]');
        const panel = root.querySelector('[data-notification-popover-panel]');
        if (!trigger || !panel || root.dataset.notificationInitialized === 'true') return;

        root.dataset.notificationInitialized = 'true';
        const state = { tab: 'all', loading: false, action: false };
        const tabs = root.querySelectorAll('[data-notification-popover-tab]');
        const list = root.querySelector('[data-notification-popover-items]');
        const loading = root.querySelector('[data-notification-popover-loading]');
        const empty = root.querySelector('[data-notification-popover-empty]');
        const template = root.querySelector('[data-notification-popover-row-template]');
        const badge = root.querySelector('[data-notification-popover-badge]');
        const unreadCount = root.querySelector('[data-notification-popover-unread-count]');
        const markAll = root.querySelector('[data-notification-popover-mark-all]');

        const setBadge = (count) => {
            const value = Number.isFinite(Number(count)) ? Math.max(0, Number(count)) : 0;
            if (badge) {
                badge.textContent = value > 99 ? '99+' : String(value);
                badge.classList.toggle('hidden', value === 0);
            }
            if (unreadCount) unreadCount.textContent = String(value);
        };

        const showError = () => {
            if (!empty) return;
            empty.textContent = '通知の取得に失敗しました。時間をおいて再試行してください。';
            empty.classList.remove('hidden');
        };

        const setLoading = (visible) => {
            state.loading = visible;
            if (loading) loading.classList.toggle('hidden', !visible);
            if (list) list.classList.toggle('hidden', visible);
        };

        const render = (items) => {
            if (!list || !template || !empty) return;
            list.replaceChildren();
            empty.textContent = '通知はありません。';
            empty.classList.toggle('hidden', items.length > 0);
            items.forEach((notification) => {
                const row = template.content.cloneNode(true);
                const link = row.querySelector('[data-notification-popover-row]');
                const title = row.querySelector('[data-notification-popover-row-title]');
                const message = row.querySelector('[data-notification-popover-row-message]');
                const time = row.querySelector('[data-notification-popover-row-time]');
                const dot = row.querySelector('[data-notification-popover-row-dot]');
                if (!link) return;

                link.dataset.unread = notification.is_unread ? 'true' : 'false';
                link.setAttribute('aria-label', notification.title || '通知');
                if (title) title.textContent = notification.title || '通知';
                if (message) message.textContent = notification.preview || '';
                if (time) time.textContent = notification.relative_time || '';
                if (dot) dot.classList.toggle('invisible', !notification.is_unread);
                link.addEventListener('click', async (event) => {
                    event.preventDefault();
                    if (state.action || !notification.id) return;
                    state.action = true;
                    link.setAttribute('aria-disabled', 'true');
                    try {
                        const result = await postJson(`${API_URL}/${encodeURIComponent(notification.id)}/read`);
                        window.location.assign(result?.data?.redirect_url || '/notifications');
                    } catch (error) {
                        showError();
                        state.action = false;
                        link.removeAttribute('aria-disabled');
                    }
                });
                list.appendChild(row);
            });
        };

        const load = async () => {
            if (state.loading) return;
            setLoading(true);
            try {
                const payload = await getJson(`${API_URL}?tab=${encodeURIComponent(state.tab)}`);
                render(Array.isArray(payload?.data) ? payload.data : []);
                setBadge(payload?.meta?.unread_count ?? 0);
                return true;
            } catch (error) {
                showError();
                return false;
            } finally {
                setLoading(false);
            }
        };

        const close = () => {
            panel.classList.add('hidden');
            panel.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger.addEventListener('click', async () => {
            const open = !panel.classList.contains('hidden');
            if (open) {
                close();
                return;
            }
            panel.classList.remove('hidden');
            panel.style.display = 'flex';
            trigger.setAttribute('aria-expanded', 'true');
            await load();
        });

        tabs.forEach((tab) => tab.addEventListener('click', async () => {
            if (state.loading || state.action) return;
            const previousTab = state.tab;
            const nextTab = tab.dataset.notificationPopoverTab || 'all';
            state.tab = nextTab;
            if (await load()) {
                tabs.forEach((item) => item.setAttribute('aria-selected', String(item === tab)));
            } else {
                state.tab = previousTab;
                tabs.forEach((item) => item.setAttribute(
                    'aria-selected',
                    String(item.dataset.notificationPopoverTab === previousTab),
                ));
            }
        }));

        markAll?.addEventListener('click', async () => {
            if (state.action) return;
            state.action = true;
            markAll.disabled = true;
            try {
                const payload = await postJson(`${API_URL}/read-all`);
                setBadge(payload?.data?.unread_count ?? 0);
                await load();
            } catch (error) {
                showError();
            } finally {
                state.action = false;
                markAll.disabled = false;
            }
        });

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) close();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.classList.contains('hidden')) {
                close();
                trigger.focus();
            }
        });
    });
}
