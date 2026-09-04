const LOGIN_ROBOT_IMAGE = 'login-robot.svg';

document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.robot-rain');
    if (!container || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const mobile = window.matchMedia('(max-width: 768px)').matches;
    const total = mobile ? 14 : 28;
    const minSize = mobile ? 24 : 32;
    const maxSize = mobile ? 50 : 72;

    for (let index = 0; index < total; index += 1) {
        const robot = document.createElement('img');
        robot.className = 'robot-drop';
        robot.src = LOGIN_ROBOT_IMAGE;
        robot.alt = '';
        robot.style.left = `${Math.random() * 100}%`;
        robot.style.setProperty('--robot-size', `${Math.round(minSize + Math.random() * (maxSize - minSize))}px`);
        robot.style.setProperty('--robot-duration', `${(8 + Math.random() * 8).toFixed(2)}s`);
        robot.style.setProperty('--robot-delay', `${(-Math.random() * 16).toFixed(2)}s`);
        robot.style.setProperty('--robot-opacity', (0.18 + Math.random() * 0.3).toFixed(2));
        robot.style.setProperty('--robot-rotation', `${Math.round(-18 + Math.random() * 36)}deg`);
        container.appendChild(robot);
    }
});