const ADMIN_LOGIN_ROBOT_IMAGE = '../login-robot.svg';

document.addEventListener('DOMContentLoaded', () => {
    const rain = document.querySelector('.admin-login-robot-rain');
    if (!rain || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const isMobile = window.innerWidth < 768;
    const robotCount = isMobile ? 14 : 28;
    const minSize = isMobile ? 26 : 34;
    const maxSize = isMobile ? 52 : 76;

    for (let index = 0; index < robotCount; index += 1) {
        const robot = document.createElement('img');
        robot.className = 'admin-login-robot';
        robot.src = ADMIN_LOGIN_ROBOT_IMAGE;
        robot.alt = '';
        robot.style.left = `${Math.random() * 100}%`;
        robot.style.setProperty('--robot-size', `${Math.round(minSize + Math.random() * (maxSize - minSize))}px`);
        robot.style.setProperty('--robot-duration', `${(8 + Math.random() * 8).toFixed(2)}s`);
        robot.style.setProperty('--robot-delay', `${(-Math.random() * 16).toFixed(2)}s`);
        robot.style.setProperty('--robot-opacity', (0.18 + Math.random() * 0.3).toFixed(2));
        robot.style.setProperty('--robot-rotation', `${Math.round(-18 + Math.random() * 36)}deg`);
        rain.appendChild(robot);
    }
});