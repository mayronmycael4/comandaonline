/* ========================================
   MOBILE BOTTOM NAVBAR NAVIGATION
   Agora renderizado pelo Menu Geral
   ======================================== */

class MobileNavBar {
    enforceMobilePagePermission() {
        if (!window.MenuGeral || typeof window.MenuGeral.getRequiredPermission !== 'function') return true;
        if (typeof Storage === 'undefined' || typeof Storage.getSession !== 'function' || typeof Storage.hasPermission !== 'function') return true;

        const session = Storage.getSession();
        if (!session) return true;

        const page = window.location.pathname.split('/').pop().toLowerCase();
        const required = window.MenuGeral.getRequiredPermission(page);
        if (!required || session.isAdmin || Storage.hasPermission(required)) return true;

        const fallback = (window.MenuGeral.MOBILE_MENU_ITEMS || [])
            .find((item) => !item.permission || Storage.hasPermission(item.permission));

        window.location.replace(fallback ? fallback.page : 'perfil-mobile.html');
        return false;
    }

    inject() {
        if (!this.enforceMobilePagePermission()) {
            return;
        }

        if (window.MenuGeral && typeof window.MenuGeral.renderMobileBottomNav === 'function') {
            window.MenuGeral.renderMobileBottomNav();
            return;
        }
    }
}

function initMobileNavbar() {
    const mobileNav = new MobileNavBar();
    mobileNav.inject();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileNavbar);
} else {
    initMobileNavbar();
}
